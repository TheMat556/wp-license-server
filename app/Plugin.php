<?php
/**
 * Composition root for WP License Server.
 *
 * @package WpLicenseServer
 */

declare(strict_types=1);

namespace WpLicenseServer;

use WpLicenseServer\Admin\AdminPage;
use WpLicenseServer\Admin\LicenseHealthMonitor;
use WpLicenseServer\Bootstrap\PluginBootstrap;
use WpLicenseServer\Database\Migrator;
use WpLicenseServer\Domain\LicenseStateMachine;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\ChatMessageRepository;
use WpLicenseServer\Repositories\ChatThreadRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Repositories\WebhookQueueRepository;
use WpLicenseServer\Rest\Middleware\RateLimiter;
use WpLicenseServer\Rest\Middleware\FeatureGate;
use WpLicenseServer\Rest\RestApi;
use WpLicenseServer\Rest\Services\LicenseSettingsService;
use WpLicenseServer\Services\ExpiryService;
use WpLicenseServer\Services\ChatService;
use WpLicenseServer\Services\HmacVerifier;
use WpLicenseServer\Services\LicenseService;
use WpLicenseServer\Services\NotificationService;
use WpLicenseServer\Services\WebhookDispatcher;
use WpLicenseServer\Services\WebhookRetrySchedule;
use WpLicenseServer\Services\WebhookService;
use WpLicenseServer\Services\WebhookTargetValidator;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\KeyDerivationService;
use WpLicenseServer\CLI\LicenseCommand;
use WpLicenseServer\CLI\MigrateEncryptionCommand;

final class Plugin {

