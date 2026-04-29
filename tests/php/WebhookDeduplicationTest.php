<?php
declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Models\License;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Repositories\WebhookQueueRepository;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\WebhookService;

final class WebhookDeduplicationTest extends \WP_UnitTestCase {

    private WebhookQueueRepository $queue_repo;
    private WebhookService $webhook_service;
    private LicenseRepository $license_repo;
    private ActivationRepository $activation_repo;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $encryption              = new EncryptionService();
        $this->license_repo      = new LicenseRepository( $wpdb, $encryption );
        $this->activation_repo   = new ActivationRepository( $wpdb, $encryption );
        $this->queue_repo        = new WebhookQueueRepository( $wpdb );
        $this->webhook_service   = new WebhookService( $this->license_repo, $this->activation_repo, $this->queue_repo );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_webhook_queue" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activations" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        parent::tear_down();
    }

    private function create_license_with_activation(): License {
        $license = $this->license_repo->create( [
            'customer_name'  => 'Dedup Test',
            'customer_email' => 'dedup@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $this->activation_repo->create( [
            'license_id' => $license->id,
            'domain'     => 'example.com',
        ] );

        return $license;
    }

    public function test_duplicate_deterministic_event_inserts_once(): void {
        $license   = $this->create_license_with_activation();
        $event_type = 'license.expired';
        $data       = [ 'domain' => 'example.com' ];

        $this->webhook_service->queue_event( $license->id, $event_type, $data, true );
        $this->webhook_service->queue_event( $license->id, $event_type, $data, true );

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}license_webhook_queue WHERE license_id = %d AND event = %s",
                $license->id,
                $event_type
            )
        );

        $this->assertSame( 1, $count, 'Only one row should exist after duplicate queue_event() calls' );
    }

    public function test_different_event_types_produce_different_rows(): void {
        $license = $this->create_license_with_activation();
        $data    = [ 'domain' => 'example.com' ];

        $this->webhook_service->queue_event( $license->id, 'license.expired', $data, true );
        $this->webhook_service->queue_event( $license->id, 'license.suspended', $data, true );

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}license_webhook_queue WHERE license_id = %d",
                $license->id
            )
        );

        $this->assertSame( 2, $count, 'Two different event types must create two separate rows' );
    }

    public function test_queued_row_has_non_empty_event_id(): void {
        $license   = $this->create_license_with_activation();
        $event_type = 'license.key_rotated';
        $data       = [ 'domain' => 'example.com', 'new_key_prefix' => 'abcd1234' ];

        $this->webhook_service->queue_event( $license->id, $event_type, $data, false );

        global $wpdb;
        $event_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT event_id FROM {$wpdb->prefix}license_webhook_queue WHERE license_id = %d AND event = %s",
                $license->id,
                $event_type
            )
        );

        $this->assertNotEmpty( $event_id, 'Queued row must have a non-empty event_id' );
    }
}
