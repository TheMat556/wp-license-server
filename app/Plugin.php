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
        $chat_service     = new ChatService( $chat_thread_repo, $chat_message_repo, $activation_repo, $activity_repo );
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
