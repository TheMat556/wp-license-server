<?php
/**
 * Computes the effective state of a license at a point in time.
 *
 * Centralises all lifecycle logic that was previously scattered across
 * License::is_active(), LicenseService::validate(), and
 * ExpiryService::check_expired().
 *
 * Grace-period length is read from the WPLICENSE_GRACE_DAYS constant
 * (define in wp-config.php); defaults to 7 days when not set.
 *
 * @package WpLicenseServer\Domain
 */

declare(strict_types=1);

namespace WpLicenseServer\Domain;

use WpLicenseServer\Models\License;

final class LicenseStateMachine {

    private const DEFAULT_GRACE_DAYS = 7;

    /**
     * Compute the effective LicenseState for a license at the given instant.
     *
     * Pass an explicit $at value in tests to avoid clock-dependent assertions.
     */
    public function compute_state( License $license, ?\DateTimeImmutable $at = null ): LicenseState {
        $now = $at ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

        /**
         * Locked is an administrative override state — it short-circuits all
         * time-based state computation. A locked license remains locked
         * regardless of valid_until, grace periods, or any other time-derived
         * condition. Only a manual unlock via the admin interface can change
         * this state.
         *
         * This guard MUST remain the first check in compute_state() so the
         * override takes priority over all derived states (active, grace,
         * expired).
         */
        if ( $license->status === 'locked' ) {
            return LicenseState::Locked;
        }

        return match ( true ) {
            $license->status === 'cancelled'                     => LicenseState::Cancelled,
            $license->status === 'suspended'                     => LicenseState::Suspended,
            $now < $this->parse_valid_until( $license )          => LicenseState::Active,
            $now < $this->grace_deadline( $license )             => LicenseState::Grace,
            default                                               => LicenseState::Expired,
        };
    }

    /**
     * Returns the instant after which a license is fully expired (past grace).
     */
    public function grace_deadline( License $license ): \DateTimeImmutable {
        $grace_days = defined( 'WPLICENSE_GRACE_DAYS' )
            ? (int) WPLICENSE_GRACE_DAYS
            : self::DEFAULT_GRACE_DAYS;

        return $this->parse_valid_until( $license )
            ->modify( '+' . $grace_days . ' days' );
    }

    /**
     * Whether a status transition is permitted by the domain matrix.
     */
    public function can_transition( string $from, string $to ): bool {
        return LicenseTransitions::is_allowed( $from, $to );
    }

    private function parse_valid_until( License $license ): \DateTimeImmutable {
        return new \DateTimeImmutable( $license->valid_until, new \DateTimeZone( 'UTC' ) );
    }
}
