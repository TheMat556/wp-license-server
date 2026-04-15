<?php
/**
 * Dual-layer rate limiter: per-IP + per-license-key + global invalid-key detection.
 *
 * Uses WordPress transients for storage (fast with object cache, acceptable without).
 *
 * @package WpLicenseServer\Rest\Middleware
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Middleware;

use WpLicenseServer\ErrorCodes;

final class RateLimiter {

    private const WINDOW_SECONDS     = 60;
    private const MAX_PER_IP         = 10;
    private const MAX_PER_KEY_PREFIX = 20;
    private const MAX_GLOBAL_INVALID = 50;

    /**
     * Check rate limits for a request.
     *
     * @param string      $endpoint   The REST route being accessed.
     * @param string|null $key_prefix The license key prefix from the request header (if available).
     * @param int         $max_per_ip  Override max requests per IP (default: MAX_PER_IP).
     * @param int         $max_per_key Override max requests per key (default: MAX_PER_KEY_PREFIX).
     *
     * @return true|\WP_Error True if allowed, WP_Error if rate limited.
     */
    public function check( string $endpoint, ?string $key_prefix = null, int $max_per_ip = self::MAX_PER_IP, int $max_per_key = self::MAX_PER_KEY_PREFIX ): true|\WP_Error {
        // Use a fixed time-bucket so the window does not slide on every request.
        $bucket = (int) ( time() / self::WINDOW_SECONDS );

        // Layer 1: IP-based rate limiting.
        $ip     = $this->get_client_ip();
        $ip_key = 'rate_limit:' . md5( $endpoint . ':ip:' . $ip . ':' . $bucket );

        $ip_count = (int) get_transient( $ip_key );
        if ( $ip_count >= $max_per_ip ) {
            return $this->rate_limit_error();
        }
        // Only set expiry on the first request; subsequent increments preserve the original window.
        if ( 0 === $ip_count ) {
            set_transient( $ip_key, 1, self::WINDOW_SECONDS * 2 );
        } else {
            set_transient( $ip_key, $ip_count + 1, self::WINDOW_SECONDS * 2 );
        }

        // Layer 2: License-key-prefix-based limiting (catches distributed key-stuffing).
        if ( $key_prefix !== null ) {
            $prefix_key = 'rate_limit:' . md5( $endpoint . ':key:' . $key_prefix . ':' . $bucket );

            $key_count = (int) get_transient( $prefix_key );
            if ( $key_count >= $max_per_key ) {
                return $this->rate_limit_error();
            }
            if ( 0 === $key_count ) {
                set_transient( $prefix_key, 1, self::WINDOW_SECONDS * 2 );
            } else {
                set_transient( $prefix_key, $key_count + 1, self::WINDOW_SECONDS * 2 );
            }
        }

        return true;
    }

    /**
     * Track failed key lookups globally.
     * Fires wplicense_brute_force_detected action at threshold.
     */
    public function record_invalid_key(): void {
        $global_key = 'rate_limit:global:invalid_keys';
        $count      = (int) get_transient( $global_key );

        set_transient( $global_key, $count + 1, self::WINDOW_SECONDS );

        if ( ( $count + 1 ) >= self::MAX_GLOBAL_INVALID ) {
            /**
             * Fires when the global invalid key threshold is reached.
             *
             * @param int $count Current count of invalid key attempts.
             */
            do_action( 'wplicense_brute_force_detected', $count + 1 );
        }
    }

    private function rate_limit_error(): \WP_Error {
        return new \WP_Error(
            ErrorCodes::RATE_LIMIT_EXCEEDED->value,
            sprintf( 'Too many requests. Try again in %d seconds.', self::WINDOW_SECONDS ),
            [ 'status' => 429, 'retry_after' => self::WINDOW_SECONDS ]
        );
    }

    private function get_client_ip(): string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '0.0.0.0';

        // Only trust X-Forwarded-For when the direct connection is from a known proxy.
        $trusted_proxies = [];
        if ( defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) && is_string( WPLICENSE_TRUSTED_PROXY_IPS ) ) {
            $trusted_proxies = array_filter( array_map( 'trim', explode( ',', WPLICENSE_TRUSTED_PROXY_IPS ) ) );
        }

        if ( ! empty( $trusted_proxies ) && in_array( $remote_addr, $trusted_proxies, true ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
                : '';

            if ( $forwarded !== '' ) {
                $ips = array_map( 'trim', explode( ',', $forwarded ) );
                // Take the LAST IP — the rightmost entry is set by the trusted proxy itself,
                // preventing clients from injecting arbitrary IPs at the start of the header.
                $ip = end( $ips );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) !== false ) {
                    return $ip;
                }
            }
        }

        return $remote_addr;
    }
}
