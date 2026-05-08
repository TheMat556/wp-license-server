<?php
/**
 * Utility for resolving the real client IP address.
 *
 * Handles X-Forwarded-For with configurable trusted proxy lists to
 * prevent IP spoofing. Uses FIRST IP in the XFF chain (the original
 * client) when REMOTE_ADDR matches a trusted proxy. Configure your
 * reverse proxy with the standard `proxy_set_header X-Forwarded-For
 * $proxy_add_x_forwarded_for;` directive (nginx) or equivalent.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

final class IpResolver {

    /**
     * Resolve the real client IP from the current request.
     *
     * @return string Resolved IP address (falls back to '0.0.0.0').
     */
    public function get_client_ip(): string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '0.0.0.0';

        // Trusted-header extraction is opt-in; disabled by default to prevent spoofing.
        $trusted_proxies_config = apply_filters( 'wplicense_trusted_proxy_ips', defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) ? WPLICENSE_TRUSTED_PROXY_IPS : null );

        if ( is_array( $trusted_proxies_config ) ) {
            $trusted_proxies = array_map( 'trim', $trusted_proxies_config );
        } elseif ( is_string( $trusted_proxies_config ) && '' !== $trusted_proxies_config ) {
            $trusted_proxies = array_map( 'trim', explode( ',', $trusted_proxies_config ) );
        } else {
            return $remote_addr;
        }

        if ( ! in_array( $remote_addr, $trusted_proxies, true ) ) {
            return $remote_addr;
        }

        // Cloudflare: CF-Connecting-IP is authoritative when Cloudflare terminates TLS.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $cf_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
            if ( filter_var( $cf_ip, FILTER_VALIDATE_IP ) !== false ) {
                return $cf_ip;
            }
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
            : '';

        if ( '' === $xff ) {
            return $remote_addr;
        }

        // Standard XFF format: "client, proxy1, proxy2". Original client is the
        // FIRST entry. Reading the last entry would let any client spoof its IP by
        // injecting an X-Forwarded-For header — the trusted proxy would append
        // their address, but the attacker-controlled value would still be returned.
        $ips       = array_map( 'trim', explode( ',', $xff ) );
        $client_ip = $ips[0] ?? '';

        if ( '' !== $client_ip && filter_var( $client_ip, FILTER_VALIDATE_IP ) !== false ) {
            return $client_ip;
        }

        return $remote_addr;
    }
}
