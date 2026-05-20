<?php
/**
 * DNS resolution utility for webhook domain validation.
 *
 * Resolves IPv4 and IPv6 addresses for a given domain, providing
 * a single source of truth for both activation-time validation and
 * dispatch-time IP pinning.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

class DnsResolver {

    /**
     * Resolve a domain to its IP addresses.
     *
     * @return array<int, string> List of IP addresses (IPv4 and IPv6).
     */
    public function resolve_ips( string $domain ): array {
        $ips = array();

        if ( function_exists( 'dns_get_record' ) ) {
            $records = @dns_get_record( $domain, DNS_A + DNS_AAAA );

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

        $result = array_values( array_unique( array_filter( $ips, fn( $ip ) => '' !== $ip ) ) );

        if ( (bool) apply_filters( 'wplicense_dns_resolver_debug', defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            error_log( sprintf( '[DnsResolver] resolve_ips(%s) = %s', $domain, (string) wp_json_encode( $result ) ) );
        }

        return $result;
    }

    /**
     * Check if an IP is a public (non-private, non-reserved) address.
     *
     * Handles IPv4-mapped IPv6 (::ffff:x.x.x.x), IPv4-embedded IPv6
     * (64:ff9b::/96), link-local (fe80::/10), and loopback (::1) which
     * FILTER_FLAG_NO_PRIV_RANGE does not reject on its own.
     */
    public function is_public_ip( string $ip ): bool {
        // Normalize IPv4-mapped IPv6 (::ffff:127.0.0.1 → check as IPv4).
        if ( preg_match( '/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m ) ) {
            return false !== filter_var(
                $m[1],
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        // Block IPv4-embedded IPv6 (64:ff9b::/96).
        if ( preg_match( '/^64:ff9b:/i', $ip ) ) {
            return false;
        }

        // Block link-local (fe80::/10).
        if ( preg_match( '/^fe[89ab][0-9a-f]:/i', $ip ) ) {
            return false;
        }

        // Block loopback (::1).
        if ( '::1' === $ip ) {
            return false;
        }

        return false !== filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
