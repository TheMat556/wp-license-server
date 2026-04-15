<?php
/**
 * Admin REST controller for rotating a license key.
 *
 * POST /license-server/v1/admin/licenses/{id}/rotate-key
 *
 * @package WpLicenseServer\Rest\Controllers
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Controllers;

use WP_REST_Request;
use WpLicenseServer\Services\LicenseService;

final class RotateKeyController {

    public function __construct(
        private readonly LicenseService $license_service,
    ) {}

    public function can_manage_options(): bool {
        return current_user_can( 'manage_options' );
    }

    public function handle( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $license_id = absint( $request->get_param( 'id' ) );

        if ( $license_id <= 0 ) {
            return new \WP_Error(
                'invalid_license_id',
                'A valid license ID is required.',
                [ 'status' => 400 ]
            );
        }

        $result = $this->license_service->rotate_key( $license_id );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }
}
