<?php
/**
 * Tests for WebhookTargetValidator including development mode.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Services\DnsResolver;
use WpLicenseServer\Services\WebhookTargetValidator;

final class WebhookTargetValidatorTest extends \WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        delete_option( 'wplicense_development_mode' );
    }

    public function tear_down(): void {
        delete_option( 'wplicense_development_mode' );
        parent::tear_down();
    }

    private function make_validator( bool $mock_public_only = true ): WebhookTargetValidator {
        if ( ! $mock_public_only ) {
            return new WebhookTargetValidator();
        }

        $mock_dns = $this->createMock( DnsResolver::class );
        $mock_dns->method( 'resolve_ips' )->willReturn( [ '1.2.3.4' ] );
        $mock_dns->method( 'is_public_ip' )->willReturnCallback(
            static function ( string $ip ): bool {
                return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
            }
        );

        return new WebhookTargetValidator( $mock_dns );
    }

    // -----------------------------------------------------------------------
    // Normal validation (development mode OFF)
    // -----------------------------------------------------------------------

    public function test_empty_domain_rejected(): void {
        $validator = $this->make_validator();
        $result    = $validator->validate_public_domain( '' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_domain', $result->get_error_code() );
    }

    public function test_localhost_rejected(): void {
        $validator = $this->make_validator();
        $result    = $validator->validate_public_domain( 'localhost' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_private_ip_rejected(): void {
        $validator = $this->make_validator();
        $result    = $validator->validate_public_domain( '192.168.1.1' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_public_domain_accepted(): void {
        $validator = $this->make_validator();
        $result    = $validator->validate_public_domain( 'example.com' );

        $this->assertIsString( $result );
        $this->assertSame( 'example.com', $result );
    }

    // -----------------------------------------------------------------------
    // Development mode ON — all domains pass
    // -----------------------------------------------------------------------

    public function test_dev_mode_allows_empty_domain(): void {
        update_option( 'wplicense_development_mode', '1' );
        $validator = $this->make_validator();
        $result    = $validator->validate_public_domain( '' );

        // Empty string is still rejected because the dev mode check happens
        // after the empty check.
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_dev_mode_allows_localhost(): void {
        update_option( 'wplicense_development_mode', '1' );
        $validator = $this->make_validator();
        $result    = $validator->validate_public_domain( 'localhost' );

        $this->assertIsString( $result );
        $this->assertSame( 'localhost', $result );
    }

    public function test_dev_mode_allows_private_ip(): void {
        update_option( 'wplicense_development_mode', '1' );
        $validator = $this->make_validator();
        $result    = $validator->validate_public_domain( '192.168.1.1' );

        $this->assertIsString( $result );
        $this->assertSame( '192.168.1.1', $result );
    }

    public function test_dev_mode_allows_local_tld(): void {
        update_option( 'wplicense_development_mode', '1' );
        $validator = $this->make_validator();
        $result    = $validator->validate_public_domain( 'myproject.local' );

        $this->assertIsString( $result );
        $this->assertSame( 'myproject.local', $result );
    }

    public function test_dev_mode_allows_127_0_0_1(): void {
        update_option( 'wplicense_development_mode', '1' );
        $validator = $this->make_validator();
        $result    = $validator->validate_public_domain( '127.0.0.1' );

        $this->assertIsString( $result );
        $this->assertSame( '127.0.0.1', $result );
    }

    public function test_dev_mode_still_rejects_truly_empty(): void {
        update_option( 'wplicense_development_mode', '1' );
        $validator = $this->make_validator();
        $result    = $validator->validate_public_domain( '' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    // -----------------------------------------------------------------------
    // Normalization still works in dev mode
    // -----------------------------------------------------------------------

    public function test_dev_mode_normalizes_domain(): void {
        update_option( 'wplicense_development_mode', '1' );
        $validator = $this->make_validator();
        $result    = $validator->validate_public_domain( 'https://MySite.LOCAL/' );

        $this->assertIsString( $result );
        $this->assertSame( 'mysite.local', $result );
    }

    // -----------------------------------------------------------------------
    // WebhookDispatcher also respects the dev mode option (integration check)
    // -----------------------------------------------------------------------

    public function test_webhook_dispatcher_resolves_private_ip_in_dev_mode(): void {
        update_option( 'wplicense_development_mode', '1' );

        // Use a real DNS resolver — we only care that the private IP
        // check is skipped, not what it resolves to.
        $validator = new WebhookTargetValidator();
        $result    = $validator->validate_public_domain( '192.168.1.1' );

        $this->assertIsString( $result );
        $this->assertSame( '192.168.1.1', $result );
    }
}
