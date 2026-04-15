<?php
/**
 * REST controller for POST /license-server/v1/chat/poll.
 *
 * @package WpLicenseServer\Rest\Controllers
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Controllers;

use WpLicenseServer\Rest\Middleware\FeatureGate;
use WpLicenseServer\Rest\Middleware\RateLimiter;
use WpLicenseServer\Services\ChatService;
use WpLicenseServer\Services\HmacVerifier;
use WpLicenseServer\Services\TierConfig;

final class ChatPollController {

    public function __construct(
        private readonly RateLimiter $rate_limiter,
        private readonly HmacVerifier $hmac_verifier,
        private readonly ChatService $chat_service,
        private readonly FeatureGate $feature_gate,
    ) {}

    public function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $key_id     = sanitize_text_field( $request->get_header( 'X-License-Key-Id' ) ?? '' );
        $rate_check = $this->rate_limiter->check( $request->get_route(), '' !== $key_id ? $key_id : null, 60, 120 );

        if ( is_wp_error( $rate_check ) ) {
            return self::error_response( $rate_check );
        }

        $license = $this->hmac_verifier->verify( $request );
        if ( is_wp_error( $license ) ) {
            return self::error_response( $license );
        }

        $check = $this->feature_gate->require_feature( TierConfig::ROUTE_FEATURES['chat/poll'], $license );
        if ( is_wp_error( $check ) ) {
            return self::error_response( $check );
        }

        $body   = $request->get_json_params();
        $domain = sanitize_text_field( $request->get_header( 'X-License-Domain' ) ?? '' );
        $result = $this->chat_service->poll(
            $license,
            $domain,
            absint( $body['selectedThreadId'] ?? 0 ),
            absint( $body['afterMessageId'] ?? 0 )
        );

        if ( is_wp_error( $result ) ) {
            return self::error_response( $result );
        }

        return new \WP_REST_Response( $result, 200 );
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
