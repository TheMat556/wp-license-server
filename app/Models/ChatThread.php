<?php
/**
 * Chat thread entity.
 *
 * @package WpLicenseServer\Models
 */

declare(strict_types=1);

namespace WpLicenseServer\Models;

final class ChatThread {

    public function __construct(
        public readonly int $id,
        public readonly int $license_id,
        public readonly string $domain,
        public readonly string $customer_name,
        public readonly string $customer_email,
        public readonly string $status,
        public readonly ?string $last_message_preview,
        public readonly string $last_message_at,
        public readonly string $created_at,
        public readonly string $updated_at,
    ) {}

    public static function from_row( object $row ): static {
        return new static(
            id: (int) $row->id,
            license_id: (int) $row->license_id,
            domain: (string) $row->domain,
            customer_name: (string) $row->customer_name,
            customer_email: (string) $row->customer_email,
            status: (string) $row->status,
            last_message_preview: is_string( $row->last_message_preview ) ? $row->last_message_preview : null,
            last_message_at: (string) $row->last_message_at,
            created_at: (string) $row->created_at,
            updated_at: (string) $row->updated_at,
        );
    }
}
