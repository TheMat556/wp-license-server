<?php
/**
 * Tests for LicenseService lock/unlock — owner immunity, rate limiting, FOR UPDATE.
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

final class LockServiceLockTest extends \WP_UnitTestCase {

    private LicenseRepository $repo;
    private LicenseService $service;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $encryption     = new EncryptionService();
        $key_derivation = new KeyDerivationService();
        $this->repo     = new LicenseRepository( $wpdb, $encryption );

        $webhook_service = $this->createMock( WebhookService::class );
        $this->service   = new LicenseService(
            $this->repo,
            $this->createMock( \WpLicenseServer\Repositories\ActivationRepository::class ),
            $this->createMock( \WpLicenseServer\Repositories\ActivityLogRepository::class ),
            $encryption,
            $key_derivation,
            $webhook_service,
        );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        parent::tear_down();
    }

    public function test_lock_customer_license_returns_license(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Customer',
            'customer_email' => 'cust@example.com',
            'tier'           => 'pro',
            'role'           => 'customer',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $result = $this->service->lock( $license->id );
        $this->assertNotWPError( $result, 'Lock should succeed for customer license' );
    }

    public function test_lock_owner_license_returns_403(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Owner',
            'customer_email' => 'owner@example.com',
            'tier'           => 'agency',
            'role'           => 'owner',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $result = $this->service->lock( $license->id );

        $this->assertWPError( $result );
        $this->assertSame( 403, $result->get_error_data()['status'] ?? null );
    }

    public function test_lock_non_existent_license_returns_404(): void {
        $result = $this->service->lock( 99999 );

        $this->assertWPError( $result );
        $this->assertSame( 404, $result->get_error_data()['status'] ?? null );
    }

    public function test_lock_already_locked_license_returns_429_on_requeue(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Customer',
            'customer_email' => 'cust@example.com',
            'tier'           => 'pro',
            'role'           => 'customer',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $this->service->lock( $license->id );
        $result = $this->service->lock( $license->id );

        $this->assertWPError( $result );
        $this->assertSame( 429, $result->get_error_data()['status'] ?? null,
            'Re-lock within rate limit window should return 429' );
    }

    public function test_unlock_customer_license_succeeds(): void {
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
    }

    /**
     * Updating an owner license role to customer returns 403.
     */
    public function test_update_owner_to_customer_returns_403(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Owner',
            'customer_email' => 'owner@example.com',
            'tier'           => 'agency',
            'role'           => 'owner',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $result = $this->service->update( $license->id, [
            'role' => 'customer',
        ] );

        $this->assertWPError( $result );
        $this->assertSame( 'owner_role_immutable', $result->get_error_code() );
        $this->assertSame( 403, $result->get_error_data()['status'] ?? null );
    }

    /**
     * Updating a non-owner license role should succeed.
     */
    public function test_update_customer_role_succeeds(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Customer',
            'customer_email' => 'cust@example.com',
            'tier'           => 'pro',
            'role'           => 'customer',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $result = $this->service->update( $license->id, [
            'tier' => 'basic',
        ] );

        $this->assertNotWPError( $result, 'Updating tier of customer should succeed' );
    }
}
