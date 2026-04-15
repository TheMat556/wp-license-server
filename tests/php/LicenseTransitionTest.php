<?php
/**
 * Tests for the license status transition matrix (M3).
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Domain\LicenseTransitions;
use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\LicenseService;

final class LicenseTransitionTest extends \WP_UnitTestCase {

    private LicenseService $service;
    private LicenseRepository $license_repo;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $encryption         = new EncryptionService();
        $this->license_repo = new LicenseRepository( $wpdb, $encryption );
        $activation_repo    = new ActivationRepository( $wpdb, $encryption );
        $activity_repo      = new ActivityLogRepository( $wpdb );

        $this->service = new LicenseService(
            $wpdb,
            $this->license_repo,
            $activation_repo,
            $activity_repo,
            new \WpLicenseServer\Services\WebhookTargetValidator(),
        );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activity_log" );
        parent::tear_down();
    }

    // -----------------------------------------------------------------------
    // Pure domain unit tests — no DB
    // -----------------------------------------------------------------------

    /** @dataProvider allowed_transitions_provider */
    public function test_allowed_transition_returns_true( string $from, string $to ): void {
        $result = LicenseTransitions::validate( $from, $to );
        $this->assertTrue( $result, "Expected {$from} → {$to} to be allowed" );
    }

    /** @return array<string, array{string, string}> */
    public static function allowed_transitions_provider(): array {
        return [
            'active→expired'    => [ 'active', 'expired' ],
            'active→suspended'  => [ 'active', 'suspended' ],
            'active→cancelled'  => [ 'active', 'cancelled' ],
            'expired→active'    => [ 'expired', 'active' ],
            'expired→cancelled' => [ 'expired', 'cancelled' ],
            'suspended→active'  => [ 'suspended', 'active' ],
            'suspended→cancelled' => [ 'suspended', 'cancelled' ],
            'pending→active'    => [ 'pending', 'active' ],
            'pending→cancelled' => [ 'pending', 'cancelled' ],
            'same status no-op' => [ 'active', 'active' ],
        ];
    }

    /** @dataProvider blocked_transitions_provider */
    public function test_blocked_transition_returns_wp_error( string $from, string $to ): void {
        $result = LicenseTransitions::validate( $from, $to );
        $this->assertWPError( $result, "Expected {$from} → {$to} to be blocked" );
        $this->assertSame( 'invalid_transition', $result->get_error_code() );
        $data = $result->get_error_data();
        $this->assertSame( 422, $data['status'] ?? null );
    }

    /** @return array<string, array{string, string}> */
    public static function blocked_transitions_provider(): array {
        return [
            'cancelled→active'    => [ 'cancelled', 'active' ],
            'cancelled→expired'   => [ 'cancelled', 'expired' ],
            'cancelled→suspended' => [ 'cancelled', 'suspended' ],
            'cancelled→pending'   => [ 'cancelled', 'pending' ],
            'expired→pending'     => [ 'expired', 'pending' ],
            'expired→suspended'   => [ 'expired', 'suspended' ],
            'active→pending'      => [ 'active', 'pending' ],
        ];
    }

    // -----------------------------------------------------------------------
    // Integration tests — through LicenseService::update()
    // -----------------------------------------------------------------------

    public function test_active_to_expired_allowed_via_service(): void {
        $license = $this->license_repo->create( [
            'customer_name'  => 'Transition Test',
            'customer_email' => 'trans@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $result = $this->service->update( $license->id, [ 'status' => 'expired' ] );
        $this->assertNotWPError( $result );
        $this->assertSame( 'expired', $result->status );
    }

    public function test_cancelled_to_active_returns_422_via_service(): void {
        $license = $this->license_repo->create( [
            'customer_name'  => 'Cancelled Test',
            'customer_email' => 'cancelled@example.com',
            'tier'           => 'basic',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        // First cancel it.
        $this->service->update( $license->id, [ 'status' => 'cancelled' ] );

        // Now try to re-activate — must fail.
        $result = $this->service->update( $license->id, [ 'status' => 'active' ] );
        $this->assertWPError( $result );
        $this->assertSame( 'invalid_transition', $result->get_error_code() );
        $data = $result->get_error_data();
        $this->assertSame( 422, $data['status'] ?? null );
    }

    public function test_status_changed_activity_logged_on_transition(): void {
        global $wpdb;

        $license = $this->license_repo->create( [
            'customer_name'  => 'Log Test',
            'customer_email' => 'log@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $this->service->update( $license->id, [ 'status' => 'suspended' ] );

        $log_entry = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT action, details FROM {$wpdb->prefix}license_activity_log
                 WHERE license_id = %d AND action = 'status_changed'
                 ORDER BY id DESC LIMIT 1",
                $license->id
            )
        );

        $this->assertNotNull( $log_entry, 'A status_changed entry must be logged' );
        $details = json_decode( $log_entry->details, true );
        $this->assertSame( 'active', $details['from'] );
        $this->assertSame( 'suspended', $details['to'] );
    }

    public function test_no_status_changed_log_when_status_unchanged(): void {
        global $wpdb;

        $license = $this->license_repo->create( [
            'customer_name'  => 'No Change',
            'customer_email' => 'nochange@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        // Update a non-status field only.
        $this->service->update( $license->id, [ 'customer_name' => 'Updated Name' ] );

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}license_activity_log
                 WHERE license_id = %d AND action = 'status_changed'",
                $license->id
            )
        );

        $this->assertSame( 0, $count, 'No status_changed log when status is not part of the update' );
    }
}
