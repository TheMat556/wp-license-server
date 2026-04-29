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

final class WebhookReceiverController {

    private const MAX_CLOCK_SKEW = 300; // 5 minutes
    private const EVENT_DEDUP_TTL = 86400; // 24 hours

    public function __construct(
        private readonly LicenseRepositoryInterface $license_repo,
        private readonly KeyDerivationService $key_derivation,
    ) {}

    public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $raw_body = $request->get_body();
        $body     = json_decode( $raw_body, true );

        if ( ! is_array( $body ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_WEBHOOK_PAYLOAD->value,
                'Invalid JSON body.',
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
                'Missing required webhook fields.',
                array( 'status' => 400 )
            );
        }

        // Replay protection layer 1: timestamp window (clients must send clock-skewed timestamps).
        $ts = (int) $timestamp;
        if ( abs( time() - $ts ) > self::MAX_CLOCK_SKEW ) {
            return new \WP_Error(
                ErrorCodes::WEBHOOK_TIMESTAMP_EXPIRED->value,
                'Webhook timestamp is outside the acceptable window.',
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
                'License not found.',
                array( 'status' => 404 )
            );
        }

        $signing_key = $this->key_derivation->derive_webhook_key( $license->license_key );

        // Prefer body_hash (v1.4+) for deterministic verification; fall back to JSON re-encode.
        if ( '' !== $body_hash ) {
            $expected_hash = hash( 'sha256', $raw_body );
            if ( ! hash_equals( $expected_hash, $body_hash ) ) {
                sodium_memzero( $signing_key );
                return new \WP_Error(
                    ErrorCodes::INVALID_SIGNATURE->value,
                    'Webhook body hash verification failed.',
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
            // Legacy verification via re-encoded JSON data (pre-v1.4).
            $data_json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

            if ( ! is_string( $data_json ) ) {
                sodium_memzero( $signing_key );
                return new \WP_Error(
                    ErrorCodes::INVALID_WEBHOOK_PAYLOAD->value,
                    'Webhook data could not be encoded.',
                    array( 'status' => 500 )
                );
            }

            $expected = hash_hmac(
                'sha256',
                implode(
                    "\n",
                    array( $event, $event_id, $key_prefix, $timestamp, $data_json )
                ),
                $signing_key
            );
        }

        sodium_memzero( $signing_key );

        if ( ! hash_equals( $expected, $signature ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_SIGNATURE->value,
                'Signature verification failed.',
                array( 'status' => 403 )
            );
        }

        // Mark event_id as processed BEFORE firing listeners. If a listener throws or
        // a downstream action stalls, a retry from the dispatcher must not re-execute
        // the side effects.
        set_transient( $dedup_key, 1, self::EVENT_DEDUP_TTL );

        do_action( 'wplicense_webhook_received', $event, $event_id, $key_prefix, $data, $request );

        return new \WP_REST_Response( array( 'received' => true ), 200 );
    }
}
