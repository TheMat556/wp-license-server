<?php
/**
 * Tests for hardened IP resolution in IpResolver.
 *
 * Covers OWASP ASVS V5.3 / threat T3 (IP spoofing via X-Forwarded-For):
 * - Without a trusted proxy config, X-Forwarded-For is ignored.
 * - Cloudflare CF-Connecting-IP header is authoritative regardless of
 *   trusted proxy configuration.
 * - When REMOTE_ADDR matches a trusted proxy, the FIRST XFF IP (real client)
 *   is used.
 * - An invalid IP in X-Forwarded-For falls back to REMOTE_ADDR.
 * - Falls back to '0.0.0.0' when REMOTE_ADDR is not set.
 *
 * Uses ReflectionMethod to access the resolution method.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Services\IpResolver;

final class IpResolverTest extends \WP_UnitTestCase {

    private IpResolver $resolver;
    private \ReflectionMethod $get_ip;

    /** @var array<string,mixed> Original $_SERVER values restored after each test. */
    private array $original_server = [];

    public function set_up(): void {
        parent::set_up();
        $this->resolver = new IpResolver();
        $this->get_ip   = new \ReflectionMethod( IpResolver::class, 'get_client_ip' );
        $this->get_ip->setAccessible( true );
        $this->original_server = $_SERVER;
    }

    public function tear_down(): void {
        $_SERVER = $this->original_server;
        parent::tear_down();
    }

    // ------------------------------------------------------------------
    // Helper: resolve the IP via the public method.
    // ------------------------------------------------------------------

    private function resolve_ip(): string {
        return $this->get_ip->invoke( $this->resolver );
    }

    // ------------------------------------------------------------------
    // Test 1 — REMOTE_ADDR is returned when WPLICENSE_TRUSTED_PROXY_IPS
    //           is not defined.
    // ------------------------------------------------------------------

    public function test_remote_addr_returned_when_no_trusted_proxy_defined(): void {
        if ( defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) ) {
            $this->markTestSkipped( 'WPLICENSE_TRUSTED_PROXY_IPS is defined in this environment.' );
        }

        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';

        $this->assertSame( '203.0.113.5', $this->resolve_ip() );
    }

    // ------------------------------------------------------------------
    // Test 2 — Cloudflare CF-Connecting-IP is used when present
    //           (regardless of trusted proxy setting).
    // ------------------------------------------------------------------

    public function test_cf_connecting_ip_used_when_present(): void {
        if ( defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) ) {
            $this->markTestSkipped( 'WPLICENSE_TRUSTED_PROXY_IPS is defined in this environment.' );
        }

        // Cloudflare IP ranges: constant must be defined for Cloudflare header to be evaluated.
        if ( ! defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) ) {
            define( 'WPLICENSE_TRUSTED_PROXY_IPS', '173.245.48.1' );
        }

        $_SERVER['REMOTE_ADDR']            = '173.245.48.1';
        $_SERVER['HTTP_CF_CONNECTING_IP']  = '1.2.3.4';

        $this->assertSame( '1.2.3.4', $this->resolve_ip() );
    }

    // ------------------------------------------------------------------
    // Test 3 — First XFF IP (real client) is used when REMOTE_ADDR is a
    //           trusted proxy.
    // ------------------------------------------------------------------

    public function test_first_xff_ip_used_when_remote_addr_is_trusted_proxy(): void {
        if ( ! defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) ) {
            define( 'WPLICENSE_TRUSTED_PROXY_IPS', '10.0.0.1' );
        } elseif ( WPLICENSE_TRUSTED_PROXY_IPS !== '10.0.0.1' ) {
            $this->markTestSkipped( 'WPLICENSE_TRUSTED_PROXY_IPS is already defined with a different value.' );
        }

        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.5';

        $this->assertSame( '1.2.3.4', $this->resolve_ip() );
    }

    // ------------------------------------------------------------------
    // Test 4 — XFF is ignored when REMOTE_ADDR is NOT in trusted list.
    // ------------------------------------------------------------------

    public function test_xff_ignored_when_remote_addr_not_in_trusted_list(): void {
        if ( ! defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) ) {
            define( 'WPLICENSE_TRUSTED_PROXY_IPS', '10.0.0.1' );
        } elseif ( WPLICENSE_TRUSTED_PROXY_IPS !== '10.0.0.1' ) {
            $this->markTestSkipped( 'WPLICENSE_TRUSTED_PROXY_IPS is already defined with a different value.' );
        }

        $_SERVER['REMOTE_ADDR']          = '203.0.113.99';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $this->assertSame( '203.0.113.99', $this->resolve_ip() );
    }

    // ------------------------------------------------------------------
    // Test 5 — Invalid XFF IP falls back to REMOTE_ADDR.
    // ------------------------------------------------------------------

    public function test_invalid_xff_falls_back_to_remote_addr(): void {
        if ( ! defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) ) {
            define( 'WPLICENSE_TRUSTED_PROXY_IPS', '10.0.0.1' );
        } elseif ( WPLICENSE_TRUSTED_PROXY_IPS !== '10.0.0.1' ) {
            $this->markTestSkipped( 'WPLICENSE_TRUSTED_PROXY_IPS is already defined with a different value.' );
        }

        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip-address';

        $this->assertSame( '10.0.0.1', $this->resolve_ip() );
    }

    // ------------------------------------------------------------------
    // Test 6 — Falls back to '0.0.0.0' when REMOTE_ADDR is not set.
    // ------------------------------------------------------------------

    public function test_fallback_to_zero_when_remote_addr_not_set(): void {
        if ( defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) ) {
            $this->markTestSkipped( 'WPLICENSE_TRUSTED_PROXY_IPS is defined in this environment.' );
        }

        unset( $_SERVER['REMOTE_ADDR'] );

        $this->assertSame( '0.0.0.0', $this->resolve_ip() );
    }
}
