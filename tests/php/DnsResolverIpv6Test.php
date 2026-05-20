<?php
/**
 * Tests for DnsResolver::is_public_ip — IPv4-mapped IPv6 and other SSRF-bypass forms.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Services\DnsResolver;
use PHPUnit\Framework\TestCase;

final class DnsResolverIpv6Test extends TestCase {

    private DnsResolver $resolver;

    public function set_up(): void {
        $this->resolver = new DnsResolver();
    }

    public function test_public_ipv4_returns_true(): void {
        $this->assertTrue( $this->resolver->is_public_ip( '8.8.8.8' ) );
    }

    public function test_private_ipv4_returns_false(): void {
        $this->assertFalse( $this->resolver->is_public_ip( '192.168.1.1' ) );
        $this->assertFalse( $this->resolver->is_public_ip( '10.0.0.1' ) );
        $this->assertFalse( $this->resolver->is_public_ip( '172.16.0.1' ) );
        $this->assertFalse( $this->resolver->is_public_ip( '127.0.0.1' ) );
    }

    public function test_ipv4_mapped_ipv6_loopback_returns_false(): void {
        $this->assertFalse( $this->resolver->is_public_ip( '::ffff:127.0.0.1' ) );
    }

    public function test_ipv4_mapped_ipv6_private_returns_false(): void {
        $this->assertFalse( $this->resolver->is_public_ip( '::ffff:192.168.1.1' ) );
        $this->assertFalse( $this->resolver->is_public_ip( '::ffff:10.0.0.1' ) );
    }

    public function test_ipv4_mapped_ipv6_public_returns_true(): void {
        $this->assertTrue( $this->resolver->is_public_ip( '::ffff:8.8.8.8' ) );
        $this->assertTrue( $this->resolver->is_public_ip( '::ffff:1.1.1.1' ) );
    }

    public function test_ipv4_embedded_ipv6_blocked(): void {
        $this->assertFalse( $this->resolver->is_public_ip( '64:ff9b::1.2.3.4' ) );
        $this->assertFalse( $this->resolver->is_public_ip( '64:ff9b::8.8.8.8' ) );
    }

    public function test_link_local_ipv6_blocked(): void {
        $this->assertFalse( $this->resolver->is_public_ip( 'fe80::1' ) );
        $this->assertFalse( $this->resolver->is_public_ip( 'fe80::1234:5678' ) );
        $this->assertFalse( $this->resolver->is_public_ip( 'fe90::1' ) );
        $this->assertFalse( $this->resolver->is_public_ip( 'fea0::1' ) );
        $this->assertFalse( $this->resolver->is_public_ip( 'feb0::1' ) );
    }

    public function test_loopback_ipv6_blocked(): void {
        $this->assertFalse( $this->resolver->is_public_ip( '::1' ) );
    }

    public function test_public_ipv6_returns_true(): void {
        $this->assertTrue( $this->resolver->is_public_ip( '2001:4860:4860::8888' ) );
        $this->assertTrue( $this->resolver->is_public_ip( '2606:4700:4700::1111' ) );
    }
}
