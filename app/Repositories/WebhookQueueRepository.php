<?php
/**
 * Repository for the license_webhook_queue table.
 *
 * @package WpLicenseServer\Repositories
 */

declare(strict_types=1);

namespace WpLicenseServer\Repositories;

use WpLicenseServer\Contracts\WebhookQueueRepositoryInterface;
use WpLicenseServer\Models\WebhookJob;

final class WebhookQueueRepository implements WebhookQueueRepositoryInterface {

    private string $table;

    public function __construct( private readonly \wpdb $wpdb ) {
        $this->table = $wpdb->prefix . 'license_webhook_queue';
    }

    /**
     * Queues a new webhook job.
     *
     * Returns true if the job was inserted or already exists (idempotent).
     * A duplicate event_id means the event was already queued — not an error.
     *
     * @param array{
     *     license_id: int,
     *     domain: string,
     *     webhook_secret: string,
     *     event: string,
     *     event_id: string,
     *     payload: array<string, mixed>
     * } $data
     */
    public function insert( array $data ): bool {
        $payload = wp_json_encode( $data['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        if ( ! is_string( $payload ) ) {
            return false;
        }

        $result = $this->wpdb->insert(
            $this->table,
            array(
                'license_id'     => absint( $data['license_id'] ),
                'domain'         => sanitize_text_field( $data['domain'] ),
                'webhook_secret' => sanitize_text_field( $data['webhook_secret'] ),
                'event'          => sanitize_text_field( $data['event'] ),
                'event_id'       => sanitize_text_field( $data['event_id'] ),
                'payload'        => $payload,
                'status'         => 'pending',
                'attempts'       => 0,
                'last_attempt'   => null,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
        );

        // A duplicate event_id means this event is already queued — treat as success.
        if ( false === $result && str_contains( (string) $this->wpdb->last_error, 'Duplicate entry' ) ) {
            return true;
        }

        return false !== $result;
    }

    /**
     * @return WebhookJob[]
     */
    public function get_pending_batch( int $limit = 20, int $last_seen_id = 0 ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE status = %s AND id > %d
                ORDER BY id ASC
                LIMIT %d",
                'pending',
                max( 0, $last_seen_id ),
                max( 1, $limit )
            )
        );

        return array_map( array( WebhookJob::class, 'from_row' ), $rows ?: array() );
    }

    public function mark_sent( int $id ): bool {
        $result = $this->wpdb->update(
            $this->table,
            array(
                'status'       => 'sent',
                'last_attempt' => current_time( 'mysql', true ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        return false !== $result;
    }

    public function record_failure( int $id, int $attempts, bool $mark_failed ): bool {
        $result = $this->wpdb->update(
            $this->table,
            array(
                'status'       => $mark_failed ? 'failed' : 'pending',
                'attempts'     => $attempts,
                'last_attempt' => current_time( 'mysql', true ),
            ),
            array( 'id' => $id ),
            array( '%s', '%d', '%s' ),
            array( '%d' )
        );

        return false !== $result;
    }

    public function find_by_id( int $id ): ?WebhookJob {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d",
                $id
            )
        );

        return $row ? WebhookJob::from_row( $row ) : null;
    }

    public function find_by_license_id( int $license_id ): ?WebhookJob {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE license_id = %d ORDER BY id DESC LIMIT 1",
                $license_id
            )
        );

        return $row ? WebhookJob::from_row( $row ) : null;
    }
}
