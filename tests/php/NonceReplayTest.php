<?php
/**
 * Tests for per-request nonce replay prevention (M2).
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

final class NonceReplayTest extends \WP_UnitTestCase {

    private LicenseRepository $repo;
    private HmacVerifier $verifier;
    private string $license_key;
    private string $key_prefix;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $encryption     = new EncryptionService();
        $key_derivation = new KeyDerivationService();
        $this->repo     = new LicenseRepository( $wpdb, $encryption );
        $this->verifier = new HmacVerifier( $this->repo, new RateLimiter(), $encryption, $key_derivation );

        $license           = $this->repo->create( [
            'customer_name'  => 'Nonce Test',
            'customer_email' => 'nonce@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );
        $this->license_key = $license->license_key;
        $this->key_prefix  = $license->key_prefix;
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        parent::tear_down();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function build_nonce_request(
        ?string $nonce,
        ?int $timestamp = null,
    ): \WP_REST_Request {
        $timestamp = $timestamp ?? time();
        $method    = 'POST';
        $route     = '/license-server/v1/validate';
        $domain    = 'example.com';
        $body      = '{}';

        if ( null !== $nonce && '' !== $nonce ) {
            $canonical = implode( "\n", [ $method, $nonce, $route, $domain, (string) $timestamp, $body ] );
        } else {
            $canonical = implode( "\n", [ $method, $route, $domain, (string) $timestamp, $body ] );
        }

        $signing_key = hash_hkdf( 'sha256', $this->license_key, 32, KeyDerivationService::INFO_API_SIGNING );
        $signature   = hash_hmac( 'sha256', $canonical, $signing_key );

        $request = new \WP_REST_Request( $method, $route );
        $request->set_header( 'X-License-Key-Id', $this->key_prefix );
        $request->set_header( 'X-License-Domain', $domain );
        $request->set_header( 'X-License-Timestamp', (string) $timestamp );
        $request->set_header( 'X-License-Signature', $signature );
        if ( null !== $nonce ) {
            $request->set_header( 'X-Request-Nonce', $nonce );
        }
        $request->set_body( $body );

        return $request;
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    /**
     * A request with a fresh nonce passes verification.
     */
    public function test_first_request_with_nonce_passes(): void {
        $nonce   = bin2hex( random_bytes( 16 ) );
        $request = $this->build_nonce_request( $nonce );
        $result  = $this->verifier->verify( $request );

        $this->assertNotWPError( $result, 'First request with valid nonce should pass' );
    }

    /**
     * Replaying the same nonce within the window returns 401 replay_detected.
     */
    public function test_replayed_nonce_returns_401(): void {
        $nonce    = bin2hex( random_bytes( 16 ) );
        $request  = $this->build_nonce_request( $nonce );

        $this->verifier->verify( $request ); // first — consumes nonce

        $replay = $this->build_nonce_request( $nonce );
        $result = $this->verifier->verify( $replay );

        $this->assertWPError( $result );
        $this->assertSame( 'replay_detected', $result->get_error_code() );
        $error_data = $result->get_error_data();
        $this->assertSame( 401, $error_data['status'] ?? null );
    }

    /**
     * A new nonce on a slightly later timestamp still passes (not treated as replay).
     */
    public function test_new_nonce_different_request_passes(): void {
        $nonce1  = bin2hex( random_bytes( 16 ) );
        $nonce2  = bin2hex( random_bytes( 16 ) );

        $request1 = $this->build_nonce_request( $nonce1 );
        $this->verifier->verify( $request1 );

        $request2 = $this->build_nonce_request( $nonce2, time() + 1 );
        $result   = $this->verifier->verify( $request2 );

        $this->assertNotWPError( $result, 'Request with new nonce should pass' );
    }

    /**
     * Backward-compat: request without X-Request-Nonce is accepted (migration window).
     */
    public function test_missing_nonce_is_accepted_for_backward_compat(): void {
        $request = $this->build_nonce_request( null );
        $result  = $this->verifier->verify( $request );

        $this->assertNotWPError( $result, 'Request without nonce should pass in backward-compat mode' );
    }

    /**
     * Two clients using the same nonce value but different key prefixes do not collide
     * (nonce transient key includes key_prefix).
     */
    public function test_same_nonce_different_clients_no_collision(): void {
        global $wpdb;
        $encryption = new EncryptionService();
        $license2   = $this->repo->create( [
            'customer_name'  => 'Client 2',
            'customer_email' => 'client2@example.com',
            'tier'           => 'basic',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $nonce = 'shared-nonce-value';

        // Build request for license 1 and verify (stores nonce transient keyed to prefix 1).
        $r1 = $this->build_nonce_request( $nonce );
        $this->verifier->verify( $r1 );

        // Build request for license 2 with the SAME nonce value — different prefix, different transient.
        $key_derivation = new KeyDerivationService();
        $verifier2      = new HmacVerifier( $this->repo, new RateLimiter(), $encryption, $key_derivation );

        $timestamp   = time();
        $method      = 'POST';
        $route       = '/license-server/v1/validate';
        $domain      = 'example.com';
        $body        = '{}';
        $canonical   = implode( "\n", [ $method, $nonce, $route, $domain, (string) $timestamp, $body ] );
        $signing_key = hash_hkdf( 'sha256', $license2->license_key, 32, KeyDerivationService::INFO_API_SIGNING );
        $signature   = hash_hmac( 'sha256', $canonical, $signing_key );

        $r2 = new \WP_REST_Request( $method, $route );
        $r2->set_header( 'X-License-Key-Id', $license2->key_prefix );
        $r2->set_header( 'X-License-Domain', $domain );
        $r2->set_header( 'X-License-Timestamp', (string) $timestamp );
        $r2->set_header( 'X-License-Signature', $signature );
        $r2->set_header( 'X-Request-Nonce', $nonce );
        $r2->set_body( $body );

        $result = $verifier2->verify( $r2 );
        $this->assertNotWPError( $result, 'Same nonce from different client prefix should NOT be treated as replay' );
    }
}
