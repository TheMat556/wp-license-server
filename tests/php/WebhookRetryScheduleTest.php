<?php
/**
 * Tests for webhook retry timing policy.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Services\WebhookRetrySchedule;

final class WebhookRetryScheduleTest extends \WP_UnitTestCase {

    public function test_first_attempt_is_ready_immediately(): void {
        $schedule = new WebhookRetrySchedule();

        $this->assertTrue( $schedule->is_ready_for_retry( 0, null ) );
    }

    public function test_second_attempt_waits_five_minutes(): void {
        $schedule = new WebhookRetrySchedule();
        $recent   = gmdate( 'Y-m-d H:i:s', time() - ( 4 * MINUTE_IN_SECONDS ) );
        $ready    = gmdate( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) );

        $this->assertFalse( $schedule->is_ready_for_retry( 1, $recent ) );
        $this->assertTrue( $schedule->is_ready_for_retry( 1, $ready ) );
    }

    public function test_fifth_failure_marks_job_failed(): void {
        $schedule = new WebhookRetrySchedule();

        $this->assertFalse( $schedule->should_mark_failed( 4 ) );
        $this->assertTrue( $schedule->should_mark_failed( 5 ) );
        $this->assertFalse( $schedule->is_ready_for_retry( 5, gmdate( 'Y-m-d H:i:s' ) ) );
    }
}
