<?php
/**
 * Port for license persistence — Hexagonal Architecture (D in SOLID).
 *
 * Lists only the methods actually consumed by application services and
 * controllers. Callers depend on this contract; the concrete
 * LicenseRepository is the adapter.
 *
 * @package WpLicenseServer\Contracts
 */

declare(strict_types=1);

namespace WpLicenseServer\Contracts;

use WpLicenseServer\Models\License;

interface LicenseRepositoryInterface {

    /** @return License|\WP_Error|null */
    public function find_by_id( int $id ): License|\WP_Error|null;

    /** @return License|\WP_Error|null */
    public function find_by_key_prefix( string $prefix ): License|\WP_Error|null;

    /** @return License|\WP_Error|null */
    public function find_by_previous_prefix( string $prefix ): License|\WP_Error|null;

    /** @return License|\WP_Error|null */
    public function find_owner( ?int $exclude_id = null ): License|\WP_Error|null;

    /** @return License[] */
    public function find_all( ?string $status = null ): array;

    /** @return License[] */
    public function find_expired_active(): array;

    /**
     * @param array{
     *     customer_name: string,
     *     customer_email: string,
     *     role?: string,
     *     tier: string,
     *     valid_until: string,
     *     payment_interval?: string,
     *     auto_renewal?: bool,
     *     notes?: string,
     * } $data
     */
    public function create( array $data ): License;

    /**
     * @param array<string, mixed> $data
     */
    public function update( int $id, array $data ): bool;

    public function update_status( int $id, string $status ): bool;

    public function delete( int $id ): bool;

    public function store_rotation(
        int $license_id,
        string $old_key,
        string $new_key,
        string $new_prefix,
        int $new_version,
    ): bool;

    public function clear_rotation( int $license_id ): bool;
}
