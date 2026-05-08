<?php
/**
 * Tests for WebhookReceiverController.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Models\License;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Rest\Controllers\WebhookReceiverController;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\KeyDerivationService;

final class WebhookReceiverControllerTest extends \WP_UnitTestCase {

    private LicenseRepository $repo;
    private WebhookReceiverController $controller;
    private KeyDerivationService $key_derivation;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $this->repo            = new LicenseRepository( $wpdb, new EncryptionService() );
        $this->key_derivation  = new KeyDerivationService();
        $this->controller      = new WebhookReceiverController( $this->repo, $this->key_derivation );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        parent::tear_down();
    }

    private function create_license(): License {
        return $this->repo->create( [
            'customer_name'  => 'Webhook Test',
            'customer_email' => 'webhook@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );
    }

    private function build_signed_webhook_body(
        License $license,
        string $event = 'license.updated',
        string $event_id = '',
        ?int $timestamp = null,
        array $data = [ 'status' => 'active' ],
        bool $use_body_hash = false,
        ?string $override_signature = null,
    ): string {
        $event_id  = $event_id ?: bin2hex( random_bytes( 16 ) );
        $timestamp = $timestamp ?? time();
        $data_json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        $payload = [
            'event'               => $event,
            'event_id'            => $event_id,
            'license_key_prefix'  => $license->key_prefix,
            'timestamp'           => (string) $timestamp,
            'data'                => $data,
        ];

        $signing_key = $this->key_derivation->derive_webhook_key( $license->license_key );

        if ( $use_body_hash ) {
            $body_hash                = hash( 'sha256', (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            $payload['body_hash']     = $body_hash;
            $payload['signature']     = hash_hmac(
                'sha256',
                implode( "\n", [ $event, $event_id, $license->key_prefix, (string) $timestamp, $body_hash ] ),
                $signing_key
            );
        } else {
            $payload['signature'] = hash_hmac(
                'sha256',
                implode( "\n", [ $event, $event_id, $license->key_prefix, (string) $timestamp, $data_json ] ),
                $signing_key
            );
        }

        if ( null !== $override_signature ) {
            $payload['signature'] = $override_signature;
        }

        sodium_memzero( $signing_key );

        return json_encode( $payload ) ?: '';
    }

    private function make_request( string $body ): \WP_REST_Request {
        $request = new \WP_REST_Request( 'POST', '/license-server/v1/webhook' );
        $request->set_body( $body );
        return $request;
    }

    public function test_invalid_json_body_returns_400(): void {
        $request = $this->make_request( '{not valid json' );

        $result = $this->controller->handle( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 400, $result->get_error_data()['status'] ?? 0 );
    }

    public function test_missing_required_fields_returns_400(): void {
        $body = json_encode( [ 'event' => 'license.updated' ] );
        $request = $this->make_request( $body ?: '' );

        $result = $this->controller->handle( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 400, $result->get_error_data()['status'] ?? 0 );
    }

    public function test_expired_timestamp_returns_400(): void {
        $license = $this->create_license();
        $body = $this->build_signed_webhook_body(
            $license,
            timestamp: time() - 600,
        );
        $request = $this->make_request( $body );

        $result = $this->controller->handle( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 400, $result->get_error_data()['status'] ?? 0 );
    }

    public function test_event_id_dedup_returns_200_on_second_call(): void {
        $license  = $this->create_license();
        $event_id = bin2hex( random_bytes( 16 ) );
        $body     = $this->build_signed_webhook_body( $license, event_id: $event_id );
        $request  = $this->make_request( $body );

        $first = $this->controller->handle( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $first );
        $this->assertSame( 200, $first->get_status() );

        $second = $this->controller->handle( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $second );
        $this->assertSame( 200, $second->get_status() );
        $data = $second->get_data();
        $this->assertTrue( $data['dedup'] ?? false );
    }

    public function test_unknown_license_key_prefix_returns_404(): void {
        $body = json_encode( [
            'event'              => 'license.updated',
            'event_id'           => bin2hex( random_bytes( 16 ) ),
            'license_key_prefix' => 'deadbeef',
            'timestamp'          => (string) time(),
            'data'               => [],
            'signature'          => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ] );
        $request = $this->make_request( $body ?: '' );

        $result = $this->controller->handle( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 404, $result->get_error_data()['status'] ?? 0 );
    }

    public function test_valid_request_with_body_hash_returns_200(): void {
        $license = $this->create_license();
        $body    = $this->build_signed_webhook_body( $license, use_body_hash: true );
        $request = $this->make_request( $body );

        $result = $this->controller->handle( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $this->assertSame( 200, $result->get_status() );
        $data = $result->get_data();
        $this->assertTrue( $data['received'] ?? false );
    }

    public function test_invalid_signature_returns_403(): void {
        $license = $this->create_license();
        $body    = $this->build_signed_webhook_body(
            $license,
            override_signature: str_repeat( 'a', 64 ),
        );
        $request = $this->make_request( $body );

        $result = $this->controller->handle( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );
    }

    public function test_legacy_verification_without_body_hash_returns_200(): void {
        $license = $this->create_license();
        $body    = $this->build_signed_webhook_body( $license, use_body_hash: false );
        // Verify no body_hash in payload.
        $decoded = json_decode( $body, true );
        $this->assertArrayNotHasKey( 'body_hash', $decoded );

        $request = $this->make_request( $body );

        $result = $this->controller->handle( $request );

        $this->assertInstanceOf( \WP_REST_Response::class, $result );
        $this->assertSame( 200, $result->get_status() );
        $data = $result->get_data();
        $this->assertTrue( $data['received'] ?? false );
    }
}
