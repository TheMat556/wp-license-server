<?php
/**
 * Chat message entity.
 *
 * @package WpLicenseServer\Models
 */

declare(strict_types=1);

namespace WpLicenseServer\Models;

final class ChatMessage {

    public function __construct(
        public readonly int $id,
        public readonly int $thread_id,
        public readonly string $author_role,
        public readonly string $author_name,
        public readonly string $message,
        public readonly string $created_at,
    ) {}

    public static function from_row( object $row ): static {
        return new static(
            id: (int) $row->id,
            thread_id: (int) $row->thread_id,
            author_role: (string) $row->author_role,
            author_name: (string) $row->author_name,
            message: (string) $row->message,
            created_at: (string) $row->created_at,
        );
    }
}
