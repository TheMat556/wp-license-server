<?php
/**
 * Schema version check and upgrade trigger.
 *
 * Runs on plugins_loaded to catch version mismatches (e.g., after manual file update).
 *
 * @package WpLicenseServer\Database
 */

declare(strict_types=1);

namespace WpLicenseServer\Database;

final class Migrator {

    public function maybe_upgrade(): void {
        $installed = get_option( 'wp_license_server_db_version', '0' );

        if ( version_compare( $installed, WP_LICENSE_SERVER_DB_VERSION, '<' ) ) {
            if ( version_compare( $installed, '1.6.0', '<' ) ) {
                $this->migrate_to_1_6_0();
            }

            if ( version_compare( $installed, '1.7.0', '<' ) ) {
                $this->migrate_to_1_7_0();
            }

            Schema::create_tables();
        }
    }

    /**
     * v1.7.0: Change status from ENUM to VARCHAR and add pre_lock_status column.
     *
     * - ENUM('active','expired','suspended','cancelled') → VARCHAR(20)
     * - Allows 'locked' as a valid status value
     * - Adds pre_lock_status column for unlocking restoration
     */
    private function migrate_to_1_7_0(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'license_keys';

        // Step 1: Change status from ENUM to VARCHAR(20)
        $col = $wpdb->get_row(
            $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'status' )
        );
        if ( $col && str_contains( strtolower( (string) $col->Type ), 'enum' ) ) {
            $wpdb->query(
                "ALTER TABLE `{$table}` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active'"
            );
        }

        // Step 2: Add pre_lock_status column
        if ( ! $wpdb->get_row(
            $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'pre_lock_status' )
        ) ) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN `pre_lock_status` VARCHAR(20) DEFAULT NULL AFTER `status`"
            );
        }
    }

    /**
     * Alter license_activations for webhook secret rotation (v1.6.0).
     *
     * - Widens webhook_secret to TEXT (encrypted values exceed CHAR(32)).
     * - Adds previous_webhook_secret, webhook_secret_rotated_at, webhook_secret_version.
     */
    private function migrate_to_1_6_0(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'license_activations';

        // Widen webhook_secret to TEXT if it is still CHAR(32).
        $col = $wpdb->get_row(
            $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'webhook_secret' )
        );
        if ( $col && strtolower( (string) $col->Type ) !== 'text' ) {
            $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `webhook_secret` TEXT NULL" );
        }

        // Add previous_webhook_secret if absent.
        if ( ! $wpdb->get_row(
            $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'previous_webhook_secret' )
        ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `previous_webhook_secret` TEXT NULL AFTER `webhook_secret`" );
        }

        // Add webhook_secret_rotated_at if absent.
        if ( ! $wpdb->get_row(
            $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'webhook_secret_rotated_at' )
        ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `webhook_secret_rotated_at` DATETIME NULL AFTER `previous_webhook_secret`" );
        }

        // Add webhook_secret_version if absent.
        if ( ! $wpdb->get_row(
            $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'webhook_secret_version' )
        ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `webhook_secret_version` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `webhook_secret_rotated_at`" );
        }
    }
}
