<?php
/**
 * Tests for HmacVerifier.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Rest\Middleware\RateLimiter;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\HmacVerifier;
use WpLicenseServer\Services\KeyDerivationService;

final class HmacVerifierTest extends \WP_UnitTestCase {

    private LicenseRepository $repo;
    private HmacVerifier $verifier;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $this->repo     = new LicenseRepository( $wpdb, new EncryptionService() );
        $rate_limiter    = new RateLimiter();
        $encryption      = new EncryptionService();
        $key_derivation  = new KeyDerivationService();
        $this->verifier = new HmacVerifier( $this->repo, $rate_limiter, $encryption, $key_derivation );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        parent::tear_down();
    }

    /**
     * Helper to build a signed WP_REST_Request.
     */
    private function build_signed_request(
        string $license_key,
        string $key_prefix,
        string $domain = 'example.com',
        string $method = 'POST',
        string $route = '/license-server/v1/validate',
        string $body = '{}',
        ?int $timestamp = null,
    ): \WP_REST_Request {
        $timestamp = $timestamp ?? time();

        $canonical   = implode( "\n", [ $method, $route, $domain, (string) $timestamp, $body ] );
        $signing_key = hash_hkdf( 'sha256', $license_key, 32, KeyDerivationService::INFO_API_SIGNING );
        $signature   = hash_hmac( 'sha256', $canonical, $signing_key );

        $request = new \WP_REST_Request( $method, $route );
        $request->set_header( 'X-License-Key-Id', $key_prefix );
        $request->set_header( 'X-License-Domain', $domain );
        $request->set_header( 'X-License-Timestamp', (string) $timestamp );
        $request->set_header( 'X-License-Signature', $signature );
        $request->set_body( $body );

        return $request;
    }

    public function test_valid_signature_passes_verification(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'HMAC Test',
            'customer_email' => 'hmac@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $request = $this->build_signed_request(
            $license->license_key,
            $license->key_prefix,
        );

        $result = $this->verifier->verify( $request );

        $this->assertNotInstanceOf( \WP_Error::class, $result );
        $this->assertSame( $license->id, $result->id );
    }

    public function test_tampered_body_returns_wp_error(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Tamper Test',
            'customer_email' => 'tamper@example.com',
            'tier'           => 'basic',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $request = $this->build_signed_request(
            $license->license_key,
            $license->key_prefix,
            body: '{"original":"data"}'
        );

        // Tamper with body after signing.
        $request->set_body( '{"tampered":"data"}' );

        $result = $this->verifier->verify( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_signature', $result->get_error_code() );
    }

    public function test_expired_timestamp_returns_wp_error(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Expired TS',
            'customer_email' => 'expired.ts@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $request = $this->build_signed_request(
            $license->license_key,
            $license->key_prefix,
            timestamp: time() - 600, // 10 minutes ago
        );

        $result = $this->verifier->verify( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'request_expired', $result->get_error_code() );
    }

    public function test_future_timestamp_beyond_skew_returns_wp_error(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Future TS',
            'customer_email' => 'future.ts@example.com',
            'tier'           => 'agency',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $request = $this->build_signed_request(
            $license->license_key,
            $license->key_prefix,
            timestamp: time() + 600, // 10 minutes in future
        );

        $result = $this->verifier->verify( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'request_expired', $result->get_error_code() );
    }

    public function test_missing_header_returns_wp_error(): void {
        $request = new \WP_REST_Request( 'POST', '/license-server/v1/validate' );
        // Intentionally no headers set.

        $result = $this->verifier->verify( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'missing_auth_headers', $result->get_error_code() );
    }

    public function test_unknown_key_prefix_returns_wp_error(): void {
        $request = new \WP_REST_Request( 'POST', '/license-server/v1/validate' );
        $request->set_header( 'X-License-Key-Id', 'deadbeef' );
        $request->set_header( 'X-License-Domain', 'example.com' );
        $request->set_header( 'X-License-Timestamp', (string) time() );
        $request->set_header( 'X-License-Signature', 'abc123' );

        $result = $this->verifier->verify( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_key', $result->get_error_code() );
    }
}
