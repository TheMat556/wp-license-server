<?php
/**
 * Admin REST controller for locking/unlocking licenses.
 *
 * POST /license-server/v1/admin/licenses/{id}/lock
 * POST /license-server/v1/admin/licenses/{id}/unlock
 *
 * Authentication: WordPress REST core handles nonce verification via
 * X-WP-Nonce header. permission_callback ensures manage_options capability.
 *
 * @package WpLicenseServer\Rest\Controllers
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Controllers;

use WP_REST_Request;
use WpLicenseServer\ErrorCodes;
use WpLicenseServer\Services\LicenseService;

final class LockController {

    public function __construct(
        private readonly LicenseService $license_service,
    ) {}

    public function can_manage_options(): bool {
        return current_user_can( 'manage_options' );
    }

    public function lock( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $id = absint( $request->get_param( 'id' ) );
        if ( $id <= 0 ) {
            return new \WP_Error(
                ErrorCodes::INVALID_LICENSE_ID->value,
                __( 'A valid license ID is required.', 'wp-license-server' ),
                [ 'status' => 400 ]
            );
        }

        $result = $this->license_service->lock( $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( [ 'item' => $this->map_license( $result ) ] );
    }

    public function unlock( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $id = absint( $request->get_param( 'id' ) );
        if ( $id <= 0 ) {
            return new \WP_Error(
                ErrorCodes::INVALID_LICENSE_ID->value,
                __( 'A valid license ID is required.', 'wp-license-server' ),
                [ 'status' => 400 ]
            );
        }

        $result = $this->license_service->unlock( $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // If pre_lock_status was corrupt, the warning is already in the
        // activity log. The admin can review it via the existing /activity endpoint.
        return rest_ensure_response( [ 'item' => $this->map_license( $result ) ] );
    }

    /**
     * Map a License model to the standard admin API response shape.
     *
     * @param object $license License model.
     * @return array<string, mixed>
     */
    private function map_license( object $license ): array {
        return [
            'id'            => $license->id,
            'keyPrefix'     => $license->key_prefix,
            'customerName'  => $license->customer_name,
            'customerEmail' => $license->customer_email,
            'role'          => $license->role,
            'tier'          => $license->tier,
            'status'        => $license->status,
            'validUntil'    => $license->valid_until,
            'notes'         => $license->notes,
        ];
    }
}
