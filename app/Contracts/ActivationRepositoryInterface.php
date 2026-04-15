<?php
/**
 * Port for activation persistence — Hexagonal Architecture (D in SOLID).
 *
 * @package WpLicenseServer\Contracts
 */

declare(strict_types=1);

namespace WpLicenseServer\Contracts;

use WpLicenseServer\Models\Activation;

interface ActivationRepositoryInterface {

    public function create( array $data ): Activation;

    public function find_active( int $license_id, string $domain ): ?Activation;

    /** @return Activation[] */
    public function get_all_active( int $license_id ): array;

    public function count_active( int $license_id ): int;

    public function count_by_license_and_domain( int $license_id, string $domain ): int;

    public function deactivate( int $license_id, string $domain ): bool;

    public function update_heartbeat( int $activation_id, array $versions ): bool;

    public function rotate_webhook_secret( int $activation_id, string $new_plaintext_secret ): bool;

    public function ensure_webhook_secret( int $activation_id ): ?string;
}
