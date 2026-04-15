<?php
/**
 * Repository for license_activity_log table.
 *
 * @package WpLicenseServer\Repositories
 */

declare(strict_types=1);

namespace WpLicenseServer\Repositories;

use WpLicenseServer\Contracts\ActivityLogRepositoryInterface;
use WpLicenseServer\Models\ActivityLog;

final class ActivityLogRepository implements ActivityLogRepositoryInterface {

    private string $table;

    public function __construct( private readonly \wpdb $wpdb ) {
        $this->table = $wpdb->prefix . 'license_activity_log';
    }

    /**
     * Insert a log entry. Automatically JSON-encodes details if an array.
     *
     * @param array{
     *     license_id: int,
     *     action: string,
     *     domain?: string,
     *     actor?: string,
     *     details?: array<string, mixed>|null,
     * } $data
     */
    public function insert( array $data ): bool {
        $details = null;
        if ( isset( $data['details'] ) && is_array( $data['details'] ) ) {
            $details = wp_json_encode( $data['details'] );
        }

        $result = $this->wpdb->insert(
            $this->table,
            [
                'license_id' => absint( $data['license_id'] ),
                'action'     => sanitize_text_field( $data['action'] ),
                'domain'     => isset( $data['domain'] ) ? sanitize_text_field( $data['domain'] ) : null,
                'actor'      => isset( $data['actor'] ) ? sanitize_text_field( $data['actor'] ) : null,
                'details'    => $details,
            ],
            [ '%d', '%s', '%s', '%s', '%s' ]
        );

        return $result !== false;
    }

    /**
     * Get paginated log entries for a license.
     *
     * @return array{ items: ActivityLog[], total: int }
     */
    public function get_by_license( int $license_id, int $page = 1, int $per_page = 20 ): array {
        $offset = max( 0, ( $page - 1 ) * $per_page );

        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE license_id = %d",
                $license_id
            )
        );

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE license_id = %d
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                $license_id,
                $per_page,
                $offset
            )
        );

        return [
            'items' => array_map( [ ActivityLog::class, 'from_row' ], $rows ?: [] ),
            'total' => $total,
        ];
    }
}
