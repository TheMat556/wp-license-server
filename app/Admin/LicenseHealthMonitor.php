<?php
/**
 * Admin health monitor — displays notices on license server admin pages.
 *
 * Checks: webhook queue depth, stale pending jobs, failed webhooks,
 * and cron schedule status. Only runs on the license server admin page.
 *
 * @package WpLicenseServer\Admin
 */

declare(strict_types=1);

namespace WpLicenseServer\Admin;

final class LicenseHealthMonitor {

    /**
     * Hooked to admin_notices.
     */
    public function check_health(): void {
        // Only run on our admin page.
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'tools_page_wp-license-server' ) {
            return;
        }

        global $wpdb;

        $queue_table = $wpdb->prefix . 'license_webhook_queue';

        // 1. Webhook queue depth > 20.
        $pending = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE status = %s",
            'pending'
        ) );
        if ( $pending > 20 ) {
            $this->notice(
                'notice-warning',
                sprintf( 'Webhook queue has %d pending items (threshold: 20). Check webhook dispatcher.', $pending )
            );
        }

        // 2. Stale pending jobs older than 24 hours.
        $stale = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE status = %s AND created_at < %s",
            'pending',
            gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
        ) );
        if ( $stale > 0 ) {
            $this->notice(
                'notice-error',
                sprintf( '%d pending webhook(s) are older than 24 hours. Investigate cron execution.', $stale )
            );
        }

        // 3. Failed webhooks in last 7 days.
        $failed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE status = %s AND created_at > %s",
            'failed',
            gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
        ) );
        if ( $failed > 0 ) {
            $this->notice(
                'notice-warning',
                sprintf( '%d webhook(s) failed in the last 7 days.', $failed )
            );
        }

        // 4. Expiry cron not scheduled.
        if ( ! wp_next_scheduled( 'wplicense_check_expiry' ) ) {
            $this->notice(
                'notice-error',
                'License expiry cron is NOT scheduled. Deactivate and reactivate the plugin, or check DISABLE_WP_CRON.'
            );
        }

        // 5. Webhook dispatcher cron not scheduled.
        if ( ! wp_next_scheduled( 'wplicense_dispatch_webhooks' ) ) {
            $this->notice(
                'notice-error',
                'Webhook dispatcher cron is NOT scheduled. Deactivate and reactivate the plugin, or check DISABLE_WP_CRON.'
            );
        }
    }

    /**
     * Output an admin notice.
     */
    private function notice( string $type, string $message ): void {
        printf(
            '<div class="notice %s is-dismissible"><p><strong>License Server:</strong> %s</p></div>',
            esc_attr( $type ),
            esc_html( $message )
        );
    }
}
