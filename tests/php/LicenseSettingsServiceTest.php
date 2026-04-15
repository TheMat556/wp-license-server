<?php
/**
 * Tests asserting that the /admin/settings REST endpoint never leaks the
 * full (unmasked) license key.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Plugin;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Rest\Services\LicenseSettingsService;
use WpLicenseServer\Services\LicenseService;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;

final class LicenseSettingsServiceTest extends \WP_UnitTestCase {

    private LicenseRepository $license_repo;
    private LicenseSettingsService $settings_service;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        Schema::create_tables();

        $encryption             = new \WpLicenseServer\Services\EncryptionService();
        $this->license_repo     = new LicenseRepository( $wpdb, $encryption );
        $this->settings_service = new LicenseSettingsService( $this->license_repo );

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

    // -------------------------------------------------------------------------
    // Unit-level: LicenseSettingsService
    // -------------------------------------------------------------------------

    public function test_payload_returns_empty_masked_key_when_no_owner(): void {
        $payload = $this->settings_service->get_license_server_settings_payload();

        $this->assertSame( '', $payload['storedLicenseKey'] );
        $this->assertFalse( $payload['hasOwnerLicense'] );
    }

    public function test_payload_masks_key_and_never_returns_full_key(): void {
        global $wpdb;

        $encryption      = new \WpLicenseServer\Services\EncryptionService();
        $activity_repo   = new ActivityLogRepository( $wpdb );
        $activation_repo = new ActivationRepository( $wpdb, $encryption );
        $license_service = new LicenseService( $wpdb, $this->license_repo, $activation_repo, $activity_repo, new \WpLicenseServer\Services\WebhookTargetValidator() );

        $owner = $license_service->create(
            [
                'customer_name'  => 'Owner',
                'customer_email' => 'owner@example.com',
                'role'           => 'owner',
                'tier'           => 'pro',
                'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
            ]
        );

        $this->assertNotInstanceOf( \WP_Error::class, $owner );

        $full_key = $this->license_repo->get_decrypted_license_key();
        $this->assertNotNull( $full_key );
        $this->assertSame( 64, strlen( $full_key ) );

        $payload = $this->settings_service->get_license_server_settings_payload();
        $masked  = $payload['storedLicenseKey'];

        // Masked value must NOT equal the full key.
        $this->assertNotSame( $full_key, $masked );

        // Must contain asterisks.
        $this->assertStringContainsString( '*', $masked );

        // Must preserve the first 4 and last 4 characters.
        $this->assertSame( substr( $full_key, 0, 4 ), substr( $masked, 0, 4 ) );
        $this->assertSame( substr( $full_key, -4 ), substr( $masked, -4 ) );

        // Middle portion must be all asterisks.
        $middle_len = strlen( $full_key ) - 8;
        $this->assertSame( str_repeat( '*', $middle_len ), substr( $masked, 4, $middle_len ) );

        // Total masked length must equal the original key length.
        $this->assertSame( strlen( $full_key ), strlen( $masked ) );

        $this->assertTrue( $payload['hasOwnerLicense'] );
    }

    // -------------------------------------------------------------------------
    // Integration-level: REST endpoint never exposes the full key
    // -------------------------------------------------------------------------

    public function test_rest_settings_endpoint_requires_admin(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $request  = new \WP_REST_Request( 'GET', '/license-server/v1/admin/settings' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 403, $response->get_status() );
    }

    public function test_rest_settings_response_never_contains_full_key(): void {
        global $wpdb;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $encryption2     = new \WpLicenseServer\Services\EncryptionService();
        $activity_repo   = new ActivityLogRepository( $wpdb );
        $activation_repo = new ActivationRepository( $wpdb, $encryption2 );
        $license_service = new LicenseService( $wpdb, $this->license_repo, $activation_repo, $activity_repo, new \WpLicenseServer\Services\WebhookTargetValidator() );

        $owner = $license_service->create(
            [
                'customer_name'  => 'Owner',
                'customer_email' => 'owner@example.com',
                'role'           => 'owner',
                'tier'           => 'pro',
                'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
            ]
        );

        $this->assertNotInstanceOf( \WP_Error::class, $owner );

        $full_key = $this->license_repo->get_decrypted_license_key();
        $this->assertNotNull( $full_key );

        $request  = new \WP_REST_Request( 'GET', '/license-server/v1/admin/settings' );
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );

        // The storedLicenseKey in the response must NOT be the full key.
        $this->assertNotSame( $full_key, $data['storedLicenseKey'] );

        // Serialise the entire response body and verify the full key is absent.
        $body = wp_json_encode( $data );
        $this->assertIsString( $body );
        $this->assertStringNotContainsString( $full_key, $body );

        // Confirm it is the correct masked value.
        $this->assertStringContainsString( '*', $data['storedLicenseKey'] );
        $this->assertTrue( $data['hasOwnerLicense'] );
    }

    public function test_rest_settings_returns_empty_masked_key_when_no_owner(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $request  = new \WP_REST_Request( 'GET', '/license-server/v1/admin/settings' );
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( '', $data['storedLicenseKey'] );
        $this->assertFalse( $data['hasOwnerLicense'] );
    }

    public function test_get_decrypted_license_key_returns_null_when_no_owner(): void {
        $this->assertNull( $this->license_repo->get_decrypted_license_key() );
    }
}
