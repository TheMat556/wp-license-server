<?php
/**
 * Tests for LicenseStateMachine and LicenseState.
 *
 * All tests pass explicit \DateTimeImmutable $at values so they are fully
 * deterministic and independent of wall-clock time.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Domain\LicenseState;
use WpLicenseServer\Domain\LicenseStateMachine;
use WpLicenseServer\Models\License;

final class LicenseStateMachineTest extends \WP_UnitTestCase {

    private LicenseStateMachine $sm;

    public function set_up(): void {
        parent::set_up();
        $this->sm = new LicenseStateMachine();
    }

    // ------------------------------------------------------------------
    // Helper: build a minimal License stub with given status / valid_until.
    // ------------------------------------------------------------------

    private function make_license( string $status, string $valid_until ): License {
        return License::from_row( (object) [
            'id' => 1,
            'license_key' => 'abcd1234...',
            'key_prefix' => 'abcd1234',
            'customer_name' => 'Test',
            'customer_email' => 'test@example.com',
            'role' => 'customer',
            'tier' => 'pro',
            'status' => $status,
            'max_activations' => 3,
            'payment_interval' => 'yearly',
            'auto_renewal' => true,
            'notes' => null,
            'key_version' => 1,
            'previous_key_encrypted' => null,
            'previous_key_prefix' => null,
            'rotation_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'valid_until' => $valid_until,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ] );
    }

    // ------------------------------------------------------------------
    // Active — valid_until is in the future.
    // ------------------------------------------------------------------

    public function test_active_when_valid_until_is_in_the_future(): void {
        $license = $this->make_license( 'active', '2030-01-01 00:00:00' );
        $at      = new \DateTimeImmutable( '2025-01-01 00:00:00', new \DateTimeZone( 'UTC' ) );

        $this->assertSame( LicenseState::Active, $this->sm->compute_state( $license, $at ) );
        $this->assertTrue( $this->sm->compute_state( $license, $at )->is_usable() );
    }

    // ------------------------------------------------------------------
    // Grace — past valid_until but within GRACE_DAYS.
    // ------------------------------------------------------------------

    public function test_grace_when_past_valid_until_but_within_grace_days(): void {
        $valid_until = '2025-01-01 00:00:00';
        $license     = $this->make_license( 'active', $valid_until );
        // 3 days after expiry — inside the default 7-day grace window.
        $at = new \DateTimeImmutable( '2025-01-04 00:00:00', new \DateTimeZone( 'UTC' ) );

        $this->assertSame( LicenseState::Grace, $this->sm->compute_state( $license, $at ) );
        $this->assertTrue( $this->sm->compute_state( $license, $at )->is_usable() );
    }

    // ------------------------------------------------------------------
    // Expired — past valid_until AND past grace deadline.
    // ------------------------------------------------------------------

    public function test_expired_when_past_grace_deadline(): void {
        $valid_until = '2025-01-01 00:00:00';
        $license     = $this->make_license( 'active', $valid_until );
        // 10 days after expiry — outside the 7-day grace window.
        $at = new \DateTimeImmutable( '2025-01-11 00:00:00', new \DateTimeZone( 'UTC' ) );

        $this->assertSame( LicenseState::Expired, $this->sm->compute_state( $license, $at ) );
        $this->assertFalse( $this->sm->compute_state( $license, $at )->is_usable() );
    }

    // ------------------------------------------------------------------
    // Cancelled — status wins regardless of valid_until.
    // ------------------------------------------------------------------

    public function test_cancelled_regardless_of_future_valid_until(): void {
        $license = $this->make_license( 'cancelled', '2099-12-31 23:59:59' );
        $at      = new \DateTimeImmutable( '2025-01-01 00:00:00', new \DateTimeZone( 'UTC' ) );

        $this->assertSame( LicenseState::Cancelled, $this->sm->compute_state( $license, $at ) );
        $this->assertFalse( $this->sm->compute_state( $license, $at )->is_usable() );
    }

    // ------------------------------------------------------------------
    // Suspended — status wins regardless of valid_until.
    // ------------------------------------------------------------------

    public function test_suspended_regardless_of_future_valid_until(): void {
        $license = $this->make_license( 'suspended', '2099-12-31 23:59:59' );
        $at      = new \DateTimeImmutable( '2025-01-01 00:00:00', new \DateTimeZone( 'UTC' ) );

        $this->assertSame( LicenseState::Suspended, $this->sm->compute_state( $license, $at ) );
        $this->assertFalse( $this->sm->compute_state( $license, $at )->is_usable() );
    }

    // ------------------------------------------------------------------
    // grace_deadline() returns valid_until + GRACE_DAYS.
    // ------------------------------------------------------------------

    public function test_grace_deadline_is_valid_until_plus_grace_days(): void {
        $license = $this->make_license( 'active', '2025-01-01 00:00:00' );
        $grace_days = defined( 'WPLICENSE_GRACE_DAYS' ) ? (int) WPLICENSE_GRACE_DAYS : 7;

        $deadline = $this->sm->grace_deadline( $license );
        $expected = new \DateTimeImmutable( '2025-01-01 00:00:00', new \DateTimeZone( 'UTC' ) );
        $expected = $expected->modify( "+{$grace_days} days" );

        $this->assertEquals( $expected, $deadline );
    }

    // ------------------------------------------------------------------
    // is_usable() contract: only Active and Grace are usable.
    // ------------------------------------------------------------------

    public function test_only_active_and_grace_are_usable(): void {
        $this->assertTrue( LicenseState::Active->is_usable() );
        $this->assertTrue( LicenseState::Grace->is_usable() );
        $this->assertFalse( LicenseState::Expired->is_usable() );
        $this->assertFalse( LicenseState::Suspended->is_usable() );
        $this->assertFalse( LicenseState::Cancelled->is_usable() );
    }
}
