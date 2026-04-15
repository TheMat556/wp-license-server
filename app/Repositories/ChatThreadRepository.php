<?php
/**
 * Repository for chat thread persistence.
 *
 * @package WpLicenseServer\Repositories
 */

declare(strict_types=1);

namespace WpLicenseServer\Repositories;

use WpLicenseServer\Models\ChatThread;
use WpLicenseServer\Models\License;

final class ChatThreadRepository {

    private string $table;

    public function __construct( private readonly \wpdb $wpdb ) {
        $this->table = $wpdb->prefix . 'license_chat_threads';
    }

    public function find_by_id( int $id ): ?ChatThread {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d",
                $id
            )
        );

        return $row ? ChatThread::from_row( $row ) : null;
    }

    public function find_by_license_domain( int $license_id, string $domain ): ?ChatThread {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE license_id = %d AND domain = %s",
                $license_id,
                sanitize_text_field( $domain )
            )
        );

        return $row ? ChatThread::from_row( $row ) : null;
    }

    public function ensure_customer_thread( License $license, string $domain ): ChatThread {
        $existing = $this->find_by_license_domain( $license->id, $domain );

        if ( $existing instanceof ChatThread ) {
            return $existing;
        }

        $this->wpdb->insert(
            $this->table,
            [
                'license_id'           => $license->id,
                'domain'               => sanitize_text_field( $domain ),
                'customer_name'        => sanitize_text_field( $license->customer_name ),
                'customer_email'       => sanitize_email( $license->customer_email ),
                'status'               => 'open',
                'last_message_preview' => null,
                'last_message_at'      => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $this->find_by_id( (int) $this->wpdb->insert_id );
    }

    /**
     * @return ChatThread[]
     */
    public function find_all(): array {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY last_message_at DESC, id DESC"
        );

        return array_map( [ ChatThread::class, 'from_row' ], $rows ?: [] );
    }

    public function touch_after_message( int $thread_id, string $message ): bool {
        $preview = sanitize_text_field( $message );
        $preview = function_exists( 'mb_substr' ) ? mb_substr( $preview, 0, 160 ) : substr( $preview, 0, 160 );

        $result = $this->wpdb->update(
            $this->table,
            [
                'last_message_preview' => $preview,
                'last_message_at'      => current_time( 'mysql', true ),
            ],
            [ 'id' => $thread_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return false !== $result;
    }
}
