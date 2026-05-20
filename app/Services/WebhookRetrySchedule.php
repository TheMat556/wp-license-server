<?php
/**
 * Retry timing policy for queued webhooks.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

final class WebhookRetrySchedule {

    /**
     * Delay keyed by the number of previous failed attempts.
     *
     * attempts=0 means the first send is immediate.
     *
     * @var array<int, int>
     */
    private const DELAYS = array(
        0 => 0,
        1 => 5 * MINUTE_IN_SECONDS,
        2 => 30 * MINUTE_IN_SECONDS,
        3 => 2 * HOUR_IN_SECONDS,
        4 => 12 * HOUR_IN_SECONDS,
    );

    /**
     * Priority delays for lock/unlock events (aggressive retry).
     * Attempts keyed by number of previous failed attempts:
     *   0 = first send (immediate)
     *   1 = 30 seconds
     *   2 = 2 minutes
     *   3 = 10 minutes
     *   4 = 30 minutes
     *
     * @var array<int, int>
     */
    private const PRIORITY_DELAYS = array(
        0 => 0,
        1 => 30,        // 30 seconds
        2 => 120,       // 2 minutes
        3 => 600,       // 10 minutes
        4 => 1800,      // 30 minutes
    );

    /**
     * Event prefixes that should use the priority retry schedule.
     *
     * @var array<int, string>
     */
    private const PRIORITY_EVENTS = array(
        'license.locked',
        'license.unlocked',
    );

    public function is_ready_for_retry( int $attempts, ?string $last_attempt, string $event = '' ): bool {
        if ( 0 === $attempts ) {
            return true;
        }

        if ( $this->should_mark_failed( $attempts ) ) {
            return false;
        }

        if ( null === $last_attempt || '' === $last_attempt ) {
            return true;
        }

        $timestamp = strtotime( $last_attempt . ' UTC' );

        if ( false === $timestamp ) {
            return true;
        }

        $is_priority = $this->is_priority_event( $event );
        $delays      = $is_priority ? self::PRIORITY_DELAYS : self::DELAYS;
        $delay       = $delays[ $attempts ] ?? ( $is_priority ? HOUR_IN_SECONDS : DAY_IN_SECONDS );

        return time() >= ( $timestamp + $delay );
    }

    /**
     * Returns whether the given event name uses the priority retry schedule.
     */
    private function is_priority_event( string $event ): bool {
        foreach ( self::PRIORITY_EVENTS as $prefix ) {
            if ( str_starts_with( $event, $prefix ) ) {
                return true;
            }
        }
        return false;
    }

    public function should_mark_failed( int $attempts ): bool {
        return $attempts >= 5;
    }
}
