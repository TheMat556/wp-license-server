<?php
/**
 * Tests for schema upgrade checks.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Migrator;
use WpLicenseServer\Database\Schema;

final class MigratorTest extends \WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        Schema::create_tables();
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}license_webhook_queue" );
        parent::tear_down();
    }

    public function test_maybe_upgrade_recreates_webhook_queue_when_db_version_is_old(): void {
        global $wpdb;

        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}license_webhook_queue" );
        update_option( 'wp_license_server_db_version', '1.0.0' );

        ( new Migrator() )->maybe_upgrade();

        $table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'license_webhook_queue' )
        );
        $activation_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}license_activations" );
        $license_columns    = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}license_keys" );

        $this->assertSame( $wpdb->prefix . 'license_webhook_queue', $table_exists );
        $this->assertContains( 'webhook_secret', $activation_columns );
        $this->assertContains( 'role', $license_columns );
        $this->assertSame( WP_LICENSE_SERVER_DB_VERSION, get_option( 'wp_license_server_db_version' ) );
    }
}
