<?php
/**
 * Database schema creation via dbDelta.
 *
 * @package WpLicenseServer\Database
 */

declare(strict_types=1);

namespace WpLicenseServer\Database;

final class Schema {

    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        $sql = "CREATE TABLE {$prefix}license_keys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key VARCHAR(512) NOT NULL,
            key_prefix CHAR(8) NOT NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            role ENUM('owner','customer') NOT NULL DEFAULT 'customer',
            tier ENUM('basic','pro','agency') NOT NULL,
            status ENUM('active','expired','suspended','cancelled') NOT NULL DEFAULT 'active',
            max_activations SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            payment_interval ENUM('monthly','yearly') NOT NULL,
            auto_renewal TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT,
            key_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
            previous_key_encrypted TEXT NULL,
            previous_key_prefix CHAR(8) NULL,
            rotation_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            valid_until DATETIME NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY license_key (license_key),
            UNIQUE KEY key_prefix (key_prefix),
            KEY customer_email (customer_email),
            KEY status_valid (status, valid_until)
        ) ENGINE=InnoDB {$charset};

        CREATE TABLE {$prefix}license_activations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT UNSIGNED NOT NULL,
            domain VARCHAR(255) NOT NULL,
            activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_heartbeat DATETIME NULL,
            plugin_version VARCHAR(20) NULL,
            wp_version VARCHAR(20) NULL,
            php_version VARCHAR(20) NULL,
            deactivated_at DATETIME NULL,
            webhook_secret TEXT NULL,
            previous_webhook_secret TEXT NULL,
            webhook_secret_rotated_at DATETIME NULL,
            webhook_secret_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY license_domain (license_id, domain),
            KEY domain (domain),
            KEY last_heartbeat (last_heartbeat)
        ) ENGINE=InnoDB {$charset};

        CREATE TABLE {$prefix}license_activity_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            domain VARCHAR(255) NULL,
            actor VARCHAR(255) NULL,
            details JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY license_created (license_id, created_at)
        ) ENGINE=InnoDB {$charset};

        CREATE TABLE {$prefix}license_webhook_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT UNSIGNED NOT NULL,
            domain VARCHAR(255) NOT NULL,
            webhook_secret CHAR(32) NOT NULL,
            event VARCHAR(50) NOT NULL,
            event_id VARCHAR(64) NOT NULL DEFAULT '',
            payload JSON NOT NULL,
            status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_attempt DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_event_id (event_id),
            KEY license_status (license_id, status),
            KEY status_attempt (status, last_attempt)
        ) ENGINE=InnoDB {$charset};

        CREATE TABLE {$prefix}license_chat_threads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT UNSIGNED NOT NULL,
            domain VARCHAR(255) NOT NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            last_message_preview TEXT NULL,
            last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY license_domain (license_id, domain),
            KEY status_last_message (status, last_message_at),
            KEY domain (domain)
        ) ENGINE=InnoDB {$charset};

        CREATE TABLE {$prefix}license_chat_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            author_role ENUM('owner','customer','system') NOT NULL,
            author_name VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY thread_created (thread_id, created_at),
            KEY thread_id_id (thread_id, id)
        ) ENGINE=InnoDB {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'wp_license_server_db_version', WP_LICENSE_SERVER_DB_VERSION );
    }
}
