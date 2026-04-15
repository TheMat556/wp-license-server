<?php
/**
 * Port for webhook queue persistence — Hexagonal Architecture (D in SOLID).
 *
 * @package WpLicenseServer\Contracts
 */

declare(strict_types=1);

namespace WpLicenseServer\Contracts;

use WpLicenseServer\Models\WebhookJob;

interface WebhookQueueRepositoryInterface {

    public function insert( array $data ): bool;

    /** @return WebhookJob[] */
    public function get_pending_batch( int $limit = 20, int $last_seen_id = 0 ): array;

    public function mark_sent( int $id ): bool;

    public function record_failure( int $id, int $attempts, bool $mark_failed ): bool;

    public function find_by_id( int $id ): ?WebhookJob;
}