    public function init(): void {
        global $wpdb;

        // Load plugin textdomain for i18n.
        load_plugin_textdomain( 'wp-license-server', false, dirname( WP_LICENSE_SERVER_BASENAME ) . '/languages' );

        // Key setup wizard — intercept early if plugin was just activated
        // and no encryption key has been configured yet.
        if ( EncryptionService::is_key_setup_pending() ) {
            if ( ! defined( 'WPLICENSE_ENCRYPTION_KEY' ) && ! get_option( EncryptionService::OPTION_KEY ) ) {
                $this->register_key_setup_wizard();
                return;
            }
            // Key already exists (constant or DB), no wizard needed.
            EncryptionService::clear_key_setup_pending();
        }

        // Encryption service — abort plugin load if key is missing.
        try {
            $encryption = new EncryptionService();
        } catch ( \RuntimeException $e ) {
            $has_constant = defined( 'WPLICENSE_ENCRYPTION_KEY' );
            $has_db_key   = (bool) get_option( EncryptionService::OPTION_KEY, '' );

            if ( ! $has_constant && ! $has_db_key ) {
                // No key exists anywhere — show the setup wizard so the user
                // can generate one and have it auto-written to wp-config.php.
                $this->register_key_setup_wizard();
                return;
            }

            if ( ! $has_constant && $has_db_key ) {
                // Key exists in DB but production mode won't accept it.
                // Show a combined error + the existing "move to wp-config.php" UI.
                $this->register_db_key_migration_notice( $e );
                return;
            }

            // Constant is defined but invalid (wrong length, bad encoding, etc.).
            add_action( 'admin_notices', static function () use ( $e ): void {
                printf(
                    '<div class="notice notice-error"><p><strong>WP License Server:</strong> %s</p></div>',
                    esc_html( $e->getMessage() )
                );
            } );
            return;
        }

        // Show a persistent notice when the key lives in the database (not in wp-config.php).
        if ( EncryptionService::get_key_source() === 'database' ) {
            $this->register_db_key_notice();
        }

        // Run migrations if DB version has changed.
        ( new Migrator() )->maybe_upgrade();

        // Repositories.
        $license_repo    = new LicenseRepository( $wpdb, $encryption );
        $activation_repo = new ActivationRepository( $wpdb, $encryption );
        $activity_repo   = new ActivityLogRepository( $wpdb );
        $chat_thread_repo = new ChatThreadRepository( $wpdb );
        $chat_message_repo = new ChatMessageRepository( $wpdb );
        $webhook_queue_repo = new WebhookQueueRepository( $wpdb );

        // Services.
        $rate_limiter     = new RateLimiter();
        $feature_gate     = new FeatureGate();
        $key_derivation   = new KeyDerivationService();
        $hmac_verifier    = new HmacVerifier( $license_repo, $rate_limiter, $encryption, $key_derivation );
        $target_validator = new WebhookTargetValidator();
        $state_machine    = new LicenseStateMachine();
        $chat_service     = new ChatService( $wpdb, $chat_thread_repo, $chat_message_repo, $activation_repo, $activity_repo );
        $webhook_service  = new WebhookService( $license_repo, $activation_repo, $webhook_queue_repo );
        $notification_service = new NotificationService( $webhook_service );
        $license_service  = new LicenseService( $wpdb, $license_repo, $activation_repo, $activity_repo, $target_validator, $webhook_service, $state_machine, $notification_service );
        $webhook_dispatcher = new WebhookDispatcher(
            $webhook_queue_repo,
            $license_repo,
            $activity_repo,
            new WebhookRetrySchedule(),
            $key_derivation,
            null,
            $target_validator
        );

        // Wire subsystems via bootstrap.
        $bootstrap = new PluginBootstrap(
            new RestApi( $rate_limiter, $hmac_verifier, $chat_service, $license_service, $license_repo, $activation_repo, new LicenseSettingsService( $license_repo ), $feature_gate ),
            new AdminPage( $license_repo, $activation_repo, $license_service ),
            new LicenseHealthMonitor(),
            new ExpiryService( $license_repo, $activity_repo, $webhook_service, $state_machine ),
            $webhook_dispatcher,
        );
        $bootstrap->register();

        // Register key rotation cleanup cron handler.
        add_action( 'wplicense_cleanup_rotation', [ $license_service, 'cleanup_rotation' ] );

        if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
            \WP_CLI::add_command(
                'license',
                new LicenseCommand( $license_repo, $activation_repo, $license_service )
            );
            \WP_CLI::add_command(
                'license-server migrate-encryption',
                new MigrateEncryptionCommand( $encryption )
            );
        }
    }

    /**
     * Register the key setup wizard — shown after activation when no key is configured.
	 *
	 * Displays a YES/NO admin notice and handles both responses.
     */
    private function register_key_setup_wizard(): void {
        add_action( 'admin_notices', static function (): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended — display-only; validated against strict whitelist below.
            $raw_status = isset( $_GET['wplicense_setup'] ) ? sanitize_key( $_GET['wplicense_setup'] ) : '';
            $status     = in_array( $raw_status, [ 'success', 'not_found', 'not_writable', 'read_error', 'write_error', 'marker_not_found', 'backup_failed', 'invalid_key', 'dir_not_writable', 'no' ], true ) ? $raw_status : '';

            if ( 'success' === $status ) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>WP License Server:</strong> ' . esc_html__( 'Encryption key generated and added to wp-config.php successfully.', 'wp-license-server' ) . '</p></div>';
                return;
            }

            if ( 'no' === $status ) {
                echo '<div class="notice notice-info is-dismissible"><p><strong>WP License Server:</strong> ' . sprintf(
                    /* translators: %1$s: opening anchor tag to setup guide, %2$s: closing anchor tag */
                    esc_html__( 'Setup skipped. The plugin will not function until WPLICENSE_ENCRYPTION_KEY is defined. See the %1$ssetup guide%2$s for manual instructions.', 'wp-license-server' ),
                    // TODO: Replace with actual documentation URL
                    '<a href="#">',
                    '</a>'
                ) . '</p></div>';
                return;
            }

            $error_messages = [
                'not_found'        => __( 'wp-config.php could not be found.', 'wp-license-server' ),
                'not_writable'     => __( 'wp-config.php is not writable by the web server.', 'wp-license-server' ),
                'read_error'       => __( 'Could not read wp-config.php.', 'wp-license-server' ),
                'write_error'      => __( 'Could not write to wp-config.php.', 'wp-license-server' ),
                'marker_not_found' => __( 'Could not find the right location in wp-config.php.', 'wp-license-server' ),
                'backup_failed'    => __( 'Could not create wp-config.php backup. No changes were made.', 'wp-license-server' ),
                'invalid_key'      => __( 'Generated key contains unexpected characters. This should not happen.', 'wp-license-server' ),
                'dir_not_writable' => __( 'The wp-config.php directory is not writable for atomic replace.', 'wp-license-server' ),
            ];
            if ( isset( $error_messages[ $status ] ) ) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>WP License Server:</strong> ' . esc_html( $error_messages[ $status ] ) . ' ' . esc_html__( 'The key was stored in the database instead — use the "Auto-add to wp-config.php" button below when the file is writable.', 'wp-license-server' ) . '</p></div>';
            }

            ?>
            <div class="notice notice-warning" style="border-left-color:#f0ad4e">
                <p>
                    <strong><?php esc_html_e( 'Welcome to WP License Server — Encryption Key Setup', 'wp-license-server' ); ?></strong>
                </p>
                <p style="font-size:13px;color:#555;margin:4px 0 0">
                    <?php esc_html_e( 'This plugin needs an encryption key to secure your license data. Would you like the system to generate one and add it to your wp-config.php file automatically?', 'wp-license-server' ); ?>
                </p>
                <p style="margin:12px 0 4px">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                        <input type="hidden" name="action" value="wplicense_key_setup_yes">
                        <?php wp_nonce_field( 'wplicense_key_setup_yes', '_wpnonce', false ); ?>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Yes, generate and secure my key', 'wp-license-server' ); ?></button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px">
                        <input type="hidden" name="action" value="wplicense_key_setup_no">
                        <?php wp_nonce_field( 'wplicense_key_setup_no', '_wpnonce', false ); ?>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'No, I\'ll do it manually', 'wp-license-server' ); ?></button>
                    </form>
                </p>
                <p style="color:#888;font-size:12px;margin:8px 0 0">
                    <?php esc_html_e( 'If you choose "No", the plugin will not be able to boot until WPLICENSE_ENCRYPTION_KEY is defined in your wp-config.php.', 'wp-license-server' ); ?>
                </p>
            </div>
            <?php
        } );

        // Handle "Yes" — generate key and write to wp-config.php.
        add_action( 'admin_post_wplicense_key_setup_yes', static function (): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'wp-license-server' ), 403 );
            }

            check_admin_referer( 'wplicense_key_setup_yes' );

            $referer = wp_get_referer() ?: admin_url();

            // Generate a fresh key.
            $raw = sodium_crypto_secretbox_keygen();
            $key = base64_encode( $raw );

            // Try to write directly to wp-config.php.
            $status = self::write_key_to_wpconfig( $key );

            if ( 'success' === $status ) {
                // Key is now a constant — no need for DB fallback.
                EncryptionService::clear_key_setup_pending();
                wp_safe_redirect( add_query_arg( 'wplicense_setup', 'success', $referer ) );
                exit;
            }

            // Writing failed — save in DB as fallback so the plugin can function.
            update_option( EncryptionService::OPTION_KEY, $key, false );
            EncryptionService::clear_key_setup_pending();
            wp_safe_redirect( add_query_arg( 'wplicense_setup', $status, $referer ) );
            exit;
        } );

        // Handle "No" — clear the pending flag, no key is generated.
        // @phpstan-ignore-next-line — exit inside closure confuses dead code analysis; the exit only runs when the action fires.
        add_action( 'admin_post_wplicense_key_setup_no', static function (): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'wp-license-server' ), 403 );
            }

            check_admin_referer( 'wplicense_key_setup_no' );

            EncryptionService::clear_key_setup_pending();

            $referer = wp_get_referer() ?: admin_url();
            wp_safe_redirect( add_query_arg( 'wplicense_setup', 'no', $referer ) );
            exit;
        } );
    }

    /**
     * Register the "key in database, move to wp-config.php" admin notice
     * with copy-to-clipboard and auto-add-to-wp-config buttons.
     */
    private function register_db_key_notice(): void {
        add_action( 'admin_notices', static function (): void {
            $key = (string) get_option( EncryptionService::OPTION_KEY, '' );
            if ( $key === '' ) {
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended — status param is display-only; validated against strict whitelist below.
            $raw_status = isset( $_GET['wplicense_wpconfig'] ) ? sanitize_key( $_GET['wplicense_wpconfig'] ) : '';
            $status      = in_array( $raw_status, [ 'success', 'not_found', 'not_writable', 'read_error', 'write_error', 'marker_not_found' ], true ) ? $raw_status : '';
            if ( 'success' === $status ) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>WP License Server:</strong> ' . esc_html__( 'Encryption key added to wp-config.php successfully. This notice will disappear on the next page load.', 'wp-license-server' ) . '</p></div>';
                return;
            }

            $error_messages = [
                'not_found'        => __( 'wp-config.php could not be found.', 'wp-license-server' ),
                'not_writable'     => __( 'wp-config.php is not writable by the web server. Please add the line manually.', 'wp-license-server' ),
                'read_error'       => __( 'Could not read wp-config.php. Please add the line manually.', 'wp-license-server' ),
                'write_error'      => __( 'Could not write to wp-config.php. Please add the line manually.', 'wp-license-server' ),
                'marker_not_found' => __( 'Could not find the right location in wp-config.php. Please add the line manually.', 'wp-license-server' ),
                'backup_failed'    => __( 'Could not create wp-config.php backup. Aborted to protect your site. Please add the line manually.', 'wp-license-server' ),
                'invalid_key'      => __( 'Stored encryption key contains unexpected characters. Aborted to avoid corrupting wp-config.php.', 'wp-license-server' ),
                'dir_not_writable' => __( 'wp-config.php directory is not writable for atomic replace. Please add the line manually.', 'wp-license-server' ),
            ];
            if ( isset( $error_messages[ $status ] ) ) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>WP License Server:</strong> ' . esc_html( $error_messages[ $status ] ) . '</p></div>';
            }

            $rest_url = rest_url( 'license-server/v1/admin/encryption-key' );
            $nonce    = wp_create_nonce( 'wplicense_add_to_wpconfig' );

            // Enqueue the external JS for the copy-button (CSP-safe, no inline handlers).
            wp_enqueue_script(
                'wplicense-key-setup',
                plugin_dir_url( WP_LICENSE_SERVER_FILE ) . 'src/admin/assets/keySetup.js',
                array(),
                '1.0.0',
                true
            );
            wp_localize_script( 'wplicense-key-setup', 'wplicenseKeySetup', array(
                'restUrl' => $rest_url,
                'nonce'   => wp_create_nonce( 'wp_rest' ),
            ) );
            ?>
            <div class="notice notice-warning" id="wplicense-key-setup-notice" style="border-left-color:#f0ad4e">
                <p>
                    <strong><?php esc_html_e( 'WP License Server — Encryption Key Setup', 'wp-license-server' ); ?></strong><br>
                    <?php esc_html_e( 'Your encryption key is currently stored in the database. For production security, move it to wp-config.php.', 'wp-license-server' ); ?>
                </p>
                <p style="font-size:13px;color:#666;margin:4px 0 0">
                    <button id="wplicense-copy-key" class="button button-secondary"><?php esc_html_e( 'Copy Key to Clipboard', 'wp-license-server' ); ?></button>
                    <span style="margin-left:10px;color:#666;font-size:12px"><?php esc_html_e( 'Then add', 'wp-license-server' ); ?> <code>define( 'WPLICENSE_ENCRYPTION_KEY', '&lt;key&gt;' );</code> <?php esc_html_e( 'to wp-config.php', 'wp-license-server' ); ?></span>
                </p>
                <p style="color:#555;font-size:13px"><?php esc_html_e( 'The database key will continue to be used as a fallback until then.', 'wp-license-server' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:10px 0 4px">
                    <input type="hidden" name="action" value="wplicense_add_key_to_wpconfig">
                    <?php wp_nonce_field( 'wplicense_add_to_wpconfig', '_wpnonce', false ); ?>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Auto-add to wp-config.php', 'wp-license-server' ); ?></button>
                    <span style="margin-left:10px;color:#666;font-size:12px"><?php esc_html_e( 'WordPress will insert the line before the "stop editing" comment.', 'wp-license-server' ); ?></span>
                </form>
            </div>
            <?php
        } );

        // Handle the form submission.
        add_action( 'admin_post_wplicense_add_key_to_wpconfig', static function (): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'wp-license-server' ), 403 );
            }

            check_admin_referer( 'wplicense_add_to_wpconfig' );

            $key     = (string) get_option( EncryptionService::OPTION_KEY, '' );
            $referer = wp_get_referer() ?: admin_url();

            if ( $key === '' ) {
                wp_safe_redirect( add_query_arg( 'wplicense_wpconfig', 'no_key', $referer ) );
                exit;
            }

            $status = self::write_key_to_wpconfig( $key );
            wp_safe_redirect( add_query_arg( 'wplicense_wpconfig', $status, $referer ) );
            exit;
        } );
    }

    /**
     * Register a combined error + migration notice for when a DB key exists
     * but production mode rejects it.
     */
    private function register_db_key_migration_notice( \RuntimeException $e ): void {
        // Show the error banner.
        add_action( 'admin_notices', static function () use ( $e ): void {
            printf(
                '<div class="notice notice-error"><p><strong>WP License Server:</strong> %s</p></div>',
                esc_html( $e->getMessage() )
            );
        } );

        // Show the "move to wp-config.php" UI with copy + auto-add form.
        $this->register_db_key_notice();
    }

    /**
     * Write the encryption key define to wp-config.php.
     *
     * Uses atomic write with a backup for safety. Returns a status string:
     * 'success', 'not_found', 'not_writable', 'read_error', 'write_error',
     * 'marker_not_found', 'backup_failed', 'invalid_key', 'dir_not_writable'.
     */
    private static function write_key_to_wpconfig( string $key ): string {
        // Locate wp-config.php (standard location or one level up).
        $config_path = ABSPATH . 'wp-config.php';
        if ( ! file_exists( $config_path ) ) {
            $config_path = dirname( ABSPATH ) . '/wp-config.php';
        }

        if ( ! file_exists( $config_path ) ) {
            return 'not_found';
        }

        if ( ! is_writable( $config_path ) ) {
            return 'not_writable';
        }

        $content = file_get_contents( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( $content === false ) {
            return 'read_error';
        }

        // Already defined — nothing to do.
        if ( strpos( $content, 'WPLICENSE_ENCRYPTION_KEY' ) !== false ) {
            return 'success';
        }

        // Validate key charset — base64 only. Refuse to write anything else into wp-config.php.
        if ( ! preg_match( '#^[A-Za-z0-9+/=]+$#', $key ) ) {
            return 'invalid_key';
        }

        // Find the best insertion point (before the stop-editing marker).
        $marker = "/* That's all, stop editing!";
        $pos    = strpos( $content, $marker );
        if ( $pos === false ) {
            $marker = '/* That\'s all, stop editing!';
            $pos    = strpos( $content, $marker );
        }
        if ( $pos === false ) {
            $marker = '/** Absolute path to the WordPress directory';
            $pos    = strpos( $content, $marker );
        }
        if ( $pos === false ) {
            return 'marker_not_found';
        }

        $define_line = "\ndefine( 'WPLICENSE_ENCRYPTION_KEY', '" . $key . "' );\n";
        $new_content = substr( $content, 0, $pos ) . $define_line . substr( $content, $pos );

        $config_dir = dirname( $config_path );
        if ( ! is_writable( $config_dir ) ) {
            return 'dir_not_writable';
        }

        // Backup before touching the file. Timestamped, kept on disk.
        $backup_path = $config_path . '.wplicense-bak-' . time();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( file_put_contents( $backup_path, $content, LOCK_EX ) === false ) {
            return 'backup_failed';
        }

        // Atomic write: tmp file in same directory + rename. Avoids torn writes that would
        // brick the site if the request is interrupted mid-write.
        $tmp_path = $config_path . '.wplicense-tmp-' . bin2hex( random_bytes( 8 ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( file_put_contents( $tmp_path, $new_content, LOCK_EX ) === false ) {
            @unlink( $tmp_path );
            return 'write_error';
        }

        // Preserve original permissions where possible.
        $perms = @fileperms( $config_path );
        if ( false !== $perms ) {
            @chmod( $tmp_path, $perms & 0777 );
        }

        if ( ! @rename( $tmp_path, $config_path ) ) {
            @unlink( $tmp_path );
            return 'write_error';
        }

        return 'success';
    }
}
