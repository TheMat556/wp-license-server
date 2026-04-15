<?php
/**
 * Tests for hardened IP resolution in RateLimiter (L5).
 *
 * Covers OWASP ASVS V5.3 / threat T3 (IP spoofing via X-Forwarded-For):
 * - Without a trusted proxy config, X-Forwarded-For is ignored.
 * - When REMOTE_ADDR matches a trusted proxy, the LAST XFF IP is used.
 * - An invalid IP in X-Forwarded-For falls back to REMOTE_ADDR.
 *
 * Uses ReflectionMethod to access the private get_client_ip() method.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Rest\Middleware\RateLimiter;

final class RateLimiterIpTest extends \WP_UnitTestCase {

    private RateLimiter $limiter;
    private \ReflectionMethod $get_ip;

    /** @var array<string,mixed> Original $_SERVER values restored after each test. */
    private array $original_server = [];

    public function set_up(): void {
        parent::set_up();
        $this->limiter = new RateLimiter();
        $this->get_ip  = new \ReflectionMethod( RateLimiter::class, 'get_client_ip' );
        $this->get_ip->setAccessible( true );
        $this->original_server = $_SERVER;
    }

    public function tear_down(): void {
        $_SERVER = $this->original_server;
        parent::tear_down();
    }

    // ------------------------------------------------------------------
    // Helper: resolve the IP via the private method.
    // ------------------------------------------------------------------

    private function resolve_ip(): string {
        return $this->get_ip->invoke( $this->limiter );
    }

    // ------------------------------------------------------------------
    // Test 1 — XFF is ignored when WPLICENSE_TRUSTED_PROXY_IPS is not defined.
    // ------------------------------------------------------------------

    public function test_xff_ignored_when_no_trusted_proxy_defined(): void {
        // Ensure the constant is not defined (standard test environment).
        if ( defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) ) {
            $this->markTestSkipped( 'WPLICENSE_TRUSTED_PROXY_IPS is defined in this environment.' );
        }

        $_SERVER['REMOTE_ADDR']          = '203.0.113.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8';

        $this->assertSame( '203.0.113.5', $this->resolve_ip() );
    }

    // ------------------------------------------------------------------
    // Test 2 — Last XFF IP is used when REMOTE_ADDR is a trusted proxy.
    // ------------------------------------------------------------------

    public function test_last_xff_ip_used_when_remote_addr_is_trusted_proxy(): void {
        if ( ! defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) ) {
            define( 'WPLICENSE_TRUSTED_PROXY_IPS', '10.0.0.1' );
        } elseif ( WPLICENSE_TRUSTED_PROXY_IPS !== '10.0.0.1' ) {
            $this->markTestSkipped( 'WPLICENSE_TRUSTED_PROXY_IPS is already defined with a different value.' );
        }

        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        // Client sends "1.2.3.4"; proxy appends its perception: "203.0.113.5".
        // The LAST entry (203.0.113.5) is set by the trusted proxy — use that.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.5';

        $this->assertSame( '203.0.113.5', $this->resolve_ip() );
    }

    // ------------------------------------------------------------------
    // Test 3 — Invalid XFF IP falls back to REMOTE_ADDR.
    // ------------------------------------------------------------------

    public function test_invalid_xff_falls_back_to_remote_addr(): void {
        if ( ! defined( 'WPLICENSE_TRUSTED_PROXY_IPS' ) ) {
            define( 'WPLICENSE_TRUSTED_PROXY_IPS', '10.0.0.1' );
        } elseif ( WPLICENSE_TRUSTED_PROXY_IPS !== '10.0.0.1' ) {
            $this->markTestSkipped( 'WPLICENSE_TRUSTED_PROXY_IPS is already defined with a different value.' );
        }

        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip-address';

        // The last XFF value is invalid — fall back to REMOTE_ADDR.
        $this->assertSame( '10.0.0.1', $this->resolve_ip() );
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

        // Direct connection from a non-proxy IP.
        $_SERVER['REMOTE_ADDR']          = '203.0.113.99';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $this->assertSame( '203.0.113.99', $this->resolve_ip() );
    }
}
