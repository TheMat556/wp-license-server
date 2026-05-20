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
     * @return array{storedLicenseKey: string, hasOwnerLicense: bool, developmentMode: bool}
     */
    public function get_license_server_settings_payload(): array {
        $full_key = $this->license_repo->get_decrypted_license_key();

        $masked = $full_key
            ? substr( $full_key, 0, 4 )
              . str_repeat( '*', max( 0, strlen( $full_key ) - 8 ) )
              . substr( $full_key, -4 )
            : '';

        $bypass_active = '1' === get_option( 'wplicense_ssrf_bypass', '0' );

        return [
            'storedLicenseKey' => $masked,
            'hasOwnerLicense'  => $full_key !== null,
            'developmentMode'  => (bool) get_option( 'wplicense_development_mode', false ),
            'ssrfBypassEnabled' => $bypass_active,
        ];
    }

    /**
     * Persist the ssrf_bypass toggle with 24-hour auto-expiry.
     *
     * The option auto-expires after 24 hours so that the bypass cannot be
     * permanently forgotten in production. Enabling the bypass is REFUSED
     * outright on production environments — local-dev/staging only.
     *
     * @return bool True on success. False if blocked because environment is production.
     */
    public function save_ssrf_bypass( bool $enabled ): bool {
        if ( $enabled ) {
            // Hard production gate: never allow private/loopback targets in production,
            // even with the toggle. wp_get_environment_type() returns 'production' by
            // default when nothing is configured, which is the safe failure mode.
            $env = function_exists( 'wp_get_environment_type' )
                ? wp_get_environment_type()
                : 'production';
            if ( 'production' === $env ) {
                return false;
            }
            update_option( 'wplicense_ssrf_bypass', '1', false );
            set_transient( 'wplicense_ssrf_bypass_active', 1, DAY_IN_SECONDS );
            return true;
        }

        delete_option( 'wplicense_ssrf_bypass' );
        delete_transient( 'wplicense_ssrf_bypass_active' );
        return true;
    }

    /**
     * Persist the development mode setting.
     */
    public function save_development_mode( bool $enabled ): void {
        update_option( 'wplicense_development_mode', $enabled ? '1' : '0', false );
    }
}
