<?php
/**
 * Tests for admin license REST routes.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Domain\LicenseStateMachine;
use WpLicenseServer\Plugin;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Services\LicenseService;

final class AdminLicensesControllerTest extends \WP_UnitTestCase {

    private LicenseRepository $license_repo;
    private ActivationRepository $activation_repo;
    private LicenseService $license_service;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        Schema::create_tables();

        // Mock DNS resolution to avoid real network calls.
        $mock_dns = $this->createMock( \WpLicenseServer\Services\DnsResolver::class );
        $mock_dns->method( 'resolve_ips' )->willReturn( [ '1.2.3.4' ] );
        $mock_dns->method( 'is_public_ip' )->willReturnCallback(
            static function ( string $ip ): bool {
                return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
            }
        );
        $target_validator = new \WpLicenseServer\Services\WebhookTargetValidator( $mock_dns );

        $encryption            = new \WpLicenseServer\Services\EncryptionService();
        $this->license_repo    = new LicenseRepository( $wpdb, $encryption );
        $this->activation_repo = new ActivationRepository( $wpdb, $encryption );
        $activity_repo         = new ActivityLogRepository( $wpdb );
        $this->license_service = new LicenseService( $wpdb, $this->license_repo, $this->activation_repo, $activity_repo, $target_validator, null, new LicenseStateMachine() );

        ( new Plugin() )->init();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wpdb;

        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activations" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activity_log" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_webhook_queue" );

        parent::tear_down();
    }

    public function test_admin_can_create_and_list_licenses_via_rest(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $create_request = new \WP_REST_Request( 'POST', '/license-server/v1/admin/licenses' );
        $create_request->set_body_params(
            [
                'customerEmail'   => 'customer@example.com',
                'customerName'    => 'Customer',
                'role'            => 'owner',
                'tier'            => 'pro',
                'validUntil'      => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
                'paymentInterval' => 'yearly',
                'notes'           => 'Created in test',
            ]
        );

        $create_response = rest_get_server()->dispatch( $create_request );
        $create_data     = $create_response->get_data();

        $this->assertSame( 200, $create_response->get_status() );
        $this->assertSame( 'pro', $create_data['item']['tier'] );
        $this->assertSame( 'owner', $create_data['item']['role'] );
        $this->assertNotEmpty( $create_data['licenseKey'] );
        $this->assertSame( 1, count( $this->license_repo->find_all() ) );

        $list_request  = new \WP_REST_Request( 'GET', '/license-server/v1/admin/licenses' );
        $list_response = rest_get_server()->dispatch( $list_request );
        $list_data     = $list_response->get_data();

        $this->assertSame( 200, $list_response->get_status() );
        $this->assertCount( 1, $list_data['items'] );
        $this->assertSame( 'customer@example.com', $list_data['items'][0]['customerEmail'] );
        $this->assertSame( 'owner', $list_data['items'][0]['role'] );
        $this->assertSame( $create_data['item']['id'], $list_data['ownerLicenseId'] );
        $this->assertArrayNotHasKey( 'licenseKey', $list_data['items'][0] );
    }

    public function test_non_admin_cannot_create_license_via_rest(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $request = new \WP_REST_Request( 'POST', '/license-server/v1/admin/licenses' );
        $request->set_body_params(
            [
                'customerEmail' => 'customer@example.com',
                'tier'          => 'pro',
                'validUntil'    => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 403, $response->get_status() );
    }

    public function test_admin_can_deactivate_and_delete_via_rest(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $license = $this->license_service->create(
            [
                'customer_email' => 'customer@example.com',
                'tier'           => 'pro',
                'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
            ]
        );

        $this->assertNotInstanceOf( \WP_Error::class, $license );

        $this->license_service->activate( $license->key_prefix, 'site-one.example' );
        $this->license_service->activate( $license->key_prefix, 'site-two.example' );

        $deactivate_request = new \WP_REST_Request( 'POST', '/license-server/v1/admin/licenses/' . $license->id . '/deactivate-all' );
        $deactivate_request->set_body_params( [] );
        $deactivate_response = rest_get_server()->dispatch( $deactivate_request );

        $this->assertSame( 200, $deactivate_response->get_status() );
        $this->assertSame( 2, $deactivate_response->get_data()['deactivated'] );
        $this->assertSame( 0, $this->activation_repo->count_active( $license->id ) );

        $delete_request  = new \WP_REST_Request( 'DELETE', '/license-server/v1/admin/licenses/' . $license->id );
        $delete_response = rest_get_server()->dispatch( $delete_request );

        $this->assertSame( 200, $delete_response->get_status() );
        $this->assertSame( 0, count( $this->license_repo->find_all() ) );
    }

    public function test_admin_can_update_license_role_and_details_via_rest(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $license = $this->license_service->create(
            [
                'customer_name'    => 'Original Customer',
                'customer_email'   => 'customer@example.com',
                'role'             => 'customer',
                'tier'             => 'pro',
                'valid_until'      => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
                'payment_interval' => 'yearly',
                'notes'            => 'Original notes',
            ]
        );

        $this->assertNotInstanceOf( \WP_Error::class, $license );

        $update_request = new \WP_REST_Request( 'PUT', '/license-server/v1/admin/licenses/' . $license->id );
        $update_request->set_body_params(
            [
                'customerName'    => 'Updated Customer',
                'customerEmail'   => 'updated@example.com',
                'role'            => 'owner',
                'tier'            => 'agency',
                'status'          => 'suspended',
                'validUntil'      => gmdate( 'Y-m-d', strtotime( '+2 years' ) ),
                'paymentInterval' => 'monthly',
                'autoRenewal'     => false,
                'maxActivations'  => 12,
                'notes'           => 'Updated notes',
            ]
        );

        $update_response = rest_get_server()->dispatch( $update_request );
        $update_data     = $update_response->get_data();

        $this->assertSame( 200, $update_response->get_status() );
        $this->assertSame( 'owner', $update_data['item']['role'] );
        $this->assertSame( 'agency', $update_data['item']['tier'] );
        $this->assertSame( 'suspended', $update_data['item']['status'] );
        $this->assertSame( 'updated@example.com', $update_data['item']['customerEmail'] );
        $this->assertSame( 12, $update_data['item']['maxActivations'] );
        $this->assertFalse( $update_data['item']['autoRenewal'] );
    }

    public function test_admin_rest_rejects_creating_second_owner(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $owner = $this->license_service->create(
            [
                'customer_email' => 'owner@example.com',
                'role'           => 'owner',
                'tier'           => 'pro',
                'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
            ]
        );

        $this->assertNotInstanceOf( \WP_Error::class, $owner );

        $request = new \WP_REST_Request( 'POST', '/license-server/v1/admin/licenses' );
        $request->set_body_params(
            [
                'customerEmail' => 'second-owner@example.com',
                'role'          => 'owner',
                'tier'          => 'pro',
                'validUntil'    => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'owner_exists', $response->get_data()['code'] );
    }

    public function test_admin_rest_rejects_promoting_second_owner(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $owner = $this->license_service->create(
            [
                'customer_email' => 'owner@example.com',
                'role'           => 'owner',
                'tier'           => 'pro',
                'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
            ]
        );
        $customer = $this->license_service->create(
            [
                'customer_email' => 'customer@example.com',
                'role'           => 'customer',
                'tier'           => 'pro',
                'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
            ]
        );

        $this->assertNotInstanceOf( \WP_Error::class, $owner );
        $this->assertNotInstanceOf( \WP_Error::class, $customer );

        $request = new \WP_REST_Request( 'PUT', '/license-server/v1/admin/licenses/' . $customer->id );
        $request->set_body_params(
            [
                'role' => 'owner',
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'owner_exists', $response->get_data()['code'] );
    }
}
