<?php
/**
 * REST controller for POST /license-server/v1/activate.
 *
 * @package WpLicenseServer\Rest\Controllers
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Controllers;

use WpLicenseServer\Rest\Dto\ActivateRequest;
use WpLicenseServer\Rest\Middleware\RateLimiter;
use WpLicenseServer\Services\HmacVerifier;
use WpLicenseServer\Services\LicenseService;

final class ActivateController {

    public function __construct(
        private readonly RateLimiter $rate_limiter,
        private readonly HmacVerifier $hmac_verifier,
        private readonly LicenseService $license_service,
    ) {}

    public function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $dto = new ActivateRequest( $request );

        // Rate limit check.
        $rate_check = $this->rate_limiter->check( $request->get_route(), '' !== $dto->keyId ? $dto->keyId : null );
        if ( is_wp_error( $rate_check ) ) {
            return self::error_response( $rate_check );
        }

        // HMAC verification (returns License on success).
        $result = $this->hmac_verifier->verify( $request );
        if ( is_wp_error( $result ) ) {
            return self::error_response( $result );
        }

        $versions = [
            'plugin_version' => $dto->pluginVersion,
            'wp_version'     => $dto->wpVersion,
            'php_version'    => $dto->phpVersion,
        ];

        $activation = $this->license_service->activate( $dto->keyId, $dto->domain, $versions );
        if ( is_wp_error( $activation ) ) {
            return self::error_response( $activation );
        }

        return new \WP_REST_Response( $activation, 200 );
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
