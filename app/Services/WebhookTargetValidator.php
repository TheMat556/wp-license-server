<?php
/**
 * Validates webhook destination hosts to avoid SSRF.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

use WpLicenseServer\ErrorCodes;
use function __;
use function sprintf;

final class WebhookTargetValidator {

    private DnsResolver $dns_resolver;

    public function __construct(
        ?DnsResolver $dns_resolver = null,
    ) {
        $this->dns_resolver = $dns_resolver ?? new DnsResolver();
    }

    /**
     * Normalize a raw client domain to a canonical host value.
     */
    public function normalize_domain( string $domain ): string {
        $raw = strtolower( trim( $domain ) );

        if ( '' === $raw ) {
            return '';
        }

        $candidate = $raw;

        if ( false === strpos( $candidate, '://' ) ) {
            $candidate = 'https://' . ltrim( $candidate, '/' );
        }

        $host = wp_parse_url( $candidate, PHP_URL_HOST );

        if ( is_string( $host ) && '' !== $host ) {
            $raw = $host;
        }

        $raw = (string) preg_replace( '/:\d+$/', '', $raw );
        $raw = (string) preg_replace( '/^www\./', '', $raw );
        $raw = trim( $raw, ". \t\n\r\0\x0B" );

        return sanitize_text_field( $raw );
    }

    /**
     * Validate that a webhook host resolves only to public IPs.
     *
     * @return string|\WP_Error Normalized host or error.
     */
    public function validate_public_domain( string $domain ) {
        $normalized = $this->normalize_domain( $domain );

        if ( '' === $normalized ) {
            return new \WP_Error(
                ErrorCodes::INVALID_DOMAIN->value,
                __( 'A valid public domain is required.', 'wp-license-server' ),
                array( 'status' => 400 )
            );
        }

        // In development mode, bypass all SSRF checks.
        if ( defined( 'WPLICENSE_DEV_MODE' ) && WPLICENSE_DEV_MODE ) {
            return $normalized;
        }

        $old_option = get_option( 'wplicense_development_mode', '0' );
        if ( '1' === $old_option ) {
            trigger_error(
                'wplicense_development_mode option is deprecated. Define WPLICENSE_DEV_MODE constant in wp-config.php instead.',
                E_USER_DEPRECATED
            );
            return $normalized;
        }

        if ( in_array( $normalized, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_DOMAIN->value,
                __( 'Localhost destinations are not allowed.', 'wp-license-server' ),
                array( 'status' => 400 )
            );
        }

        if ( preg_match( '/\.(?:local|localhost|internal|lan|home|corp|intranet|private)$/', $normalized ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_DOMAIN->value,
                __( 'Private or internal domains are not allowed.', 'wp-license-server' ),
                array( 'status' => 400 )
            );
        }

        if ( false !== filter_var( $normalized, FILTER_VALIDATE_IP ) ) {
            if ( ! $this->dns_resolver->is_public_ip( $normalized ) ) {
                return new \WP_Error(
                    ErrorCodes::INVALID_DOMAIN->value,
                    __( 'Private or reserved IP addresses are not allowed.', 'wp-license-server' ),
                    array( 'status' => 400 )
                );
            }

            return $normalized;
        }

        if ( ! preg_match( '/^[a-z0-9.-]+$/', $normalized ) || false === strpos( $normalized, '.' ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_DOMAIN->value,
                __( 'The activation domain must be a valid public hostname.', 'wp-license-server' ),
                array( 'status' => 400 )
            );
        }

        $resolved_ips = $this->dns_resolver->resolve_ips( $normalized );

        if ( empty( $resolved_ips ) ) {
            return new \WP_Error(
                ErrorCodes::DNS_RESOLUTION_FAILED->value,
                __( 'The activation domain must resolve to a public IP address.', 'wp-license-server' ),
                array( 'status' => 400 )
            );
        }

        $skip_private_ip_check = (bool) apply_filters( 'wplicense_allow_private_webhook_target', false, $normalized, $resolved_ips );

        if ( ! $skip_private_ip_check ) {
            foreach ( $resolved_ips as $ip ) {
                if ( ! $this->dns_resolver->is_public_ip( $ip ) ) {
                    return new \WP_Error(
                        ErrorCodes::PRIVATE_IP->value,
                        __( 'The activation domain resolves to a private or reserved IP address.', 'wp-license-server' ),
                        array( 'status' => 400 )
                    );
                }
            }
        }

        $allowed = apply_filters( 'wplicense_allow_webhook_domain', true, $normalized, $resolved_ips );

        if ( false === $allowed ) {
            return new \WP_Error(
                ErrorCodes::INVALID_DOMAIN->value,
                __( 'The activation domain is not allowed.', 'wp-license-server' ),
                array( 'status' => 400 )
            );
        }

        return $normalized;
    }
}
