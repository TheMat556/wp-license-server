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
                sprintf(
                    /* translators: %d: number of pending webhooks */
                    __( 'Webhook queue has %d pending items (threshold: 20). Check webhook dispatcher.', 'wp-license-server' ),
                    $pending
                )
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
                sprintf(
                    /* translators: %d: number of stale pending webhooks */
                    __( '%d pending webhook(s) are older than 24 hours. Investigate cron execution.', 'wp-license-server' ),
                    $stale
                )
            );
        }

        // 3. Failed webhooks in last 7 days (with domain details).
        $failed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE status = %s AND created_at > %s",
            'failed',
            gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
        ) );
        if ( $failed > 0 ) {
            $failed_domains = $wpdb->get_results( $wpdb->prepare(
                "SELECT domain, event, attempts, MAX(created_at) as last_attempt
                 FROM {$queue_table}
                 WHERE status = %s AND created_at > %s
                 GROUP BY domain, event, attempts
                 ORDER BY last_attempt DESC
                 LIMIT 5",
                'failed',
                gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
            ) );

            $detail_lines = array();
            foreach ( $failed_domains as $row ) {
                $detail_lines[] = sprintf(
                    '%s (%s, %d attempt(s))',
                    esc_html( $row->domain ),
                    esc_html( $row->event ),
                    (int) $row->attempts
                );
            }

            $message = sprintf(
                /* translators: %d: number of failed webhooks */
                __( '%d webhook(s) failed in the last 7 days.', 'wp-license-server' ),
                $failed
            );
            if ( ! empty( $detail_lines ) ) {
                $message .= ' <code>' . implode( '</code>, <code>', $detail_lines ) . '</code>';
            }

            $this->notice( 'notice-warning', $message );
        }

        // 4. Expiry cron not scheduled.
        if ( ! wp_next_scheduled( 'wplicense_check_expiry' ) ) {
            $this->notice(
                'notice-error',
                __( 'License expiry cron is NOT scheduled. Deactivate and reactivate the plugin, or check DISABLE_WP_CRON.', 'wp-license-server' )
            );
        }

        // 5. Webhook dispatcher cron not scheduled.
        if ( ! wp_next_scheduled( 'wplicense_dispatch_webhooks' ) ) {
            $this->notice(
                'notice-error',
                __( 'Webhook dispatcher cron is NOT scheduled. Deactivate and reactivate the plugin, or check DISABLE_WP_CRON.', 'wp-license-server' )
            );
        }
    }

    /**
     * Output an admin notice.
     */
    private function notice( string $type, string $message ): void {
        printf(
            '<div class="notice %s is-dismissible"><p><strong>%s:</strong> %s</p></div>',
            esc_attr( $type ),
            esc_html__( 'License Server', 'wp-license-server' ),
            wp_kses_post( $message )
        );
    }
}
