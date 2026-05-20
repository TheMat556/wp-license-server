<?php
/**
 * Tests for LockController — lock/unlock, owner immunity, rate limiting.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Rest\Controllers\LockController;
use WpLicenseServer\Rest\Middleware\RateLimiter;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\LicenseService;
use WpLicenseServer\Services\KeyDerivationService;
use WpLicenseServer\Services\WebhookService;

final class LockControllerTest extends \WP_UnitTestCase {

    private LicenseRepository $repo;
    private LicenseService $service;
    private LockController $controller;

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

        $this->controller = new LockController( $this->service, null );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        parent::tear_down();
    }

    public function test_lock_customer_license_succeeds(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Customer',
            'customer_email' => 'cust@example.com',
            'tier'           => 'pro',
            'role'           => 'customer',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $request = new \WP_REST_Request( 'POST', '/license-server/v1/admin/licenses/' . $license->id . '/lock' );
        $request->set_param( 'id', $license->id );
        $result = $this->controller->lock( $request );

        $this->assertNotWPError( $result );
        $this->assertSame( 'locked', $result->get_data()['status'] ?? $result->get_data()['license_status'] ?? '' );
    }

    public function test_lock_owner_license_returns_403(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Owner',
            'customer_email' => 'owner@example.com',
            'tier'           => 'agency',
            'role'           => 'owner',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $request = new \WP_REST_Request( 'POST', '/license-server/v1/admin/licenses/' . $license->id . '/lock' );
        $request->set_param( 'id', $license->id );
        $result = $this->controller->lock( $request );

        $this->assertWPError( $result );
        $this->assertSame( 403, $result->get_error_data()['status'] ?? null );
    }

    public function test_lock_non_existent_license_returns_404(): void {
        $request = new \WP_REST_Request( 'POST', '/license-server/v1/admin/licenses/99999/lock' );
        $request->set_param( 'id', 99999 );
        $result = $this->controller->lock( $request );

        $this->assertWPError( $result );
        $this->assertSame( 404, $result->get_error_data()['status'] ?? null );
    }

    public function test_unlock_locked_license_succeeds(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Customer',
            'customer_email' => 'cust@example.com',
            'tier'           => 'pro',
            'role'           => 'customer',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $lock_req = new \WP_REST_Request( 'POST', '/license-server/v1/admin/licenses/' . $license->id . '/lock' );
        $lock_req->set_param( 'id', $license->id );
        $this->controller->lock( $lock_req );

        $unlock_req = new \WP_REST_Request( 'POST', '/license-server/v1/admin/licenses/' . $license->id . '/unlock' );
        $unlock_req->set_param( 'id', $license->id );
        $result = $this->controller->unlock( $unlock_req );

        $this->assertNotWPError( $result );
    }

    public function test_double_lock_within_rate_limit_returns_429(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Customer',
            'customer_email' => 'cust@example.com',
            'tier'           => 'pro',
            'role'           => 'customer',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $req = new \WP_REST_Request( 'POST', '/license-server/v1/admin/licenses/' . $license->id . '/lock' );
        $req->set_param( 'id', $license->id );

        $this->controller->lock( $req );       // First lock — succeeds.
        $result = $this->controller->lock( $req ); // Re-lock within window — rate limited.

        $this->assertWPError( $result );
        $this->assertSame( 429, $result->get_error_data()['status'] ?? null );
    }
}
