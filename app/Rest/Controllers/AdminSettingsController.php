<?php
/**
 * Admin-only REST controller for server settings.
 *
 * @package WpLicenseServer\Rest\Controllers
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Controllers;

use WpLicenseServer\ErrorCodes;
use WpLicenseServer\Rest\Middleware\RateLimiter;
use WpLicenseServer\Rest\Services\LicenseSettingsService;
use WpLicenseServer\Services\EncryptionService;

final class AdminSettingsController {

    /** Per-user/IP cap: 5 reads per RateLimiter window (60s). */
    private const ENC_KEY_MAX_PER_WINDOW = 5;

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

    /**
     * POST /admin/settings
     *
     * Saves server settings such as development mode.
     * Body: { "development_mode": true|false }
     */
    public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $params = $request->get_json_params();

        if ( isset( $params['development_mode'] ) ) {
            $this->settings_service->save_development_mode( (bool) $params['development_mode'] );
        }

        return rest_ensure_response(
            $this->settings_service->get_license_server_settings_payload()
        );
    }

    /**
     * GET /admin/encryption-key
     *
     * Returns the plaintext encryption key. Only accessible by manage_options
     * users with a valid nonce. Response is never cached.
     *
     * SECURITY: This endpoint exists to allow the admin UI to copy the key to
     * wp-config.php without embedding it in the DOM. The key is returned as
     * application/json with no-store caching headers.
     */
    public function get_encryption_key(): \WP_REST_Response|\WP_Error {
        $nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ?? '' ) );

        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_Error(
                ErrorCodes::MISSING_AUTH_HEADERS->value,
                'Invalid or missing nonce.',
                array( 'status' => 403 )
            );
        }

        // Rate-limit plaintext key reads. Tight cap because this is the most
        // sensitive endpoint in the plugin — an XSS in any other admin context
        // could otherwise drain the key in a single page load.
        $rate_limiter = new RateLimiter();
        $user_id      = (string) get_current_user_id();
        $rate_check   = $rate_limiter->check(
            '/admin/encryption-key',
            'user_' . $user_id,
            self::ENC_KEY_MAX_PER_WINDOW,
            self::ENC_KEY_MAX_PER_WINDOW
        );
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        $key = (string) get_option( \WpLicenseServer\Services\EncryptionService::OPTION_KEY, '' );

        if ( '' === $key && defined( 'WPLICENSE_ENCRYPTION_KEY' ) ) {
            $key = WPLICENSE_ENCRYPTION_KEY;
        }

        // Audit-trail: every successful read is fired as an action so site operators can
        // wire it to their logging/SIEM without us imposing a storage layer here.
        do_action(
            'wplicense_encryption_key_read',
            (int) $user_id,
            EncryptionService::get_key_source(),
            time()
        );

        $response = rest_ensure_response( array( 'key' => $key ) );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'Expires', '0' );

        return $response;
    }
}
