<?php
/**
 * WP-CLI commands for the License Server.
 *
 * Provides subcommands: list, create, status, activate, deactivate, health.
 *
 * @package WpLicenseServer\CLI
 */

declare(strict_types=1);

namespace WpLicenseServer\CLI;

use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Services\LicenseService;
use WpLicenseServer\Services\TierConfig;
use WpLicenseServer\ErrorCodes;

final class LicenseCommand {

    public function __construct(
        private readonly LicenseRepository $license_repo,
        private readonly ActivationRepository $activation_repo,
        private readonly LicenseService $license_service,
    ) {}

    /**
     * Rotates the license key for a license.
     *
     * Generates a new key, keeps the old key valid for 24 hours, and notifies
     * all active sites via webhook.
     *
     * ## OPTIONS
     *
     * <license_id>
     * : The numeric ID of the license.
     *
     * ## EXAMPLES
     *
     *     wp license rotate-key 42
     *
     * @subcommand rotate-key
     * @param array<int, string> $args
     */
    public function rotate_key( array $args ): void {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please provide a license ID.' );
            return;
        }

        $license_id = absint( $args[0] );
        $result     = $this->license_service->rotate_key( $license_id );

        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
            return;
        }

        \WP_CLI::success( sprintf(
            'Key rotated for license #%d. New prefix: %s (version %d).',
            $license_id,
            $result['new_prefix'],
            $result['key_version']
        ) );

        \WP_CLI::log( sprintf( 'New key (store securely — not shown again): %s', $result['new_key'] ) );
        \WP_CLI::log( sprintf( 'Old key valid until: %s', $result['transition_until'] ) );
    }

    /**
     * Lists all licenses.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by status (active, expired, suspended, cancelled).
     *
     * [--format=<format>]
     * : Output format. Default: table.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp license list
     *     wp license list --status=active
     *     wp license list --format=json
     *
     * @subcommand list
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public function list_( array $args, array $assoc_args ): void {
        $status = isset( $assoc_args['status'] ) ? sanitize_text_field( $assoc_args['status'] ) : null;
        $format = $assoc_args['format'] ?? 'table';

        $licenses = $this->license_repo->find_all( $status );

        if ( empty( $licenses ) ) {
            \WP_CLI::warning( 'No licenses found.' );
            return;
        }

        $items = array_map( fn( $l ) => [
            'id'             => $l->id,
            'key_prefix'     => $l->key_prefix,
            'customer_email' => $l->customer_email,
            'role'           => $l->role,
            'tier'           => $l->tier,
            'status'         => $l->status,
            'valid_until'    => $l->valid_until,
        ], $licenses );

        $formatter = new \WP_CLI\Formatter(
            $assoc_args,
            [ 'id', 'key_prefix', 'customer_email', 'role', 'tier', 'status', 'valid_until' ]
        );
        $formatter->display_items( $items );
    }

    /**
     * Creates a new license.
     *
     * ## OPTIONS
     *
     * --email=<email>
     * : Customer email address. Required.
     *
     * --tier=<tier>
     * : License tier. Required.
     * ---
     * options:
     *   - basic
     *   - pro
     *   - agency
     * ---
     *
     * --valid-until=<date>
     * : Expiry date in YYYY-MM-DD format. Required.
     *
     * [--name=<name>]
     * : Customer name.
     *
     * [--role=<role>]
     * : License role.
     * ---
     * default: customer
     * options:
     *   - owner
     *   - customer
     * ---
     *
     * [--interval=<interval>]
     * : Payment interval.
     * ---
     * default: yearly
     * options:
     *   - monthly
     *   - yearly
     * ---
     *
     * ## EXAMPLES
     *
     *     wp license create --email=user@example.com --tier=pro --valid-until=2025-12-31
     *     wp license create --email=agency@corp.com --tier=agency --valid-until=2026-06-01 --name="Corp Inc"
     *
     * @subcommand create
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public function create( array $args, array $assoc_args ): void {
        $data = [
            'customer_email'   => sanitize_email( $assoc_args['email'] ?? '' ),
            'customer_name'    => sanitize_text_field( $assoc_args['name'] ?? '' ),
            'role'             => sanitize_key( $assoc_args['role'] ?? 'customer' ),
            'tier'             => sanitize_text_field( $assoc_args['tier'] ?? '' ),
            'valid_until'      => sanitize_text_field( $assoc_args['valid-until'] ?? '' ),
            'payment_interval' => sanitize_text_field( $assoc_args['interval'] ?? 'yearly' ),
        ];

        $result = $this->license_service->create( $data );

        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
            return;
        }

        \WP_CLI::success( sprintf(
            'License #%d created. Key prefix: %s',
            $result->id,
            $result->key_prefix
        ) );

        // In CLI context, show the full key once for the admin to store.
        \WP_CLI::log( sprintf( 'Full key (store securely — not shown again): %s', $result->license_key ) );
    }

    /**
     * Shows details for a specific license.
     *
     * ## OPTIONS
     *
     * <key_prefix>
     * : The 8-character key prefix of the license.
     *
     * ## EXAMPLES
     *
     *     wp license status ab12cd34
     *
     * @subcommand status
     * @param array<int, string> $args
     */
    public function status( array $args ): void {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please provide a license key prefix.' );
            return;
        }

        $prefix  = sanitize_text_field( $args[0] );
        $license = $this->license_repo->find_by_key_prefix( $prefix );

        if ( is_wp_error( $license ) || ! $license ) {
            \WP_CLI::error( sprintf( 'No license found with prefix "%s".', $prefix ) );
            return;
        }

        $details = [
            'ID'               => $license->id,
            'Key Prefix'       => $license->key_prefix,
            'Customer'         => $license->customer_name,
            'Email'            => $license->customer_email,
            'Role'             => $license->role,
            'Tier'             => $license->tier,
            'Status'           => $license->status,
            'Max Activations'  => $license->max_activations,
            'Payment Interval' => $license->payment_interval,
            'Auto Renewal'     => $license->auto_renewal ? 'Yes' : 'No',
            'Valid Until'      => $license->valid_until,
            'Created'          => $license->created_at,
        ];

        foreach ( $details as $key => $value ) {
            \WP_CLI::log( sprintf( '%-18s %s', $key . ':', $value ) );
        }

        // Active domains.
        $activations = $this->activation_repo->get_all_active( $license->id );
        if ( empty( $activations ) ) {
            \WP_CLI::log( "\nNo active domains." );
            return;
        }

        \WP_CLI::log( sprintf( "\nActive domains (%d):", count( $activations ) ) );

        $items = array_map( fn( $a ) => [
            'domain'         => $a->domain,
            'activated_at'   => $a->activated_at,
            'last_heartbeat' => $a->last_heartbeat ?? '—',
            'plugin_version' => $a->plugin_version ?? '—',
        ], $activations );

        $formatter = new \WP_CLI\Formatter(
            [ 'format' => 'table' ],
            [ 'domain', 'activated_at', 'last_heartbeat', 'plugin_version' ]
        );
        $formatter->display_items( $items );
    }

    /**
     * Activates a license on a domain.
     *
     * ## OPTIONS
     *
     * <key_prefix>
     * : The 8-character key prefix of the license.
     *
     * <domain>
     * : The domain to activate on.
     *
     * ## EXAMPLES
     *
     *     wp license activate ab12cd34 example.com
     *
     * @subcommand activate
     * @param array<int, string> $args
     */
    public function activate( array $args ): void {
        if ( count( $args ) < 2 ) {
            \WP_CLI::error( 'Usage: wp license activate <key_prefix> <domain>' );
            return;
        }

        $prefix = sanitize_text_field( $args[0] );
        $domain = sanitize_text_field( $args[1] );

        $result = $this->license_service->activate( $prefix, $domain );

        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
            return;
        }

        \WP_CLI::success( sprintf( 'License activated on %s.', $domain ) );
    }

    /**
     * Deactivates a license from a domain.
     *
     * ## OPTIONS
     *
     * <key_prefix>
     * : The 8-character key prefix of the license.
     *
     * <domain>
     * : The domain to deactivate.
     *
     * ## EXAMPLES
     *
     *     wp license deactivate ab12cd34 example.com
     *
     * @subcommand deactivate
     * @param array<int, string> $args
     */
    public function deactivate( array $args ): void {
        if ( count( $args ) < 2 ) {
            \WP_CLI::error( 'Usage: wp license deactivate <key_prefix> <domain>' );
            return;
        }

        $prefix = sanitize_text_field( $args[0] );
        $domain = sanitize_text_field( $args[1] );

        $result = $this->license_service->deactivate( $prefix, $domain );

        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
            return;
        }

        \WP_CLI::success( sprintf( 'License deactivated from %s.', $domain ) );
    }

    /**
     * Locks a license, triggering full-site lockdown on the client.
     *
     * ## OPTIONS
     *
     * <license_id>
     * : The numeric ID of the license to lock.
     *
     * ## EXAMPLES
     *
     *     wp license lock 42
     *
     * @subcommand lock
     * @param array<int, string> $args
     */
    public function lock( array $args ): void {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please provide a license ID.' );
            return;
        }

        $license_id = absint( $args[0] );

        // Owner licenses cannot be locked — check before calling the service.
        $license = $this->license_repo->find_by_id( $license_id );
        if ( ! is_wp_error( $license ) && $license && 'owner' === $license->role ) {
            \WP_CLI::error( __( 'Owner licenses cannot be locked.', 'wp-license-server' ) );
            return;
        }

        $result     = $this->license_service->lock( $license_id );

        if ( is_wp_error( $result ) ) {
            if ( in_array( ErrorCodes::LICENSE_NOT_FOUND->value, $result->get_error_codes(), true ) ) {
                \WP_CLI::error( sprintf( 'License #%d not found.', $license_id ) );
            } elseif ( in_array( ErrorCodes::LICENSE_LOCKED->value, $result->get_error_codes(), true ) ) {
                \WP_CLI::warning( sprintf( 'License #%d is already locked.', $license_id ) );
            } else {
                \WP_CLI::error( $result->get_error_message() );
            }
            return;
        }

        \WP_CLI::success( sprintf(
            'License #%d locked. Client site will lock within seconds (webhook) to 1 hour (cache TTL).',
            $license_id
        ) );
    }

    /**
     * Unlocks a license, restoring client site access.
     *
     * ## OPTIONS
     *
     * <license_id>
     * : The numeric ID of the license to unlock.
     *
     * ## EXAMPLES
     *
     *     wp license unlock 42
     *
     * @subcommand unlock
     * @param array<int, string> $args
     */
    public function unlock( array $args ): void {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please provide a license ID.' );
            return;
        }

        $license_id = absint( $args[0] );
        $result     = $this->license_service->unlock( $license_id );

        if ( is_wp_error( $result ) ) {
            if ( in_array( ErrorCodes::LICENSE_NOT_FOUND->value, $result->get_error_codes(), true ) ) {
                \WP_CLI::error( sprintf( 'License #%d not found.', $license_id ) );
            } elseif ( in_array( ErrorCodes::LICENSE_NOT_LOCKED->value, $result->get_error_codes(), true ) ) {
                \WP_CLI::warning( sprintf( 'License #%d is not locked.', $license_id ) );
            } else {
                \WP_CLI::error( $result->get_error_message() );
            }
            return;
        }

        \WP_CLI::success( sprintf(
            'License #%d unlocked — status restored to %s. Client site will restore access on next page load.',
            $license_id,
            $result->status
        ) );
    }

    /**
     * Runs license server health checks.
     *
     * Checks cron scheduling, webhook queue, stale heartbeats, and more.
     *
     * ## EXAMPLES
     *
     *     wp license health
     *
     * @subcommand health
     */
    public function health(): void {
        global $wpdb;

        \WP_CLI::log( 'License Server Health Check' );
        \WP_CLI::log( str_repeat( '─', 40 ) );

        // 1. Expiry cron.
        $expiry_next = wp_next_scheduled( 'wplicense_check_expiry' );
        if ( $expiry_next ) {
            $this->health_ok( sprintf( 'Expiry cron scheduled (next: %s)', gmdate( 'Y-m-d H:i:s', $expiry_next ) ) );
        } else {
            $this->health_fail( 'Expiry cron NOT scheduled' );
        }

        // 2. Webhook dispatcher cron.
        $webhook_next = wp_next_scheduled( 'wplicense_dispatch_webhooks' );
        if ( $webhook_next ) {
            $this->health_ok( sprintf( 'Webhook cron scheduled (next: %s)', gmdate( 'Y-m-d H:i:s', $webhook_next ) ) );
        } else {
            $this->health_fail( 'Webhook dispatcher cron NOT scheduled' );
        }

        // 3. Pending webhooks.
        $table   = $wpdb->prefix . 'license_webhook_queue';
        $pending = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s",
            'pending'
        ) );
        if ( $pending > 20 ) {
            $this->health_warn( sprintf( 'Pending webhooks: %d (above threshold of 20)', $pending ) );
        } else {
            $this->health_ok( sprintf( 'Pending webhooks: %d', $pending ) );
        }

        // 4. Failed webhooks in last 7 days.
        $failed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s AND created_at > %s",
            'failed',
            gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
        ) );
        if ( $failed > 0 ) {
            $this->health_warn( sprintf( 'Failed webhooks (last 7d): %d', $failed ) );
        } else {
            $this->health_ok( 'Failed webhooks (last 7d): 0' );
        }

        // 5. Active licenses.
        $keys_table     = $wpdb->prefix . 'license_keys';
        $active_count   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$keys_table} WHERE status = %s",
            'active'
        ) );
        $this->health_ok( sprintf( 'Active licenses: %d', $active_count ) );

        // 6. Stale heartbeats (> 3 days).
        $activations_table = $wpdb->prefix . 'license_activations';
        $stale = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$activations_table} WHERE deactivated_at IS NULL AND last_heartbeat IS NOT NULL AND last_heartbeat < %s",
            gmdate( 'Y-m-d H:i:s', strtotime( '-3 days' ) )
        ) );
        if ( $stale > 0 ) {
            $this->health_warn( sprintf( 'Stale heartbeats (>3d): %d', $stale ) );
        } else {
            $this->health_ok( 'Stale heartbeats (>3d): 0' );
        }
    }

    private function health_ok( string $message ): void {
        \WP_CLI::log( '  ✓ ' . $message );
    }

    private function health_warn( string $message ): void {
        \WP_CLI::warning( '  ⚠ ' . $message );
    }

    private function health_fail( string $message ): void {
        \WP_CLI::error( '  ✗ ' . $message, false );
    }
}
