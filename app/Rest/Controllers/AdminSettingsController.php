<?php
/**
 * Admin-only REST controller for server settings.
 *
 * @package WpLicenseServer\Rest\Controllers
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Controllers;

use WpLicenseServer\Rest\Services\LicenseSettingsService;

final class AdminSettingsController {

    public function __construct(
        private readonly LicenseSettingsService $settings_service,
    ) {}

    public function can_manage_options(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * GET /admin/settings
     *
     * Returns server settings. 'storedLicenseKey' is always a masked display
     * value — the full key is never included in the response.
     */
    public function get_settings(): \WP_REST_Response {
        return rest_ensure_response(
            $this->settings_service->get_license_server_settings_payload()
        );
    }
}
