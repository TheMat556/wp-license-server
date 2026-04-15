<?php
/**
 * Tests for FeatureGate middleware.
 *
 * Covers OWASP API5 (Broken Function Level Authorization):
 * - Basic-tier licenses must be denied access to native_chat endpoints (403).
 * - Pro/Agency-tier licenses must be allowed access (gate returns true).
 * - 403 response body includes both the feature name and the license tier.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Models\License;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Rest\Middleware\FeatureGate;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\TierConfig;

final class FeatureGateTest extends \WP_UnitTestCase {

    private LicenseRepository $repo;
    private FeatureGate $gate;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();

        $this->repo = new LicenseRepository( $wpdb, new EncryptionService() );
        $this->gate = new FeatureGate();
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        parent::tear_down();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function make_license( string $tier ): License {
        return $this->repo->create( [
            'customer_name'  => "Test {$tier}",
            'customer_email' => "{$tier}@example.com",
            'tier'           => $tier,
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Tier denial (403)
    // -------------------------------------------------------------------------

    public function test_basic_tier_is_denied_native_chat(): void {
        $license = $this->make_license( 'basic' );
        $result  = $this->gate->require_feature( 'native_chat', $license );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_basic_tier_returns_403_status(): void {
        $license = $this->make_license( 'basic' );
        $error   = $this->gate->require_feature( 'native_chat', $license );

        $this->assertInstanceOf( \WP_Error::class, $error );
        $data = $error->get_error_data();
        $this->assertIsArray( $data );
        $this->assertSame( 403, $data['status'] );
    }

    public function test_403_message_contains_feature_name(): void {
        $license = $this->make_license( 'basic' );
        $error   = $this->gate->require_feature( 'native_chat', $license );

        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertStringContainsString( 'native_chat', $error->get_error_message() );
    }

    public function test_403_message_contains_tier_name(): void {
        $license = $this->make_license( 'basic' );
        $error   = $this->gate->require_feature( 'native_chat', $license );

        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertStringContainsString( 'basic', $error->get_error_message() );
    }

    public function test_403_error_code_is_feature_not_available(): void {
        $license = $this->make_license( 'basic' );
        $error   = $this->gate->require_feature( 'native_chat', $license );

        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertSame( 'feature_not_available', $error->get_error_code() );
    }

    // -------------------------------------------------------------------------
    // Tier allowance (true)
    // -------------------------------------------------------------------------

    public function test_pro_tier_is_allowed_native_chat(): void {
        $license = $this->make_license( 'pro' );
        $result  = $this->gate->require_feature( 'native_chat', $license );

        $this->assertTrue( $result );
    }

    public function test_agency_tier_is_allowed_native_chat(): void {
        $license = $this->make_license( 'agency' );
        $result  = $this->gate->require_feature( 'native_chat', $license );

        $this->assertTrue( $result );
    }

    // -------------------------------------------------------------------------
    // TierConfig is single source of truth for ROUTE_FEATURES
    // -------------------------------------------------------------------------

    public function test_route_features_map_chat_routes_to_native_chat(): void {
        $this->assertSame( 'native_chat', TierConfig::ROUTE_FEATURES['chat/bootstrap'] );
        $this->assertSame( 'native_chat', TierConfig::ROUTE_FEATURES['chat/send'] );
        $this->assertSame( 'native_chat', TierConfig::ROUTE_FEATURES['chat/poll'] );
    }

    public function test_basic_tier_does_not_include_native_chat_feature(): void {
        $features = TierConfig::features_for_tier( 'basic' );
        $this->assertNotContains( 'native_chat', $features );
    }

    public function test_pro_tier_includes_native_chat_feature(): void {
        $features = TierConfig::features_for_tier( 'pro' );
        $this->assertContains( 'native_chat', $features );
    }
}
