<?php
/**
 * HMAC-SHA256 request verification for license API endpoints.
 *
 * Verifies that incoming REST requests were signed by a party that knows the license key.
 * The full key is NEVER sent in headers — only an 8-char prefix for DB lookup.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

use WpLicenseServer\Contracts\LicenseRepositoryInterface;
use WpLicenseServer\Models\License;
use WpLicenseServer\Rest\Middleware\RateLimiter;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\KeyDerivationService;

final class HmacVerifier {

    private const MAX_CLOCK_SKEW = 300; // 5 minutes
    private const ROTATION_TRANSITION_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly LicenseRepositoryInterface $license_repo,
        private readonly RateLimiter $rate_limiter,
        private readonly EncryptionService $encryption,
        private readonly KeyDerivationService $key_derivation,
    ) {}

    /**
     * Verify the HMAC signature on an incoming request.
     *
     * On success, returns the License object (so callers don't need a second DB lookup).
     * During a key rotation transition window (24h), accepts signatures from both
     * the current and previous key.
     *
     * @return License|\WP_Error License on success, WP_Error on failure.
     */
    public function verify( \WP_REST_Request $request ): License|\WP_Error {
        $key_id    = $request->get_header( 'X-License-Key-Id' );
        $domain    = $request->get_header( 'X-License-Domain' );
        $timestamp = $request->get_header( 'X-License-Timestamp' );
        $signature = $request->get_header( 'X-License-Signature' );
        $nonce     = $request->get_header( 'X-Request-Nonce' )
            ?? $request->get_param( '_nonce' )
            ?? null;

        // 1. All required headers present?
        if ( ! $key_id || ! $domain || ! $timestamp || ! $signature ) {
            return new \WP_Error(
                'missing_auth_headers',
                'Missing one or more required authentication headers (Key-Id, Domain, Timestamp, Signature).',
                [ 'status' => 401 ]
            );
        }

        // 2. Timestamp within acceptable window?
        $time_diff = abs( time() - (int) $timestamp );
        if ( $time_diff > self::MAX_CLOCK_SKEW ) {
            return new \WP_Error(
                'request_expired',
                sprintf( 'Request timestamp is %d seconds outside the %d-second window.', $time_diff, self::MAX_CLOCK_SKEW ),
                [ 'status' => 401 ]
            );
        }

        // 3. Look up license by key prefix (current or previous).
        $sanitized_prefix = sanitize_text_field( $key_id );
        $license          = $this->license_repo->find_by_key_prefix( $sanitized_prefix );
        $using_old_key    = false;

        if ( is_wp_error( $license ) ) {
            return $license;
        }
        if ( ! $license ) {
            // Check if this prefix matches a previous (pre-rotation) key prefix.
            $license = $this->license_repo->find_by_previous_prefix( $sanitized_prefix );
            if ( is_wp_error( $license ) ) {
                return $license;
            }
            if ( $license ) {
                $using_old_key = true;
            }
        }

        if ( ! $license ) {
            $this->rate_limiter->record_invalid_key();
            return new \WP_Error(
                'invalid_key',
                'License key not recognized.',
                [ 'status' => 403 ]
            );
        }

        // 4. Reconstruct canonical string and verify HMAC.
        // When nonce is present (M2), insert it as 2nd field for replay prevention.
        // Backward-compat: clients without nonce support use the 5-field canonical.
        $body = $request->get_body();

        if ( null !== $nonce && '' !== $nonce ) {
            $string_to_sign = implode( "\n", [
                $request->get_method(),
                $nonce,
                $request->get_route(),
                sanitize_text_field( $domain ),
                $timestamp,
                $body,
            ] );
        } else {
            $string_to_sign = implode( "\n", [
                $request->get_method(),
                $request->get_route(),
                sanitize_text_field( $domain ),
                $timestamp,
                $body,
            ] );
        }

        // Try the current key first (signing key derived from license key — NIST key separation).
        $signing_key = $this->key_derivation->derive_signing_key( $license->license_key );
        $expected    = hash_hmac( 'sha256', $string_to_sign, $signing_key );
        $verified    = hash_equals( $expected, $signature );
        sodium_memzero( $signing_key );

        // If current key fails and we have a previous key in a transition window, try that.
        if ( ! $verified && null !== $license->previous_key_encrypted && null !== $license->rotation_at ) {
            $rotation_expiry = strtotime( $license->rotation_at ) + self::ROTATION_TRANSITION_SECONDS;

            if ( time() <= $rotation_expiry ) {
                $old_key = $this->get_previous_key( $license );
                if ( null !== $old_key ) {
                    $old_signing_key = $this->key_derivation->derive_signing_key( $old_key );
                    $expected_old    = hash_hmac( 'sha256', $string_to_sign, $old_signing_key );
                    $verified        = hash_equals( $expected_old, $signature );
                    sodium_memzero( $old_signing_key );
                }
            }
        }

        if ( ! $verified ) {
            $this->rate_limiter->record_invalid_key();
            return new \WP_Error(
                'invalid_signature',
                'HMAC signature verification failed.',
                [ 'status' => 401 ]
            );
        }

        // 5. Replay prevention — only active when client sends a nonce.
        if ( null !== $nonce && '' !== $nonce ) {
            $nonce_key = 'wplicense_nonce_' . md5( $nonce . $sanitized_prefix );

            if ( get_transient( $nonce_key ) ) {
                $this->rate_limiter->record_invalid_key();
                return new \WP_Error(
                    'replay_detected',
                    'Request nonce has already been used.',
                    [ 'status' => 401 ]
                );
            }

            // Store for clock-skew window + 60s buffer so the transient outlives
            // any in-flight duplicate that arrived near the edge of the window.
            set_transient( $nonce_key, 1, self::MAX_CLOCK_SKEW + 60 );
        }

        return $license;
    }

    /**
     * Decrypt the previous (pre-rotation) key from the license row.
     */
    private function get_previous_key( License $license ): ?string {
        if ( null === $license->previous_key_encrypted ) {
            return null;
        }

        try {
            return $this->encryption->decrypt( $license->previous_key_encrypted );
        } catch ( \RuntimeException ) {
            return null;
        }
    }
}
