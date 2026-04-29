<?php
/**
 * Tests for KeyDerivationService.
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

final class KeyDerivationTest extends \WP_UnitTestCase {

	private KeyDerivationService $kd;
	private LicenseRepository $repo;
	private HmacVerifier $verifier;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		Schema::create_tables();

		$this->kd      = new KeyDerivationService();
		$encryption    = new EncryptionService();
		$this->repo    = new LicenseRepository( $wpdb, $encryption );
		$this->verifier = new HmacVerifier( $this->repo, new RateLimiter(), $encryption, $this->kd );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
		parent::tear_down();
	}

	// ── Derivation unit tests ──────────────────────────────────────────────

	public function test_derive_signing_key_is_deterministic(): void {
		$key = bin2hex( random_bytes( 32 ) );

		$this->assertSame(
			$this->kd->derive_signing_key( $key ),
			$this->kd->derive_signing_key( $key ),
			'derive_signing_key() must return the same result for identical input'
		);
	}

	public function test_derive_webhook_key_is_deterministic(): void {
		$key = bin2hex( random_bytes( 32 ) );

		$this->assertSame(
			$this->kd->derive_webhook_key( $key ),
			$this->kd->derive_webhook_key( $key ),
			'derive_webhook_key() must return the same result for identical input'
		);
	}

	public function test_signing_key_differs_from_webhook_key(): void {
		$key = bin2hex( random_bytes( 32 ) );

		$this->assertNotSame(
			$this->kd->derive_signing_key( $key ),
			$this->kd->derive_webhook_key( $key ),
			'Signing key and webhook key must be distinct for the same input (key separation)'
		);
	}

	public function test_derived_keys_differ_from_input(): void {
		$key = bin2hex( random_bytes( 32 ) );

		$this->assertNotSame( $key, $this->kd->derive_signing_key( $key ) );
		$this->assertNotSame( $key, $this->kd->derive_webhook_key( $key ) );
	}

	public function test_different_inputs_produce_different_signing_keys(): void {
		$key_a = bin2hex( random_bytes( 32 ) );
		$key_b = bin2hex( random_bytes( 32 ) );

		$this->assertNotSame(
			$this->kd->derive_signing_key( $key_a ),
			$this->kd->derive_signing_key( $key_b )
		);
	}

	public function test_derived_signing_key_is_32_bytes(): void {
		$derived = $this->kd->derive_signing_key( bin2hex( random_bytes( 32 ) ) );
		$this->assertSame( 32, strlen( $derived ) );
	}

	public function test_derived_webhook_key_is_32_bytes(): void {
		$derived = $this->kd->derive_webhook_key( bin2hex( random_bytes( 32 ) ) );
		$this->assertSame( 32, strlen( $derived ) );
	}

	public function test_info_constants_are_distinct(): void {
		$this->assertNotSame(
			KeyDerivationService::INFO_API_SIGNING,
			KeyDerivationService::INFO_WEBHOOK_SIGNING
		);
	}

	// ── HmacVerifier integration tests ────────────────────────────────────

	/**
	 * A request signed with the RAW license key must be rejected.
	 * This validates that the server enforces key derivation.
	 */
	public function test_request_signed_with_raw_key_is_rejected(): void {
		$license = $this->repo->create( [
			'customer_name'  => 'KD Test',
			'customer_email' => 'kd@example.com',
			'tier'           => 'pro',
			'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
		] );

		$method    = 'POST';
		$route     = '/license-server/v1/validate';
		$domain    = 'example.com';
		$timestamp = (string) time();
		$body      = '{}';
		$nonce     = wp_generate_uuid4();

		$canonical = implode( "\n", [ $method, $nonce, $route, $domain, $timestamp, $body ] );

		// Sign with the RAW key (no derivation) — this must fail.
		$raw_signature = hash_hmac( 'sha256', $canonical, $license->license_key );

		$request = new \WP_REST_Request( $method, $route );
		$request->set_header( 'X-License-Key-Id', $license->key_prefix );
		$request->set_header( 'X-License-Domain', $domain );
		$request->set_header( 'X-License-Timestamp', $timestamp );
		$request->set_header( 'X-License-Signature', $raw_signature );
		$request->set_header( 'X-Request-Nonce', $nonce );
		$request->set_body( $body );

		$result = $this->verifier->verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_signature', $result->get_error_code() );
	}

	/**
	 * A request signed with the DERIVED signing key must be accepted.
	 */
	public function test_request_signed_with_derived_key_is_accepted(): void {
		$license = $this->repo->create( [
			'customer_name'  => 'KD Derived Test',
			'customer_email' => 'kd.derived@example.com',
			'tier'           => 'pro',
			'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
		] );

		$method      = 'POST';
		$route       = '/license-server/v1/validate';
		$domain      = 'example.com';
		$timestamp   = (string) time();
		$body        = '{}';
		$nonce       = wp_generate_uuid4();

		$canonical   = implode( "\n", [ $method, $nonce, $route, $domain, $timestamp, $body ] );
		$signing_key = $this->kd->derive_signing_key( $license->license_key );
		$signature   = hash_hmac( 'sha256', $canonical, $signing_key );

		$request = new \WP_REST_Request( $method, $route );
		$request->set_header( 'X-License-Key-Id', $license->key_prefix );
		$request->set_header( 'X-License-Domain', $domain );
		$request->set_header( 'X-License-Timestamp', $timestamp );
		$request->set_header( 'X-License-Signature', $signature );
		$request->set_header( 'X-Request-Nonce', $nonce );
		$request->set_body( $body );

		$result = $this->verifier->verify( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $license->id, $result->id );
	}

	/**
	 * Signing with the webhook key instead of the signing key must also be rejected.
	 * Tests that the purpose-scope separation actually prevents cross-purpose use.
	 */
	public function test_request_signed_with_webhook_key_is_rejected(): void {
		$license = $this->repo->create( [
			'customer_name'  => 'KD Cross Test',
			'customer_email' => 'kd.cross@example.com',
			'tier'           => 'pro',
			'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
		] );

		$method      = 'POST';
		$route       = '/license-server/v1/validate';
		$domain      = 'example.com';
		$timestamp   = (string) time();
		$body        = '{}';
		$nonce       = wp_generate_uuid4();

		$canonical   = implode( "\n", [ $method, $nonce, $route, $domain, $timestamp, $body ] );
		$webhook_key = $this->kd->derive_webhook_key( $license->license_key );
		$signature   = hash_hmac( 'sha256', $canonical, $webhook_key );

		$request = new \WP_REST_Request( $method, $route );
		$request->set_header( 'X-License-Key-Id', $license->key_prefix );
		$request->set_header( 'X-License-Domain', $domain );
		$request->set_header( 'X-License-Timestamp', $timestamp );
		$request->set_header( 'X-License-Signature', $signature );
		$request->set_header( 'X-Request-Nonce', $nonce );
		$request->set_body( $body );

		$result = $this->verifier->verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_signature', $result->get_error_code() );
	}
}
