<?php
/**
 * REST controller for POST /license-server/v1/deactivate.
 *
 * @package WpLicenseServer\Rest\Controllers
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Controllers;

use WpLicenseServer\Rest\Middleware\RateLimiter;
use WpLicenseServer\Services\HmacVerifier;
use WpLicenseServer\Services\LicenseService;

final class DeactivateController {

    public function __construct(
        private readonly RateLimiter $rate_limiter,
        private readonly HmacVerifier $hmac_verifier,
        private readonly LicenseService $license_service,
    ) {}

    public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $key_id = sanitize_text_field( $request->get_header( 'X-License-Key-Id' ) ?? '' );

        // Rate limit check.
        $rate_check = $this->rate_limiter->check( $request->get_route(), $key_id ?: null );
        if ( is_wp_error( $rate_check ) ) {
            return self::error_response( $rate_check );
        }

        // HMAC verification.
        $result = $this->hmac_verifier->verify( $request );
        if ( is_wp_error( $result ) ) {
            return self::error_response( $result );
        }

        $domain = sanitize_text_field( $request->get_header( 'X-License-Domain' ) ?? '' );

        $deactivation = $this->license_service->deactivate( $key_id, $domain );
        if ( is_wp_error( $deactivation ) ) {
            return self::error_response( $deactivation );
        }

        return new \WP_REST_Response( [ 'status' => 'deactivated' ], 200 );
    }

    private static function error_response( \WP_Error $error ): \WP_REST_Response {
        $data   = $error->get_error_data();
        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

        return new \WP_REST_Response(
            [
                'code'    => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'data'    => $data,
            ],
            $status
        );
    }
}
