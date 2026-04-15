<?php
/**
 * PHPUnit bootstrap for WP License Server tests.
 *
 * Loads the WordPress test suite and the plugin.
 */

declare(strict_types=1);

// Load Composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Define test encryption key before WordPress loads the plugin.
if ( ! defined( 'WPLICENSE_ENCRYPTION_KEY' ) ) {
	define( 'WPLICENSE_ENCRYPTION_KEY', base64_encode( str_repeat( "\x01", 32 ) ) );
}

// Try to find the WordPress test suite.
$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
    // Fall back to wp-phpunit composer package.
    $wp_tests_dir = __DIR__ . '/../vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
    throw new RuntimeException(
        'WordPress test suite not found. Set WP_TESTS_DIR or install wp-phpunit/wp-phpunit.'
    );
}

// Load WordPress test functions.
require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Load the plugin during tests_add_filter('muplugins_loaded', ...).
 */
tests_add_filter( 'muplugins_loaded', function (): void {
    require __DIR__ . '/../wp-license-server.php';
} );

// Start the WordPress test environment.
require $wp_tests_dir . '/includes/bootstrap.php';
