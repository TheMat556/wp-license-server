<?php
/**
 * Uninstall handler for WP License Server.
 *
 * Drops all plugin tables and removes plugin options.
 * Only runs when the plugin is explicitly uninstalled via WP admin.
 *
 * @package WpLicenseServer
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop plugin tables in dependency order (foreign-key safe).
$tables = [
    $wpdb->prefix . 'license_webhook_queue',
    $wpdb->prefix . 'license_activity_log',
    $wpdb->prefix . 'license_activations',
    $wpdb->prefix . 'license_keys',
];

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Remove plugin options.
delete_option( 'wp_license_server_db_version' );

// Remove any transients created by the rate limiter.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_rate_limit:' ) . '%',
        $wpdb->esc_like( '_transient_timeout_rate_limit:' ) . '%'
    )
);
