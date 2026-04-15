<?php
/**
 * Service that assembles the settings payload for the admin REST response.
 *
 * SECURITY CONTRACT: The full license key MUST NEVER appear in any value
 * returned by this service. get_license_server_settings_payload() returns
 * only a masked display value (e.g. "a1b2****ef12"). Strip logic lives
 * server-side so it cannot be bypassed by a JavaScript consumer.
 *
 * @package WpLicenseServer\Rest\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Services;

use WpLicenseServer\Repositories\LicenseRepository;

final class LicenseSettingsService {

    public function __construct(
        private readonly LicenseRepository $license_repo,
    ) {}

    /**
     * Build the settings payload for the REST response.
     *
     * 'storedLicenseKey' is always a masked display string — never the full key.
     * The mask keeps the first 4 and last 4 characters and replaces everything
     * in between with asterisks. For keys shorter than 8 characters (which the
     * current key-generation code never produces) the entire key is masked.
     *
     * @return array{storedLicenseKey: string, hasOwnerLicense: bool}
     */
    public function get_license_server_settings_payload(): array {
        $full_key = $this->license_repo->get_decrypted_license_key();

        $masked = $full_key
            ? substr( $full_key, 0, 4 )
              . str_repeat( '*', max( 0, strlen( $full_key ) - 8 ) )
              . substr( $full_key, -4 )
            : '';

        return [
            'storedLicenseKey' => $masked,
            'hasOwnerLicense'  => $full_key !== null,
        ];
    }
}
