<?php
/**
 * Repository for chat message persistence.
 *
 * @package WpLicenseServer\Repositories
 */

declare(strict_types=1);

namespace WpLicenseServer\Repositories;

use WpLicenseServer\Models\ChatMessage;

final class ChatMessageRepository {

    private string $table;

    public function __construct( private readonly \wpdb $wpdb ) {
        $this->table = $wpdb->prefix . 'license_chat_messages';
    }

    public function find_by_id( int $id ): ?ChatMessage {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d",
                $id
            )
        );

        return $row ? ChatMessage::from_row( $row ) : null;
    }

    /**
     * @return ChatMessage[]
     */
    public function find_for_thread( int $thread_id, int $after_id = 0, int $limit = 100 ): array {
        $limit = max( 1, min( 200, $limit ) );

        if ( $after_id > 0 ) {
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->table}
                     WHERE thread_id = %d AND id > %d
                     ORDER BY id ASC
                     LIMIT %d",
                    $thread_id,
                    $after_id,
                    $limit
                )
            );

            return array_map( [ ChatMessage::class, 'from_row' ], $rows ?: [] );
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM (
                    SELECT * FROM {$this->table}
                    WHERE thread_id = %d
                    ORDER BY id DESC
                    LIMIT %d
                ) messages
                ORDER BY id ASC",
                $thread_id,
                $limit
            )
        );

        return array_map( [ ChatMessage::class, 'from_row' ], $rows ?: [] );
    }

    public function create( int $thread_id, string $author_role, string $author_name, string $message ): ChatMessage {
        $this->wpdb->insert(
            $this->table,
            [
                'thread_id'   => $thread_id,
                'author_role' => sanitize_key( $author_role ),
                'author_name' => sanitize_text_field( $author_name ),
                'message'     => sanitize_textarea_field( $message ),
            ],
            [ '%d', '%s', '%s', '%s' ]
        );

        return $this->find_by_id( (int) $this->wpdb->insert_id );
    }
}
