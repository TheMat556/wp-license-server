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

        // Encryption service — abort plugin load if key is missing.
        try {
            $encryption = new EncryptionService();
        } catch ( \RuntimeException $e ) {
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
            add_action( 'admin_notices', static function (): void {
                $key = (string) get_option( EncryptionService::OPTION_KEY, '' );
                if ( $key === '' ) {
                    return;
                }

                $status = isset( $_GET['wplicense_wpconfig'] ) ? sanitize_key( $_GET['wplicense_wpconfig'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                if ( 'success' === $status ) {
                    echo '<div class="notice notice-success is-dismissible"><p><strong>WP License Server:</strong> Encryption key added to <code>wp-config.php</code> successfully. This notice will disappear on the next page load.</p></div>';
                    return;
                }

                $error_messages = [
                    'not_found'      => 'wp-config.php could not be found.',
                    'not_writable'   => 'wp-config.php is not writable by the web server. Please add the line manually.',
                    'read_error'     => 'Could not read wp-config.php. Please add the line manually.',
                    'write_error'    => 'Could not write to wp-config.php. Please add the line manually.',
                    'marker_not_found' => 'Could not find the right location in wp-config.php. Please add the line manually.',
                ];
                if ( isset( $error_messages[ $status ] ) ) {
                    echo '<div class="notice notice-error is-dismissible"><p><strong>WP License Server:</strong> ' . esc_html( $error_messages[ $status ] ) . '</p></div>';
                }

                $nonce = wp_create_nonce( 'wplicense_add_to_wpconfig' );
                ?>
                <div class="notice notice-warning" id="wplicense-key-setup-notice" style="border-left-color:#f0ad4e">
                    <p>
                        <strong>WP License Server — Encryption Key Setup</strong><br>
                        Your encryption key is currently stored in the database.
                        For production security, add the following line to <code>wp-config.php</code>
                        and the notice will disappear automatically:
                    </p>
                    <pre style="background:#f6f7f7;border:1px solid #ddd;padding:10px 14px;border-radius:4px;overflow-x:auto;font-size:13px;margin:8px 0">define( 'WPLICENSE_ENCRYPTION_KEY', '<?php echo esc_html( $key ); ?>' );</pre>
                    <p style="color:#555;font-size:13px">The database key will continue to be used as a fallback until then.</p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:10px 0 4px">
                        <input type="hidden" name="action" value="wplicense_add_key_to_wpconfig">
                        <?php wp_nonce_field( 'wplicense_add_to_wpconfig', '_wpnonce', false ); ?>
                        <button type="submit" class="button button-primary">Auto-add to wp-config.php</button>
                        <span style="margin-left:10px;color:#666;font-size:12px">WordPress will insert the line before the "stop editing" comment.</span>
                    </form>
                </div>
                <?php
            } );

            // Handle the form submission.
            add_action( 'admin_post_wplicense_add_key_to_wpconfig', static function (): void {
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_die( 'Unauthorized', 403 );
                }

                check_admin_referer( 'wplicense_add_to_wpconfig' );

                $key = (string) get_option( EncryptionService::OPTION_KEY, '' );
                $referer = wp_get_referer() ?: admin_url();

                if ( $key === '' ) {
                    wp_safe_redirect( add_query_arg( 'wplicense_wpconfig', 'no_key', $referer ) );
                    exit;
                }

                // Locate wp-config.php (standard location or one level up).
                $config_path = ABSPATH . 'wp-config.php';
                if ( ! file_exists( $config_path ) ) {
                    $config_path = dirname( ABSPATH ) . '/wp-config.php';
                }

                if ( ! file_exists( $config_path ) ) {
                    wp_safe_redirect( add_query_arg( 'wplicense_wpconfig', 'not_found', $referer ) );
                    exit;
                }

                if ( ! is_writable( $config_path ) ) {
                    wp_safe_redirect( add_query_arg( 'wplicense_wpconfig', 'not_writable', $referer ) );
                    exit;
                }

                $content = file_get_contents( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                if ( $content === false ) {
                    wp_safe_redirect( add_query_arg( 'wplicense_wpconfig', 'read_error', $referer ) );
                    exit;
                }

                // Already defined — nothing to do.
                if ( strpos( $content, 'WPLICENSE_ENCRYPTION_KEY' ) !== false ) {
                    wp_safe_redirect( add_query_arg( 'wplicense_wpconfig', 'success', $referer ) );
                    exit;
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
                    wp_safe_redirect( add_query_arg( 'wplicense_wpconfig', 'marker_not_found', $referer ) );
                    exit;
                }

                $define_line = "\ndefine( 'WPLICENSE_ENCRYPTION_KEY', '" . addslashes( $key ) . "' );\n";
                $new_content = substr( $content, 0, $pos ) . $define_line . substr( $content, $pos );

                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                if ( file_put_contents( $config_path, $new_content ) === false ) {
                    wp_safe_redirect( add_query_arg( 'wplicense_wpconfig', 'write_error', $referer ) );
                    exit;
                }

                wp_safe_redirect( add_query_arg( 'wplicense_wpconfig', 'success', $referer ) );
                exit;
            } );
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
}
