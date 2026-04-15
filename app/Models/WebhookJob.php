<?php
/**
 * Webhook queue job entity.
 *
 * @package WpLicenseServer\Models
 */

declare(strict_types=1);

namespace WpLicenseServer\Models;

final class WebhookJob {

    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly int $license_id,
        public readonly string $domain,
        public readonly string $webhook_secret,
        public readonly string $event,
        public readonly string $event_id,
        public readonly ?array $payload,
        public readonly string $status,
        public readonly int $attempts,
        public readonly ?string $last_attempt,
        public readonly string $created_at,
    ) {}

    public static function from_row( object $row ): static {
        $payload = null;
        if ( is_string( $row->payload ) && '' !== $row->payload ) {
            $decoded = json_decode( $row->payload, true );
            $payload = is_array( $decoded ) ? $decoded : null;
        }

        return new static(
            id:             (int) $row->id,
            license_id:     (int) $row->license_id,
            domain:         (string) $row->domain,
            webhook_secret: (string) $row->webhook_secret,
            event:          (string) $row->event,
            event_id:       (string) ( $row->event_id ?? '' ),
            payload:        $payload,
            status:         (string) $row->status,
            attempts:       (int) $row->attempts,
            last_attempt:   is_string( $row->last_attempt ) ? $row->last_attempt : null,
            created_at:     (string) $row->created_at,
        );
    }
}
