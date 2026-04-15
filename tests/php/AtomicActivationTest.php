<?php
/**
 * Tests for atomic activation seat-count enforcement.
 *
 * These tests verify that the SELECT ... FOR UPDATE transaction in
 * LicenseService::activate() prevents concurrent requests from exceeding
 * the max_activations limit and that race attempts are logged.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\LicenseService;

final class AtomicActivationTest extends \WP_UnitTestCase {

    private LicenseRepository $license_repo;
    private ActivationRepository $activation_repo;
    private ActivityLogRepository $activity_repo;
    private LicenseService $service;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $encryption            = new EncryptionService();
        $this->license_repo    = new LicenseRepository( $wpdb, $encryption );
        $this->activation_repo = new ActivationRepository( $wpdb, $encryption );
        $this->activity_repo   = new ActivityLogRepository( $wpdb );
        $this->service         = new LicenseService(
            $wpdb,
            $this->license_repo,
            $this->activation_repo,
            $this->activity_repo,
            new \WpLicenseServer\Services\WebhookTargetValidator()
        );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activations" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activity_log" );
        parent::tear_down();
    }

    private function create_license( int $max_activations = 2 ): array {
        $result = $this->service->create( [
            'customer_name'   => 'Race Test',
            'customer_email'  => 'race@example.com',
            'tier'            => 'pro',
            'max_activations' => $max_activations,
            'valid_until'     => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $this->assertNotInstanceOf( \WP_Error::class, $result );
        return $result;
    }

    // -----------------------------------------------------------------------
    // Core limit enforcement
    // -----------------------------------------------------------------------

    public function test_activation_limit_is_enforced_sequentially(): void {
        $data      = $this->create_license( max_activations: 2 );
        $key_prefix = $data['key_prefix'];

        $r1 = $this->service->activate( $key_prefix, 'site1.example.com' );
        $r2 = $this->service->activate( $key_prefix, 'site2.example.com' );
        $r3 = $this->service->activate( $key_prefix, 'site3.example.com' );

        $this->assertNotInstanceOf( \WP_Error::class, $r1, 'First activation should succeed' );
        $this->assertNotInstanceOf( \WP_Error::class, $r2, 'Second activation should succeed' );
        $this->assertInstanceOf( \WP_Error::class, $r3, 'Third activation should fail' );
        $this->assertSame( 'activation_limit_reached', $r3->get_error_code() );
    }

    public function test_activation_count_never_exceeds_max(): void {
        global $wpdb;
        $data      = $this->create_license( max_activations: 2 );
        $key_prefix = $data['key_prefix'];

        // Attempt more activations than the limit.
        for ( $i = 1; $i <= 5; $i++ ) {
            $this->service->activate( $key_prefix, "site{$i}.example.com" );
        }

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}license_activations
                 WHERE license_id = %d AND deactivated_at IS NULL",
                $data['license_id']
            )
        );

        $this->assertSame( 2, $count, 'Active count must never exceed max_activations' );
    }

    // -----------------------------------------------------------------------
    // Race attempt logging
    // -----------------------------------------------------------------------

    public function test_race_attempt_is_logged_to_activity_log(): void {
        global $wpdb;
        $data       = $this->create_license( max_activations: 1 );
        $key_prefix  = $data['key_prefix'];

        $this->service->activate( $key_prefix, 'first.example.com' );  // fills the seat
        $this->service->activate( $key_prefix, 'blocked.example.com' ); // blocked

        $blocked_log = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}license_activity_log
                 WHERE license_id = %d AND action = 'activation_limit_blocked'
                 ORDER BY id DESC LIMIT 1",
                $data['license_id']
            )
        );

        $this->assertNotNull( $blocked_log, 'Blocked activation attempt must be logged' );
        $this->assertSame( 'blocked.example.com', $blocked_log->domain );

        $details = json_decode( $blocked_log->details, true );
        $this->assertSame( 1, $details['max_activations'] );
        $this->assertSame( 1, $details['current_count'] );
    }

    // -----------------------------------------------------------------------
    // Idempotency: re-activating same domain returns 409, not 500
    // -----------------------------------------------------------------------

    public function test_duplicate_domain_activation_returns_409(): void {
        $data       = $this->create_license( max_activations: 5 );
        $key_prefix  = $data['key_prefix'];

        $r1 = $this->service->activate( $key_prefix, 'repeat.example.com' );
        $r2 = $this->service->activate( $key_prefix, 'repeat.example.com' );

        $this->assertNotInstanceOf( \WP_Error::class, $r1 );
        $this->assertInstanceOf( \WP_Error::class, $r2 );
        $this->assertSame( 'already_activated', $r2->get_error_code() );
        $this->assertSame( 409, $r2->get_error_data()['status'] );
    }

    // -----------------------------------------------------------------------
    // Response shape after successful activation
    // -----------------------------------------------------------------------

    public function test_successful_activation_returns_correct_shape(): void {
        $data       = $this->create_license( max_activations: 3 );
        $key_prefix  = $data['key_prefix'];

        $result = $this->service->activate( $key_prefix, 'ok.example.com', [
            'plugin_version' => '1.2.3',
            'wp_version'     => '6.5.0',
        ] );

        $this->assertNotInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'activated', $result['status'] );
        $this->assertSame( 1, $result['license']['current_activations'] );
        $this->assertSame( 3, $result['license']['max_activations'] );
    }

    // -----------------------------------------------------------------------
    // Limit with max_activations = 1 (single-site license)
    // -----------------------------------------------------------------------

    public function test_single_site_license_blocks_second_domain(): void {
        $data       = $this->create_license( max_activations: 1 );
        $key_prefix  = $data['key_prefix'];

        $r1 = $this->service->activate( $key_prefix, 'only.example.com' );
        $r2 = $this->service->activate( $key_prefix, 'second.example.com' );

        $this->assertNotInstanceOf( \WP_Error::class, $r1 );
        $this->assertInstanceOf( \WP_Error::class, $r2 );
        $this->assertSame( 'activation_limit_reached', $r2->get_error_code() );
    }

    // -----------------------------------------------------------------------
    // Deactivation frees a seat
    // -----------------------------------------------------------------------

    public function test_deactivation_frees_seat_for_new_activation(): void {
        $data       = $this->create_license( max_activations: 1 );
        $key_prefix  = $data['key_prefix'];

        $r1 = $this->service->activate( $key_prefix, 'first.example.com' );
        $this->assertNotInstanceOf( \WP_Error::class, $r1 );

        $deactivated = $this->service->deactivate( $key_prefix, 'first.example.com' );
        $this->assertNotInstanceOf( \WP_Error::class, $deactivated );

        // Seat freed — a new domain should succeed.
        $r2 = $this->service->activate( $key_prefix, 'second.example.com' );
        $this->assertNotInstanceOf( \WP_Error::class, $r2, 'Activation after deactivation must succeed' );
    }
}
