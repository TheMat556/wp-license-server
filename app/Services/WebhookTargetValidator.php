<?php
/**
 * Validates webhook destination hosts to avoid SSRF.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

final class WebhookTargetValidator {

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
                'invalid_domain',
                'A valid public domain is required.',
                array( 'status' => 400 )
            );
        }

        if ( in_array( $normalized, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
            return new \WP_Error(
                'invalid_domain',
                'Localhost destinations are not allowed.',
                array( 'status' => 400 )
            );
        }

        if ( preg_match( '/\.(?:local|localhost|internal)$/', $normalized ) ) {
            return new \WP_Error(
                'invalid_domain',
                'Private or internal domains are not allowed.',
                array( 'status' => 400 )
            );
        }

        if ( false !== filter_var( $normalized, FILTER_VALIDATE_IP ) ) {
            if ( ! $this->is_public_ip( $normalized ) ) {
                return new \WP_Error(
                    'invalid_domain',
                    'Private or reserved IP addresses are not allowed.',
                    array( 'status' => 400 )
                );
            }

            return $normalized;
        }

        if ( ! preg_match( '/^[a-z0-9.-]+$/', $normalized ) || false === strpos( $normalized, '.' ) ) {
            return new \WP_Error(
                'invalid_domain',
                'The activation domain must be a valid public hostname.',
                array( 'status' => 400 )
            );
        }

        $resolved_ips = $this->resolve_ips( $normalized );

        if ( empty( $resolved_ips ) ) {
            return new \WP_Error(
                'invalid_domain',
                'The activation domain must resolve to a public IP address.',
                array( 'status' => 400 )
            );
        }

        foreach ( $resolved_ips as $ip ) {
            if ( ! $this->is_public_ip( $ip ) ) {
                return new \WP_Error(
                    'invalid_domain',
                    'The activation domain resolves to a private or reserved IP address.',
                    array( 'status' => 400 )
                );
            }
        }

        $allowed = apply_filters( 'wplicense_allow_webhook_domain', true, $normalized, $resolved_ips );

        if ( false === $allowed ) {
            return new \WP_Error(
                'invalid_domain',
                'The activation domain is not allowed.',
                array( 'status' => 400 )
            );
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function resolve_ips( string $domain ): array {
        $ips = array();

        if ( function_exists( 'dns_get_record' ) ) {
            $records = dns_get_record( $domain, DNS_A + DNS_AAAA );

            if ( is_array( $records ) ) {
                foreach ( $records as $record ) {
                    if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
                        $ips[] = $record['ip'];
                    }

                    if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if ( empty( $ips ) && function_exists( 'gethostbynamel' ) ) {
            $fallback = gethostbynamel( $domain );
            if ( is_array( $fallback ) ) {
                $ips = array_merge( $ips, $fallback );
            }
        }

        return array_values( array_unique( array_filter( $ips, 'is_string' ) ) );
    }

    private function is_public_ip( string $ip ): bool {
        return false !== filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
