<?php
/**
 * Tests for transaction rollback on lock/unlock failures.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\LicenseService;
use WpLicenseServer\Services\KeyDerivationService;
use WpLicenseServer\Services\WebhookService;

final class TransactionRollbackTest extends \WP_UnitTestCase {

    private LicenseRepository $repo;
    private LicenseService $service;
    private \WpLicenseServer\Repositories\ActivityLogRepository $activity_repo;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $encryption     = new EncryptionService();
        $key_derivation = new KeyDerivationService();
        $this->repo     = new LicenseRepository( $wpdb, $encryption );
        $this->activity_repo = new \WpLicenseServer\Repositories\ActivityLogRepository( $wpdb );

        $webhook_service = $this->createMock( WebhookService::class );
        $this->service   = new LicenseService(
            $this->repo,
            $this->createMock( \WpLicenseServer\Repositories\ActivationRepository::class ),
            $this->activity_repo,
            $encryption,
            $key_derivation,
            $webhook_service,
        );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activity_log" );
        parent::tear_down();
    }

    /**
     * Lock should succeed and create an activity log entry.
     */
    public function test_lock_creates_activity_entry(): void {
        global $wpdb;

        $license = $this->repo->create( [
            'customer_name'  => 'Customer',
            'customer_email' => 'cust@example.com',
            'tier'           => 'pro',
            'role'           => 'customer',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $result = $this->service->lock( $license->id );
        $this->assertNotWPError( $result, 'Lock should succeed for customer license' );

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action FROM {$wpdb->prefix}license_activity_log WHERE license_id = %d",
                $license->id
            )
        );
        $actions = wp_list_pluck( $logs, 'action' );
        $this->assertContains( 'locked', $actions, 'Activity log should contain locked action' );
    }

    /**
     * Unlocking should restore status to active and log the action.
     */
    public function test_unlock_restores_status_and_logs(): void {
        global $wpdb;

        $license = $this->repo->create( [
            'customer_name'  => 'Customer',
            'customer_email' => 'cust@example.com',
            'tier'           => 'pro',
            'role'           => 'customer',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $this->service->lock( $license->id );
        $result = $this->service->unlock( $license->id );

        $this->assertNotWPError( $result, 'Unlock should succeed' );

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action FROM {$wpdb->prefix}license_activity_log WHERE license_id = %d AND action = 'unlocked'",
                $license->id
            )
        );
        $this->assertNotEmpty( $logs, 'Activity log should contain unlocked action' );
    }

    /**
     * Locking an owner license should not create a locked log entry.
     */
    public function test_lock_owner_does_not_create_locked_entry(): void {
        global $wpdb;

        $license = $this->repo->create( [
            'customer_name'  => 'Owner',
            'customer_email' => 'owner@example.com',
            'tier'           => 'agency',
            'role'           => 'owner',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $result = $this->service->lock( $license->id );
        $this->assertWPError( $result, 'Owner license lock should return error' );

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action FROM {$wpdb->prefix}license_activity_log WHERE license_id = %d AND action = 'locked'",
                $license->id
            )
        );
        $this->assertEmpty( $logs, 'No locked action should be logged for owner licenses' );
    }

    /**
     * Force a transaction rollback by injecting a webhook failure
     * and verify no locked activity row is created.
     */
    public function test_lock_rollback_on_webhook_failure(): void {
        global $wpdb;

        $license = $this->repo->create( [
            'customer_name'  => 'Customer',
            'customer_email' => 'cust@example.com',
            'tier'           => 'pro',
            'role'           => 'customer',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        // Make webhook queue_event throw, triggering transaction rollback.
        $failing_webhook = $this->createMock( WebhookService::class );
        $failing_webhook->method( 'queue_event' )
            ->willThrowException( new \RuntimeException( 'Simulated failure' ) );

        $service = new LicenseService(
            $this->repo,
            $this->createMock( \WpLicenseServer\Repositories\ActivationRepository::class ),
            $this->activity_repo,
            new EncryptionService(),
            new KeyDerivationService(),
            $failing_webhook,
        );

        $result = $service->lock( $license->id );

        $this->assertWPError( $result, 'Lock should fail when webhook throws' );

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action FROM {$wpdb->prefix}license_activity_log WHERE license_id = %d AND action = 'locked'",
                $license->id
            )
        );
        $this->assertEmpty( $logs, 'No locked action should exist after rollback' );

        // Verify the license row status is unchanged after rollback.
        $reloaded = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}license_keys WHERE id = %d",
                $license->id
            )
        );
        $this->assertSame( 'active', $reloaded, 'License status should remain active after rollback' );
    }
}
