<?php
/**
 * Tests for webhook event_id deduplication (M1).
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\WebhookQueueRepository;
use WpLicenseServer\Services\WebhookService;

final class WebhookDeduplicationTest extends \WP_UnitTestCase {

    private WebhookQueueRepository $queue_repo;
    private WebhookService $webhook_service;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $this->queue_repo      = new WebhookQueueRepository( $wpdb );
        $this->webhook_service = new WebhookService( $wpdb );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_webhook_queue" );
        parent::tear_down();
    }

    /**
     * Calling queue_event() twice with identical args inserts only one row (deterministic path).
     */
    public function test_duplicate_deterministic_event_inserts_once(): void {
        $license_id = 1;
        $event_type = 'license.expired';
        $data       = [ 'domain' => 'example.com' ];

        $first  = $this->webhook_service->queue_event( $license_id, $event_type, $data, true );
        $second = $this->webhook_service->queue_event( $license_id, $event_type, $data, true );

        $this->assertTrue( $first, 'First queue_event() must succeed' );
        $this->assertTrue( $second, 'Second queue_event() must return true (idempotent, not error)' );

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}license_webhook_queue WHERE license_id = %d AND event_type = %s",
                $license_id,
                $event_type
            )
        );

        $this->assertSame( 1, $count, 'Only one row should exist after duplicate queue_event() calls' );
    }

    /**
     * Two different event types produce different event_ids and both are stored.
     */
    public function test_different_event_types_produce_different_rows(): void {
        $license_id = 2;
        $data       = [ 'domain' => 'example.com' ];

        $this->webhook_service->queue_event( $license_id, 'license.expired', $data, true );
        $this->webhook_service->queue_event( $license_id, 'license.suspended', $data, true );

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}license_webhook_queue WHERE license_id = %d",
                $license_id
            )
        );

        $this->assertSame( 2, $count, 'Two different event types must create two separate rows' );
    }

    /**
     * The stored row contains a non-empty event_id field.
     */
    public function test_queued_row_has_non_empty_event_id(): void {
        $license_id = 3;
        $event_type = 'license.key_rotated';
        $data       = [ 'domain' => 'example.com', 'new_key_prefix' => 'abcd1234' ];

        $this->webhook_service->queue_event( $license_id, $event_type, $data, false );

        global $wpdb;
        $event_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT event_id FROM {$wpdb->prefix}license_webhook_queue WHERE license_id = %d AND event_type = %s",
                $license_id,
                $event_type
            )
        );

        $this->assertNotEmpty( $event_id, 'Queued row must have a non-empty event_id' );
    }
}
