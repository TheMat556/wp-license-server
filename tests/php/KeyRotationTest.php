<?php
/**
 * Tests for license key rotation.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Models\License;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Repositories\WebhookQueueRepository;
use WpLicenseServer\Rest\Middleware\RateLimiter;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\HmacVerifier;
use WpLicenseServer\Services\KeyDerivationService;
use WpLicenseServer\Services\LicenseService;
use WpLicenseServer\Services\WebhookService;

final class KeyRotationTest extends \WP_UnitTestCase {

	private LicenseRepository $license_repo;
	private ActivationRepository $activation_repo;
	private ActivityLogRepository $activity_repo;
	private WebhookQueueRepository $webhook_queue_repo;
	private LicenseService $service;
	private HmacVerifier $verifier;
	private EncryptionService $encryption;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		Schema::create_tables();

		$this->encryption        = new EncryptionService();
		$this->license_repo      = new LicenseRepository( $wpdb, $this->encryption );
		$this->activation_repo   = new ActivationRepository( $wpdb, $this->encryption );
		$this->activity_repo     = new ActivityLogRepository( $wpdb );
		$this->webhook_queue_repo = new WebhookQueueRepository( $wpdb );

		$webhook_service = new WebhookService( $this->license_repo, $this->activation_repo, $this->webhook_queue_repo );

		$this->service = new LicenseService(
			$wpdb,
			$this->license_repo,
			$this->activation_repo,
			$this->activity_repo,
			new \WpLicenseServer\Services\WebhookTargetValidator(),
			$webhook_service
		);

		$rate_limiter    = new RateLimiter();
		$this->verifier  = new HmacVerifier( $this->license_repo, $rate_limiter, $this->encryption, new KeyDerivationService() );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activations" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activity_log" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_webhook_queue" );
		parent::tear_down();
	}

	private function create_license(): License {
		return $this->license_repo->create( [
			'customer_name'  => 'Rotation Test',
			'customer_email' => 'rotate@example.com',
			'tier'           => 'pro',
			'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
		] );
	}

	private function build_signed_request(
		string $license_key,
		string $key_prefix,
		string $domain = 'example.com',
	): \WP_REST_Request {
		$method    = 'POST';
		$route     = '/license-server/v1/validate';
		$body      = '{}';
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );

		$canonical   = implode( "\n", [ $method, $nonce, $route, $domain, $timestamp, $body ] );
		$signing_key = hash_hkdf( 'sha256', $license_key, 32, KeyDerivationService::INFO_API_SIGNING );
		$signature   = hash_hmac( 'sha256', $canonical, $signing_key );

		$request = new \WP_REST_Request( $method, $route );
		$request->set_header( 'X-License-Key-Id', $key_prefix );
		$request->set_header( 'X-License-Domain', $domain );
		$request->set_header( 'X-License-Timestamp', $timestamp );
		$request->set_header( 'X-License-Signature', $signature );
		$request->set_header( 'X-Request-Nonce', $nonce );
		$request->set_body( $body );

		return $request;
	}

	public function test_rotate_key_returns_new_key(): void {
		$license = $this->create_license();

		$result = $this->service->rotate_key( $license->id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'new_key', $result );
		$this->assertArrayHasKey( 'new_prefix', $result );
		$this->assertArrayHasKey( 'key_version', $result );
		$this->assertArrayHasKey( 'transition_until', $result );
		$this->assertSame( 2, $result['key_version'] );
		$this->assertSame( 64, strlen( $result['new_key'] ) );
		$this->assertSame( 8, strlen( $result['new_prefix'] ) );
	}

	public function test_hmac_with_new_key_succeeds_immediately(): void {
		$license = $this->create_license();
		$result  = $this->service->rotate_key( $license->id );

		$request  = $this->build_signed_request( $result['new_key'], $result['new_prefix'] );
		$verified = $this->verifier->verify( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $verified );
		$this->assertSame( $license->id, $verified->id );
	}

	public function test_hmac_with_old_key_succeeds_during_transition(): void {
		$license = $this->create_license();
		$old_key = $license->license_key;
		$old_prefix = $license->key_prefix;

		$this->service->rotate_key( $license->id );

		// Sign with the OLD key and OLD prefix.
		$request  = $this->build_signed_request( $old_key, $old_prefix );
		$verified = $this->verifier->verify( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $verified );
		$this->assertSame( $license->id, $verified->id );
	}

	public function test_old_key_is_invalid_after_transition_expires(): void {
		global $wpdb;

		$license = $this->create_license();
		$old_key    = $license->license_key;
		$old_prefix = $license->key_prefix;

		$this->service->rotate_key( $license->id );

		// Simulate 25 hours passing by backdating rotation_at.
		$wpdb->update(
			$wpdb->prefix . 'license_keys',
			[ 'rotation_at' => gmdate( 'Y-m-d H:i:s', time() - 90000 ) ],
			[ 'id' => $license->id ],
			[ '%s' ],
			[ '%d' ]
		);

		// Now clean up the expired rotation.
		$this->service->cleanup_rotation( $license->id );

		// Old key should no longer work.
		$request  = $this->build_signed_request( $old_key, $old_prefix );
		$verified = $this->verifier->verify( $request );

		$this->assertInstanceOf( \WP_Error::class, $verified );
	}

	public function test_webhook_queued_on_rotation(): void {
		$license = $this->create_license();

		// Activate on a domain so the webhook has a target.
		$this->activation_repo->create( [
			'license_id' => $license->id,
			'domain'     => 'example.com',
		] );

		$result = $this->service->rotate_key( $license->id );

		// Check webhook queue.
		global $wpdb;
		$webhook = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}license_webhook_queue WHERE license_id = %d AND event = %s",
			$license->id,
			'license.key_rotated'
		) );

		$this->assertNotNull( $webhook );
		$payload = json_decode( $webhook->payload, true );
		$this->assertSame( $result['new_prefix'], $payload['data']['new_key_prefix'] );
	}

	public function test_rotation_blocked_while_in_progress(): void {
		$license = $this->create_license();

		$result1 = $this->service->rotate_key( $license->id );
		$this->assertIsArray( $result1 );

		$result2 = $this->service->rotate_key( $license->id );
		$this->assertInstanceOf( \WP_Error::class, $result2 );
		$this->assertSame( 'rotation_in_progress', $result2->get_error_code() );
	}

	public function test_rotation_allowed_after_previous_transition_expires(): void {
		global $wpdb;

		$license = $this->create_license();

		$result1 = $this->service->rotate_key( $license->id );
		$this->assertIsArray( $result1 );

		// Backdate and clear the previous rotation.
		$wpdb->update(
			$wpdb->prefix . 'license_keys',
			[ 'rotation_at' => gmdate( 'Y-m-d H:i:s', time() - 90000 ) ],
			[ 'id' => $license->id ],
			[ '%s' ],
			[ '%d' ]
		);
		$this->service->cleanup_rotation( $license->id );

		// Second rotation should now be allowed.
		$result2 = $this->service->rotate_key( $license->id );
		$this->assertIsArray( $result2 );
		$this->assertSame( 3, $result2['key_version'] );
	}

	public function test_rotation_not_found_returns_wp_error(): void {
		$result = $this->service->rotate_key( 99999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'license_not_found', $result->get_error_code() );
	}

	public function test_rotation_is_logged_to_activity(): void {
		global $wpdb;

		$license = $this->create_license();
		$this->service->rotate_key( $license->id );

		$log = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}license_activity_log WHERE license_id = %d AND action = %s ORDER BY id DESC LIMIT 1",
			$license->id,
			'key_rotated'
		) );

		$this->assertNotNull( $log );
		$details = json_decode( $log->details, true );
		$this->assertSame( 2, $details['key_version'] );
	}

	public function test_cleanup_logs_rotation_completed(): void {
		global $wpdb;

		$license = $this->create_license();
		$this->service->rotate_key( $license->id );

		// Backdate rotation.
		$wpdb->update(
			$wpdb->prefix . 'license_keys',
			[ 'rotation_at' => gmdate( 'Y-m-d H:i:s', time() - 90000 ) ],
			[ 'id' => $license->id ],
			[ '%s' ],
			[ '%d' ]
		);

		$this->service->cleanup_rotation( $license->id );

		$log = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}license_activity_log WHERE license_id = %d AND action = %s ORDER BY id DESC LIMIT 1",
			$license->id,
			'rotation_completed'
		) );

		$this->assertNotNull( $log );
	}

	public function test_key_version_increments_with_each_rotation(): void {
		global $wpdb;

		$license = $this->create_license();
		$this->assertSame( 1, $license->key_version );

		$r1 = $this->service->rotate_key( $license->id );
		$this->assertSame( 2, $r1['key_version'] );

		// Clear rotation for next.
		$wpdb->update(
			$wpdb->prefix . 'license_keys',
			[ 'rotation_at' => gmdate( 'Y-m-d H:i:s', time() - 90000 ) ],
			[ 'id' => $license->id ],
			[ '%s' ],
			[ '%d' ]
		);
		$this->service->cleanup_rotation( $license->id );

		$r2 = $this->service->rotate_key( $license->id );
		$this->assertSame( 3, $r2['key_version'] );
	}
}
