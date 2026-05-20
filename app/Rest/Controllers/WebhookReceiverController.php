<?php
/**
 * Receives inbound webhook events from license clients.
 *
 * Verifies the HMAC-SHA256 signature on every request before firing the
 * wplicense_webhook_received action. Returns 200 on success so the
 * dispatching server marks the job as sent.
 *
 * @package WpLicenseServer\Rest\Controllers
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Controllers;

use WpLicenseServer\Contracts\LicenseRepositoryInterface;
use WpLicenseServer\ErrorCodes;
use WpLicenseServer\Services\KeyDerivationService;
use WpLicenseServer\Rest\Middleware\RateLimiter;
use function __;

final class WebhookReceiverController {

    private const MAX_CLOCK_SKEW = 300; // 5 minutes
    private const EVENT_DEDUP_TTL = 86400; // 24 hours

    /**
     * Permission callback: applies per-IP rate limiting to the webhook endpoint.
     *
     * Returns true if the request is within rate limits, WP_Error with 429 otherwise.
     * The webhook endpoint is public (no HMAC auth), so rate limiting is the primary
     * defense against CPU/DB DoS and brute-force oracle attacks.
     */
    public function check_rate_limit(): true|\WP_Error {
        return $this->rate_limiter->check( 'webhook' );
    }

    public function __construct(
        private readonly LicenseRepositoryInterface $license_repo,
        private readonly KeyDerivationService $key_derivation,
        private readonly RateLimiter $rate_limiter,
    ) {}

    public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $raw_body = $request->get_body();
        $body     = json_decode( $raw_body, true );

        if ( ! is_array( $body ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_WEBHOOK_PAYLOAD->value,
                __( 'Invalid JSON body.', 'wp-license-server' ),
                array( 'status' => 400 )
            );
        }

        $event      = isset( $body['event'] ) ? (string) $body['event'] : '';
        $event_id   = isset( $body['event_id'] ) ? (string) $body['event_id'] : '';
        $key_prefix = isset( $body['license_key_prefix'] ) ? (string) $body['license_key_prefix'] : '';
        $timestamp  = isset( $body['timestamp'] ) ? (string) $body['timestamp'] : '';
        $data       = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
        $signature  = isset( $body['signature'] ) ? (string) $body['signature'] : '';
        $body_hash  = isset( $body['body_hash'] ) ? (string) $body['body_hash'] : '';

        if ( '' === $event || '' === $event_id || '' === $key_prefix || '' === $timestamp || '' === $signature ) {
            return new \WP_Error(
                ErrorCodes::INVALID_WEBHOOK_PAYLOAD->value,
                __( 'Missing required webhook fields.', 'wp-license-server' ),
                array( 'status' => 400 )
            );
        }

        // Replay protection layer 1: timestamp window (clients must send clock-skewed timestamps).
        $ts = (int) $timestamp;
        if ( abs( time() - $ts ) > self::MAX_CLOCK_SKEW ) {
            return new \WP_Error(
                ErrorCodes::WEBHOOK_TIMESTAMP_EXPIRED->value,
                __( 'Webhook timestamp is outside the acceptable window.', 'wp-license-server' ),
                array( 'status' => 400 )
            );
        }

        // Replay protection layer 2: event_id dedup.
        $dedup_key = 'wplicense_webhook_event_' . hash( 'sha256', $event_id );
        if ( get_transient( $dedup_key ) ) {
            // Already processed — return 200 so the dispatcher does not retry.
            return new \WP_REST_Response( array( 'received' => true, 'dedup' => true ), 200 );
        }

        $license = $this->license_repo->find_by_key_prefix( $key_prefix );

        if ( is_wp_error( $license ) || ! $license ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_FOUND->value,
                __( 'License not found.', 'wp-license-server' ),
                array( 'status' => 404 )
            );
        }

        $signing_key = $this->key_derivation->derive_webhook_key( $license->license_key );

        // Prefer body_hash (v1.4+) for deterministic verification; fall back to raw body.
        if ( '' !== $body_hash ) {
            // Verify body_hash against both the re-encoded data (backward compatible)
            // and the raw request body bytes (defensive against PHP json_encode drift).
            $data_json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            $expected_reencoded = hash( 'sha256', $data_json );
            $expected_raw       = hash( 'sha256', $raw_body );
            $expected_hash      = hash_equals( $expected_reencoded, $body_hash ) ? $expected_reencoded
                : ( hash_equals( $expected_raw, $body_hash ) ? $expected_raw : '' );
            if ( ! hash_equals( $expected_hash, $body_hash ) ) {
                sodium_memzero( $signing_key );
                return new \WP_Error(
                    ErrorCodes::INVALID_SIGNATURE->value,
                    __( 'Webhook body hash verification failed.', 'wp-license-server' ),
                    array( 'status' => 403 )
                );
            }

            $expected = hash_hmac(
                'sha256',
                implode(
                    "\n",
                    array( $event, $event_id, $key_prefix, $timestamp, $body_hash )
                ),
                $signing_key
            );
        } else {
            // Legacy verification via raw body bytes (pre-v1.4).
            // Hashing the raw body avoids PHP json_encode determinism issues
            // across different PHP versions and float/array edge cases.
            $expected = hash_hmac(
                'sha256',
                implode(
                    "\n",
                    array( $event, $event_id, $key_prefix, $timestamp, $raw_body )
                ),
                $signing_key
            );
        }

        sodium_memzero( $signing_key );

        if ( ! hash_equals( $expected, $signature ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_SIGNATURE->value,
                __( 'Signature verification failed.', 'wp-license-server' ),
                array( 'status' => 403 )
            );
        }

        // Mark event_id as processed AFTER firing listeners. If a listener
        // throws, the exception is caught and the dedup transient is NOT set,
        // so the dispatcher retry can re-execute the side effects.
        try {
            do_action( 'wplicense_webhook_received', $event, $event_id, $key_prefix, $data, $request );

            set_transient( $dedup_key, 1, self::EVENT_DEDUP_TTL );

            return new \WP_REST_Response( array( 'received' => true ), 200 );
        } catch ( \Throwable $e ) {
            error_log(
                sprintf(
                    '[WPLicense] Webhook processing failed: %s in %s:%d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                )
            );

            return new \WP_Error(
                'webhook_processing_failed',
                __( 'Webhook processing failed.', 'wp-license-server' ),
                array( 'status' => 500 )
            );
        }
    }
}
