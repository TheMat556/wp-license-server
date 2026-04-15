<?php
/**
 * Activity log entry (readonly value object).
 *
 * @package WpLicenseServer\Models
 */

declare(strict_types=1);

namespace WpLicenseServer\Models;

final class ActivityLog {

    public function __construct(
        public readonly int $id,
        public readonly int $license_id,
        public readonly string $action,
        public readonly ?string $domain,
        public readonly ?string $actor,
        /** @var array<string, mixed>|null */
        public readonly ?array $details,
        public readonly string $created_at,
    ) {}

    public static function from_row( object $row ): static {
        $details = null;
        if ( $row->details !== null ) {
            $decoded = json_decode( $row->details, true );
            $details = is_array( $decoded ) ? $decoded : null;
        }

        return new static(
            id:         (int) $row->id,
            license_id: (int) $row->license_id,
            action:     $row->action,
            domain:     $row->domain,
            actor:      $row->actor,
            details:    $details,
            created_at: $row->created_at,
        );
    }
}
