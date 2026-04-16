<?php
/**
 * Plugin Name: WP License Server
 * Plugin URI:  https://example.com/wp-license-server
 * Description: License management server with REST API, HMAC authentication, and WP-CLI support.
 * Version:     1.0.0
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 * Text Domain: wp-license-server
 *
 * @package WpLicenseServer
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_LICENSE_SERVER_VERSION', '1.0.0' );
define( 'WP_LICENSE_SERVER_DB_VERSION', '1.6.0' );
define( 'WP_LICENSE_SERVER_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_LICENSE_SERVER_FILE', __FILE__ );

// Optionally add this to wp-config.php to store the encryption key outside the database:
// define( 'WPLICENSE_ENCRYPTION_KEY', 'your-base64-encoded-32-byte-key-here' );

require_once WP_LICENSE_SERVER_PATH . 'vendor/autoload.php';

// Activation: create tables and generate encryption key if needed.
register_activation_hook( __FILE__, [ \WpLicenseServer\Database\Schema::class, 'create_tables' ] );
register_activation_hook( __FILE__, [ \WpLicenseServer\Services\EncryptionService::class, 'maybe_generate_key' ] );

// Deactivation: unschedule all plugin cron events.
register_deactivation_hook( __FILE__, static function (): void {
    $cron_hooks = [
        'wplicense_check_expiry',
        'wplicense_dispatch_webhooks',
        'wplicense_cleanup_rotation',
    ];
    foreach ( $cron_hooks as $hook ) {
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
    }
} );

// Boot the plugin on plugins_loaded.
add_action( 'plugins_loaded', static function (): void {
    ( new \WpLicenseServer\Plugin() )->init();
} );
