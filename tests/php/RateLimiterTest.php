<?php
/**
 * Tests for RateLimiter.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Rest\Middleware\RateLimiter;

final class RateLimiterTest extends \WP_UnitTestCase {

    private RateLimiter $limiter;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%rate_limit%'" );
        $this->limiter = new RateLimiter();
    }

    public function tear_down(): void {
        // Clean up rate limit transients.
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%rate_limit%'" );
        parent::tear_down();
    }

    public function test_allows_requests_within_ip_limit(): void {
        for ( $i = 0; $i < 10; $i++ ) {
            $result = $this->limiter->check( '/license-server/v1/validate' );
            $this->assertTrue( $result );
        }
    }

    public function test_blocks_request_after_ip_threshold(): void {
        // Exhaust the limit.
        for ( $i = 0; $i < 10; $i++ ) {
            $this->limiter->check( '/license-server/v1/validate' );
        }

        // Next request should be blocked.
        $result = $this->limiter->check( '/license-server/v1/validate' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
    }

    public function test_blocks_request_after_key_prefix_threshold(): void {
        $prefix = 'ab12cd34';

        // Exhaust key-prefix limit (20).
        for ( $i = 0; $i < 20; $i++ ) {
            $this->limiter->check( '/license-server/v1/validate', $prefix );
        }

        // Next request should be blocked (key prefix layer blocks first).
        $result = $this->limiter->check( '/license-server/v1/validate', $prefix );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
    }

    public function test_record_invalid_key_fires_action_at_threshold(): void {
        $action_fired = false;
        $recorded_count = 0;

        add_action( 'wplicense_brute_force_detected', function ( int $count ) use ( &$action_fired, &$recorded_count ): void {
            $action_fired   = true;
            $recorded_count = $count;
        } );

        for ( $i = 0; $i < 50; $i++ ) {
            $this->limiter->record_invalid_key();
        }

        $this->assertTrue( $action_fired );
        $this->assertSame( 50, $recorded_count );
    }

    public function test_separate_endpoints_have_independent_limits(): void {
        // Exhaust one endpoint.
        for ( $i = 0; $i < 10; $i++ ) {
            $this->limiter->check( '/license-server/v1/activate' );
        }

        // Different endpoint should still work.
        $result = $this->limiter->check( '/license-server/v1/validate' );
        $this->assertTrue( $result );
    }
}
