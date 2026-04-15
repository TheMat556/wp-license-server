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

    public function is_ready_for_retry( int $attempts, ?string $last_attempt ): bool {
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

        $delay = self::DELAYS[ $attempts ] ?? DAY_IN_SECONDS;

        return time() >= ( $timestamp + $delay );
    }

    public function should_mark_failed( int $attempts ): bool {
        return $attempts >= 5;
    }
}
