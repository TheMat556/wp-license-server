<?php
/**
 * Tests for NotificationService.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Repositories\WebhookQueueRepository;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\NotificationService;
use WpLicenseServer\Services\WebhookService;

final class NotificationServiceTest extends \WP_UnitTestCase {

    private LicenseRepository $license_repo;
    private ActivationRepository $activation_repo;
    private WebhookQueueRepository $queue_repo;
    private WebhookService $webhook_service;
    private NotificationService $service;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        Schema::create_tables();

        $encryption              = new EncryptionService();
        $this->license_repo      = new LicenseRepository( $wpdb, $encryption );
        $this->activation_repo   = new ActivationRepository( $wpdb, $encryption );
        $this->queue_repo        = new WebhookQueueRepository( $wpdb );
        $this->webhook_service   = new WebhookService( $this->license_repo, $this->activation_repo, $this->queue_repo );
        $this->service           = new NotificationService( $this->webhook_service );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_webhook_queue" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activations" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        parent::tear_down();
    }

    public function test_on_new_activation_fires_action_hook(): void {
        $hook_fired = false;
        add_action( 'wplicense_new_activation', function () use ( &$hook_fired ): void {
            $hook_fired = true;
        } );

        $license    = $this->create_license_with_activation();
        $activation = $this->activation_repo->find_active( $license->id, 'test.example' );

        $this->service->on_new_activation(
            $license->id,
            'owner@example.com',
            'test.example',
            '127.0.0.1',
            (string) ( $activation->id ?? '' )
        );

        $this->assertTrue( $hook_fired, 'wplicense_new_activation action hook should have fired.' );
    }

    public function test_on_new_activation_queues_new_activation_webhook(): void {
        $license    = $this->create_license_with_activation();
        $activation = $this->activation_repo->find_active( $license->id, 'test.example' );

        $this->service->on_new_activation(
            $license->id,
            'owner@example.com',
            'test.example',
            '127.0.0.1',
            (string) ( $activation->id ?? '' )
        );

        $jobs = $this->queue_repo->get_pending_batch( 10 );

        $this->assertNotEmpty( $jobs, 'A webhook job should have been queued.' );
        $this->assertSame( 'new_activation', $jobs[0]->event );
    }

    public function test_on_new_activation_webhook_is_deterministic(): void {
        $license    = $this->create_license_with_activation();
        $activation = $this->activation_repo->find_active( $license->id, 'test.example' );
        $act_id     = (string) ( $activation->id ?? '' );

        $this->service->on_new_activation( $license->id, 'owner@example.com', 'test.example', '127.0.0.1', $act_id );
        $this->service->on_new_activation( $license->id, 'owner@example.com', 'test.example', '127.0.0.1', $act_id );

        $jobs = $this->queue_repo->get_pending_batch( 10 );

        // Deterministic event_id means the second insert is silently discarded.
        $this->assertCount( 1, $jobs, 'Duplicate deterministic calls should produce only one queue row.' );
    }

    public function test_on_new_activation_payload_includes_ip_address(): void {
        $captured_payload = null;
        add_action( 'wplicense_new_activation', function ( array $payload ) use ( &$captured_payload ): void {
            $captured_payload = $payload;
        } );

        $license    = $this->create_license_with_activation();
        $activation = $this->activation_repo->find_active( $license->id, 'test.example' );

        $this->service->on_new_activation(
            $license->id,
            'owner@example.com',
            'test.example',
            '192.168.1.50',
            (string) ( $activation->id ?? '' )
        );

        $this->assertIsArray( $captured_payload );
        $this->assertArrayHasKey( 'ip_address', $captured_payload );
        $this->assertSame( '192.168.1.50', $captured_payload['ip_address'] );
    }

    public function test_on_new_activation_skips_email_when_owner_email_is_invalid(): void {
        $emails_sent = 0;
        add_filter( 'wp_mail', function ( array $atts ) use ( &$emails_sent ): array {
            ++$emails_sent;
            return $atts;
        } );

        $license    = $this->create_license_with_activation();
        $activation = $this->activation_repo->find_active( $license->id, 'test.example' );

        $this->service->on_new_activation(
            $license->id,
            '', // empty email — should skip wp_mail
            'test.example',
            '127.0.0.1',
            (string) ( $activation->id ?? '' )
        );

        $this->assertSame( 0, $emails_sent, 'No email should be sent when owner_email is empty.' );
    }

    private function create_license_with_activation(): \WpLicenseServer\Models\License {
        global $wpdb;
        $license = $this->license_repo->create( [
            'customer_name'  => 'Notification Test',
            'customer_email' => 'owner@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $this->activation_repo->create( [
            'license_id' => $license->id,
            'domain'     => 'test.example',
        ] );

        return $license;
    }
}
