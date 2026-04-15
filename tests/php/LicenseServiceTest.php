<?php
/**
 * Tests for LicenseService.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Domain\LicenseStateMachine;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Services\LicenseService;

final class LicenseServiceTest extends \WP_UnitTestCase {

    private LicenseRepository $license_repo;
    private ActivationRepository $activation_repo;
    private LicenseService $service;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $encryption            = new \WpLicenseServer\Services\EncryptionService();
        $this->license_repo    = new LicenseRepository( $wpdb, $encryption );
        $this->activation_repo = new ActivationRepository( $wpdb, $encryption );
        $activity_repo         = new ActivityLogRepository( $wpdb );
        $this->service         = new LicenseService( $wpdb, $this->license_repo, $this->activation_repo, $activity_repo, new \WpLicenseServer\Services\WebhookTargetValidator(), null, new LicenseStateMachine() );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activations" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activity_log" );
        parent::tear_down();
    }

    private function create_license(
        string $tier = 'pro',
        ?string $valid_until = null,
        string $role = 'customer'
    ): \WpLicenseServer\Models\License {
        return $this->license_repo->create( [
            'customer_name'  => 'Test',
            'customer_email' => 'test@example.com',
            'role'           => $role,
            'tier'           => $tier,
            'valid_until'    => $valid_until ?? gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );
    }

    public function test_activate_returns_activated_status(): void {
        $license = $this->create_license();

        $result = $this->service->activate( $license->key_prefix, 'example.com' );

        $this->assertIsArray( $result );
        $this->assertSame( 'activated', $result['status'] );
        $this->assertSame( 'customer', $result['license']['role'] );
        $this->assertSame( 'pro', $result['license']['tier'] );
        $this->assertNotEmpty( $result['webhook_secret'] );
        $this->assertSame( 32, strlen( $result['webhook_secret'] ) );
    }

    public function test_activate_at_limit_returns_wp_error(): void {
        // Basic tier = 1 activation max.
        $license = $this->create_license( 'basic' );

        // First activation succeeds.
        $result1 = $this->service->activate( $license->key_prefix, 'first.com' );
        $this->assertIsArray( $result1 );

        // Second activation should fail.
        $result2 = $this->service->activate( $license->key_prefix, 'second.com' );
        $this->assertInstanceOf( \WP_Error::class, $result2 );
        $this->assertSame( 'activation_limit_reached', $result2->get_error_code() );
    }

    public function test_activate_expired_license_returns_wp_error(): void {
        // Create license that expired yesterday.
        $license = $this->create_license( 'pro', gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ) );

        $result = $this->service->activate( $license->key_prefix, 'example.com' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'license_not_valid', $result->get_error_code() );
    }

    public function test_activate_allows_local_domain(): void {
        $license = $this->create_license();

        $result = $this->service->activate( $license->key_prefix, 'localhost' );

        $this->assertNotInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'localhost', $result->domain );
    }

    public function test_activate_allows_private_ip_domain(): void {
        $license = $this->create_license();

        $result = $this->service->activate( $license->key_prefix, '192.168.10.150' );

        $this->assertNotInstanceOf( \WP_Error::class, $result );
    }

    public function test_validate_updates_heartbeat_timestamp(): void {
        $license = $this->create_license();
        $this->service->activate( $license->key_prefix, 'heartbeat.com' );

        $versions = [
            'plugin_version' => '1.2.3',
            'wp_version'     => '6.9.0',
            'php_version'    => '8.4.0',
        ];

        $result = $this->service->validate( $license->key_prefix, 'heartbeat.com', $versions );

        $this->assertIsArray( $result );
        $this->assertSame( 'valid', $result['status'] );
        $this->assertSame( 'customer', $result['license']['role'] );
        $this->assertNotEmpty( $result['webhook_secret'] );

        // Check heartbeat was updated.
        $activation = $this->activation_repo->find_active( $license->id, 'heartbeat.com' );
        $this->assertNotNull( $activation->last_heartbeat );
        $this->assertSame( '1.2.3', $activation->plugin_version );
    }

    public function test_validate_expired_within_grace_returns_grace_data(): void {
        // Create license that expired 3 days ago (within 7-day grace).
        $license = $this->create_license( 'pro', gmdate( 'Y-m-d H:i:s', strtotime( '-3 days' ) ) );

        // Activate before it expired (simulate by directly inserting activation).
        $this->activation_repo->create( [
            'license_id' => $license->id,
            'domain'     => 'grace.com',
        ] );

        // Mark license as expired.
        $this->license_repo->update_status( $license->id, 'expired' );

        $result = $this->service->validate( $license->key_prefix, 'grace.com' );

        $this->assertIsArray( $result );
        $this->assertSame( 'grace', $result['status'] );
        $this->assertArrayHasKey( 'grace_days_remaining', $result['license'] );
        $this->assertNotEmpty( $result['webhook_secret'] );
        $this->assertGreaterThanOrEqual( 0, $result['license']['grace_days_remaining'] );
        $this->assertLessThanOrEqual( 7, $result['license']['grace_days_remaining'] );
    }

    public function test_validate_backfills_missing_webhook_secret_for_legacy_activation(): void {
        global $wpdb;

        $license   = $this->create_license();
        $activated = $this->service->activate( $license->key_prefix, 'legacy.example' );

        $this->assertIsArray( $activated );

        $activation = $this->activation_repo->find_active( $license->id, 'legacy.example' );
        $this->assertNotNull( $activation );

        $wpdb->update(
            $wpdb->prefix . 'license_activations',
            array(
                'webhook_secret' => null,
            ),
            array(
                'id' => $activation->id,
            ),
            array( '%s' ),
            array( '%d' )
        );

        $result = $this->service->validate( $license->key_prefix, 'legacy.example' );

        $this->assertIsArray( $result );
        $this->assertNotEmpty( $result['webhook_secret'] );

        $reloaded = $this->activation_repo->find_active( $license->id, 'legacy.example' );
        $this->assertNotNull( $reloaded->webhook_secret );
    }

    public function test_deactivate_sets_deactivated_at(): void {
        $license = $this->create_license();
        $this->service->activate( $license->key_prefix, 'deactivate-me.com' );

        $result = $this->service->deactivate( $license->key_prefix, 'deactivate-me.com' );

        $this->assertTrue( $result );

        // Verify activation is now inactive.
        $activation = $this->activation_repo->find_active( $license->id, 'deactivate-me.com' );
        $this->assertNull( $activation );
    }

    public function test_activate_duplicate_domain_returns_wp_error(): void {
        $license = $this->create_license( 'pro' );

        $result1 = $this->service->activate( $license->key_prefix, 'duplicate.com' );
        $this->assertIsArray( $result1 );

        $result2 = $this->service->activate( $license->key_prefix, 'duplicate.com' );
        $this->assertInstanceOf( \WP_Error::class, $result2 );
        $this->assertSame( 'already_activated', $result2->get_error_code() );
    }

    public function test_update_changes_role_status_and_max_activations(): void {
        $license = $this->create_license( 'pro' );

        $result = $this->service->update(
            $license->id,
            [
                'role'            => 'owner',
                'status'          => 'suspended',
                'tier'            => 'agency',
                'payment_interval' => 'monthly',
                'auto_renewal'    => false,
                'max_activations' => 7,
                'notes'           => 'Updated in test',
            ]
        );

        $this->assertNotInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'owner', $result->role );
        $this->assertSame( 'suspended', $result->status );
        $this->assertSame( 'agency', $result->tier );
        $this->assertSame( 'monthly', $result->payment_interval );
        $this->assertFalse( $result->auto_renewal );
        $this->assertSame( 7, $result->max_activations );
        $this->assertSame( 'Updated in test', $result->notes );
    }

    public function test_update_rejects_invalid_role(): void {
        $license = $this->create_license();

        $result = $this->service->update(
            $license->id,
            [
                'role' => 'invalid-role',
            ]
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_role', $result->get_error_code() );
    }

    public function test_create_rejects_second_owner_license(): void {
        $this->create_license( 'pro', null, 'owner' );

        $result = $this->service->create(
            [
                'customer_email' => 'second-owner@example.com',
                'role'           => 'owner',
                'tier'           => 'pro',
                'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
            ]
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'owner_exists', $result->get_error_code() );
    }

    public function test_update_rejects_promoting_second_owner_license(): void {
        $this->create_license( 'pro', null, 'owner' );
        $license = $this->create_license();

        $result = $this->service->update(
            $license->id,
            [
                'role' => 'owner',
            ]
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'owner_exists', $result->get_error_code() );
    }

    public function test_update_allows_existing_owner_to_keep_owner_role(): void {
        $license = $this->create_license( 'pro', null, 'owner' );

        $result = $this->service->update(
            $license->id,
            [
                'role'  => 'owner',
                'notes' => 'Updated owner',
            ]
        );

        $this->assertNotInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'owner', $result->role );
        $this->assertSame( 'Updated owner', $result->notes );
    }

    public function test_update_rejects_active_license_with_past_expiry(): void {
        $license = $this->create_license();

        $result = $this->service->update(
            $license->id,
            [
                'status'      => 'active',
                'valid_until' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
            ]
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'date_in_past', $result->get_error_code() );
    }

    public function test_create_rejects_unsupported_payment_interval(): void {
        $result = $this->service->create(
            [
                'customer_name'    => 'Test',
                'customer_email'   => 'test@example.com',
                'role'             => 'customer',
                'tier'             => 'pro',
                'valid_until'      => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
                'payment_interval' => 'lifetime',
            ]
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_payment_interval', $result->get_error_code() );
    }

    // -------------------------------------------------------------------------
    // M4 — Webhook secret rotation tests
    // -------------------------------------------------------------------------

    public function test_validate_returns_new_secret_different_from_previous(): void {
        $license = $this->create_license();
        $this->service->activate( $license->key_prefix, 'rotate.example' );

        // First heartbeat — captures the secret returned.
        $result1 = $this->service->validate( $license->key_prefix, 'rotate.example' );
        $this->assertIsArray( $result1 );
        $secret_after_first_heartbeat = $result1['webhook_secret'];

        // Second heartbeat — secret must differ.
        $result2 = $this->service->validate( $license->key_prefix, 'rotate.example' );
        $this->assertIsArray( $result2 );
        $secret_after_second_heartbeat = $result2['webhook_secret'];

        $this->assertNotSame( $secret_after_first_heartbeat, $secret_after_second_heartbeat );
    }

    public function test_old_webhook_secret_accepted_within_transition_window(): void {
        global $wpdb;

        $license = $this->create_license();
        $activated = $this->service->activate( $license->key_prefix, 'window.example' );
        $this->assertIsArray( $activated );
        $original_secret = $activated['webhook_secret'];

        // Heartbeat — rotates the secret.
        $result = $this->service->validate( $license->key_prefix, 'window.example' );
        $this->assertIsArray( $result );
        $new_secret = $result['webhook_secret'];
        $this->assertNotSame( $original_secret, $new_secret );

        // Load the activation; previous_webhook_secret should be the original.
        $activation = $this->activation_repo->find_active( $license->id, 'window.example' );
        $this->assertNotNull( $activation );
        $this->assertSame( $new_secret, $activation->webhook_secret );
        $this->assertSame( $original_secret, $activation->previous_webhook_secret );

        // Within the 5-minute window, the old secret must still be accepted.
        $within_window = time() + 1; // 1 second after rotation
        $this->assertTrue( $activation->is_webhook_secret_valid( $original_secret, $within_window ) );
        $this->assertTrue( $activation->is_webhook_secret_valid( $new_secret, $within_window ) );
    }

    public function test_old_webhook_secret_rejected_after_transition_window(): void {
        $license = $this->create_license();
        $this->service->activate( $license->key_prefix, 'expired-window.example' );

        // Rotate via heartbeat.
        $result = $this->service->validate( $license->key_prefix, 'expired-window.example' );
        $this->assertIsArray( $result );

        $activation = $this->activation_repo->find_active( $license->id, 'expired-window.example' );
        $this->assertNotNull( $activation );

        $old_secret = $activation->previous_webhook_secret;
        $this->assertNotNull( $old_secret );

        // 5 minutes + 1 second after rotation — old secret must be rejected.
        $after_window = time() + \WpLicenseServer\Models\Activation::WEBHOOK_SECRET_TRANSITION_SECONDS + 1;
        $this->assertFalse( $activation->is_webhook_secret_valid( $old_secret, $after_window ) );

        // New secret must still be accepted at any time.
        $this->assertTrue( $activation->is_webhook_secret_valid( $activation->webhook_secret, $after_window ) );
    }

    public function test_stored_webhook_secret_is_encrypted_in_db(): void {
        global $wpdb;

        $license = $this->create_license();
        $activated = $this->service->activate( $license->key_prefix, 'encrypted.example' );
        $this->assertIsArray( $activated );
        $plaintext_secret = $activated['webhook_secret'];

        // Read the raw value from the DB — it must NOT equal the plaintext.
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT webhook_secret FROM {$wpdb->prefix}license_activations
                 WHERE license_id = %d AND domain = %s",
                $license->id,
                'encrypted.example'
            )
        );

        $this->assertNotNull( $raw );
        $this->assertNotSame( $plaintext_secret, $raw );
    }

    public function test_stored_previous_webhook_secret_is_encrypted_in_db(): void {
        global $wpdb;

        $license = $this->create_license();
        $activated = $this->service->activate( $license->key_prefix, 'encrypted-prev.example' );
        $this->assertIsArray( $activated );

        // Trigger rotation.
        $this->service->validate( $license->key_prefix, 'encrypted-prev.example' );

        $activation = $this->activation_repo->find_active( $license->id, 'encrypted-prev.example' );
        $this->assertNotNull( $activation );
        $plaintext_prev = $activation->previous_webhook_secret;
        $this->assertNotNull( $plaintext_prev );

        // Raw DB value for previous_webhook_secret must not equal plaintext.
        $raw_prev = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT previous_webhook_secret FROM {$wpdb->prefix}license_activations
                 WHERE license_id = %d AND domain = %s",
                $license->id,
                'encrypted-prev.example'
            )
        );

        $this->assertNotNull( $raw_prev );
        $this->assertNotSame( $plaintext_prev, $raw_prev );
    }

    public function test_webhook_secret_version_increments_on_each_heartbeat(): void {
        $license = $this->create_license();
        $this->service->activate( $license->key_prefix, 'version.example' );

        $activation_v1 = $this->activation_repo->find_active( $license->id, 'version.example' );
        $this->assertSame( 1, $activation_v1->webhook_secret_version );

        $this->service->validate( $license->key_prefix, 'version.example' );
        $activation_v2 = $this->activation_repo->find_active( $license->id, 'version.example' );
        $this->assertSame( 2, $activation_v2->webhook_secret_version );

        $this->service->validate( $license->key_prefix, 'version.example' );
        $activation_v3 = $this->activation_repo->find_active( $license->id, 'version.example' );
        $this->assertSame( 3, $activation_v3->webhook_secret_version );
    }

    public function test_create_normalizes_date_only_expiry_to_end_of_day(): void {
        $result = $this->service->create(
            [
                'customer_name'    => 'Test',
                'customer_email'   => 'test@example.com',
                'role'             => 'customer',
                'tier'             => 'pro',
                'valid_until'      => gmdate( 'Y-m-d', strtotime( '+1 day' ) ),
                'payment_interval' => 'yearly',
            ]
        );

        $this->assertNotInstanceOf( \WP_Error::class, $result );
        $this->assertStringEndsWith( '23:59:59', $result->valid_until );
    }
}
