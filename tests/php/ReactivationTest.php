<?php
/**
 * Tests for deactivate-then-reactivate flows on the same domain.
 *
 * Covers the bug where a soft-deleted activation row (deactivated_at IS NOT NULL)
 * blocked a fresh INSERT due to the UNIQUE KEY (license_id, domain), causing
 * ActivationRepository::create() to return null and a PHP fatal error.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Domain\LicenseStateMachine;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\LicenseService;
use WpLicenseServer\Services\WebhookTargetValidator;

final class ReactivationTest extends \WP_UnitTestCase {

    private LicenseRepository $license_repo;
    private ActivationRepository $activation_repo;
    private LicenseService $service;
    private string $key_prefix;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $encryption            = new EncryptionService();
        $this->license_repo    = new LicenseRepository( $wpdb, $encryption );
        $this->activation_repo = new ActivationRepository( $wpdb, $encryption );
        $activity_repo         = new ActivityLogRepository( $wpdb );
        $this->service         = new LicenseService(
            $wpdb,
            $this->license_repo,
            $this->activation_repo,
            $activity_repo,
            new WebhookTargetValidator(),
            null,
            new LicenseStateMachine()
        );

        $license = $this->service->create( [
            'customer_name'   => 'Reactivation Test',
            'customer_email'  => 'reactivation@example.com',
            'tier'            => 'pro',
            'max_activations' => 3,
            'valid_until'     => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $this->assertNotInstanceOf( \WP_Error::class, $license );
        $this->key_prefix = $license->key_prefix;
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activations" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activity_log" );
        parent::tear_down();
    }

    /**
     * Core regression test: activate → deactivate → reactivate same domain.
     *
     * Before the fix, step 3 caused a PHP fatal:
     *   ActivationRepository::create(): Return value must be of type Activation, null returned
     * because the UNIQUE KEY (license_id, domain) blocked the INSERT on the
     * soft-deleted row.
     */
    public function test_same_domain_can_be_reactivated_after_deactivation(): void {
        $domain = 'reactivate.example.com';

        $r1 = $this->service->activate( $this->key_prefix, $domain );
        $this->assertNotInstanceOf( \WP_Error::class, $r1, 'Initial activation must succeed' );
        $this->assertSame( 'activated', $r1['status'] );

        $d1 = $this->service->deactivate( $this->key_prefix, $domain );
        $this->assertNotInstanceOf( \WP_Error::class, $d1, 'Deactivation must succeed' );

        // Reactivating the same domain after deactivation must NOT trigger a PHP fatal
        // or return already_activated. This was the reported bug.
        $r2 = $this->service->activate( $this->key_prefix, $domain );
        $this->assertNotInstanceOf(
            \WP_Error::class,
            $r2,
            'Reactivation of same domain after deactivation must succeed, not return: '
                . ( $r2 instanceof \WP_Error ? $r2->get_error_code() . ' — ' . $r2->get_error_message() : '' )
        );
        $this->assertSame( 'activated', $r2['status'] );
    }

    /**
     * Multiple cycles: activate → deactivate → reactivate × 3 on the same domain.
     * Ensures repeated re-use of the same domain slot works indefinitely.
     */
    public function test_multiple_reactivation_cycles_on_same_domain(): void {
        $domain = 'cycle.example.com';

        for ( $cycle = 1; $cycle <= 3; $cycle++ ) {
            $activate = $this->service->activate( $this->key_prefix, $domain );
            $this->assertNotInstanceOf(
                \WP_Error::class,
                $activate,
                "Cycle {$cycle}: activate must succeed"
            );

            $deactivate = $this->service->deactivate( $this->key_prefix, $domain );
            $this->assertNotInstanceOf(
                \WP_Error::class,
                $deactivate,
                "Cycle {$cycle}: deactivate must succeed"
            );
        }
    }

    /**
     * Deactivating a domain and reactivating it must not consume an extra seat.
     * Active seat count should be 1 after activate → deactivate → reactivate.
     */
    public function test_reactivation_does_not_consume_extra_seat(): void {
        global $wpdb;
        $domain = 'seat.example.com';

        $this->service->activate( $this->key_prefix, $domain );
        $this->service->deactivate( $this->key_prefix, $domain );
        $this->service->activate( $this->key_prefix, $domain );

        $active_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}license_activations
                  WHERE license_id IN (
                      SELECT id FROM {$wpdb->prefix}license_keys WHERE key_prefix = %s
                  )
                  AND deactivated_at IS NULL",
                $this->key_prefix
            )
        );

        $this->assertSame( 1, $active_count, 'Only 1 active seat after reactivation cycle' );
    }

    /**
     * Activating a live (non-deactivated) domain must still return already_activated.
     * Ensures the fix did not break the duplicate-activation guard.
     */
    public function test_duplicate_live_activation_still_returns_already_activated(): void {
        $domain = 'duplicate.example.com';

        $r1 = $this->service->activate( $this->key_prefix, $domain );
        $this->assertNotInstanceOf( \WP_Error::class, $r1 );

        $r2 = $this->service->activate( $this->key_prefix, $domain );
        $this->assertInstanceOf( \WP_Error::class, $r2 );
        $this->assertSame( 'already_activated', $r2->get_error_code() );
        $this->assertSame( 409, $r2->get_error_data()['status'] );
    }
}
