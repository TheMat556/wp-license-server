<?php
/**
 * Port for activity-log persistence — Hexagonal Architecture (D in SOLID).
 *
 * @package WpLicenseServer\Contracts
 */

declare(strict_types=1);

namespace WpLicenseServer\Contracts;

interface ActivityLogRepositoryInterface {

    /**
     * @param array{
     *     license_id: int,
     *     action: string,
     *     domain?: string,
     *     actor?: string,
     *     details?: array|null,
     * } $data
     */
    public function insert( array $data ): bool;
}
