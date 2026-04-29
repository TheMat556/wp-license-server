<?php
/**
 * REST API route registration.
 *
 * All license API endpoints are public (permission_callback = __return_true)
 * because authentication is handled via HMAC signature verification.
 *
 * @package WpLicenseServer\Rest
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest;

use WpLicenseServer\Rest\Controllers\ActivateController;
use WpLicenseServer\Rest\Controllers\AdminLicensesController;
use WpLicenseServer\Rest\Controllers\AdminSettingsController;
use WpLicenseServer\Rest\Controllers\ChatArchiveController;
use WpLicenseServer\Rest\Controllers\ChatBootstrapController;
use WpLicenseServer\Rest\Controllers\ChatDeleteController;
use WpLicenseServer\Rest\Controllers\ChatPollController;
use WpLicenseServer\Rest\Controllers\ChatSendController;
use WpLicenseServer\Rest\Controllers\ChatUnarchiveController;
use WpLicenseServer\Rest\Controllers\DeactivateController;
use WpLicenseServer\Rest\Controllers\WebhookReceiverController;
use WpLicenseServer\Rest\Controllers\RotateKeyController;
use WpLicenseServer\Rest\Controllers\ValidateController;
use WpLicenseServer\Rest\Middleware\FeatureGate;
use WpLicenseServer\Rest\Middleware\RateLimiter;
use WpLicenseServer\Rest\Services\LicenseSettingsService;
use WpLicenseServer\Services\ChatService;
use WpLicenseServer\Services\HmacVerifier;
use WpLicenseServer\Services\KeyDerivationService;
use WpLicenseServer\Services\LicenseService;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Services\EncryptionService;

final class RestApi {

    private const NAMESPACE = 'license-server/v1';

    public function __construct(
        private readonly RateLimiter $rate_limiter,
        private readonly HmacVerifier $hmac_verifier,
        private readonly ChatService $chat_service,
        private readonly LicenseService $license_service,
        private readonly LicenseRepository $license_repo,
        private readonly ActivationRepository $activation_repo,
        private readonly LicenseSettingsService $settings_service,
        private readonly FeatureGate $feature_gate,
    ) {}

    /**
     * Hooked to rest_api_init.
     */
    public function register_routes(): void {
        $activate   = new ActivateController( $this->rate_limiter, $this->hmac_verifier, $this->license_service );
        $chat_archive = new ChatArchiveController( $this->rate_limiter, $this->hmac_verifier, $this->chat_service, $this->feature_gate );
        $chat_bootstrap = new ChatBootstrapController( $this->rate_limiter, $this->hmac_verifier, $this->chat_service, $this->feature_gate );
        $chat_delete = new ChatDeleteController( $this->rate_limiter, $this->hmac_verifier, $this->chat_service, $this->feature_gate );
        $chat_poll = new ChatPollController( $this->rate_limiter, $this->hmac_verifier, $this->chat_service, $this->feature_gate );
        $chat_send = new ChatSendController( $this->rate_limiter, $this->hmac_verifier, $this->chat_service, $this->feature_gate );
        $chat_unarchive = new ChatUnarchiveController( $this->rate_limiter, $this->hmac_verifier, $this->chat_service, $this->feature_gate );
        $validate   = new ValidateController( $this->rate_limiter, $this->hmac_verifier, $this->license_service );
        $deactivate = new DeactivateController( $this->rate_limiter, $this->hmac_verifier, $this->license_service );
        $admin      = new AdminLicensesController( $this->license_repo, $this->activation_repo, $this->license_service );
        $settings   = new AdminSettingsController( $this->settings_service );

        // Require HMAC auth headers on public endpoints. Must match HmacVerifier::verify()
        // so the permission callback rejects malformed requests at the same layer the
        // verifier checks them.
        $hmac_required = static function (): bool {
            $headers = array(
                'X-License-Key-Id',
                'X-License-Domain',
                'X-License-Timestamp',
                'X-License-Signature',
            );
            foreach ( $headers as $header ) {
                if ( empty( $_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) ) ] ?? '' ) ) {
                    return false;
                }
            }
            return true;
        };

        register_rest_route( self::NAMESPACE, '/activate', [
            'methods'             => 'POST',
            'callback'            => [ $activate, 'handle' ],
            'permission_callback' => $hmac_required,
        ] );

        register_rest_route( self::NAMESPACE, '/validate', [
            'methods'             => 'POST',
            'callback'            => [ $validate, 'handle' ],
            'permission_callback' => $hmac_required,
        ] );

        register_rest_route( self::NAMESPACE, '/deactivate', [
            'methods'             => 'POST',
            'callback'            => [ $deactivate, 'handle' ],
            'permission_callback' => $hmac_required,
        ] );

        register_rest_route( self::NAMESPACE, '/chat/bootstrap', [
            'methods'             => 'POST',
            'callback'            => [ $chat_bootstrap, 'handle' ],
            'permission_callback' => $hmac_required,
        ] );

        register_rest_route( self::NAMESPACE, '/chat/archive', [
            'methods'             => 'POST',
            'callback'            => [ $chat_archive, 'handle' ],
            'permission_callback' => $hmac_required,
        ] );

        register_rest_route( self::NAMESPACE, '/chat/delete', [
            'methods'             => 'POST',
            'callback'            => [ $chat_delete, 'handle' ],
            'permission_callback' => $hmac_required,
        ] );

        register_rest_route( self::NAMESPACE, '/chat/unarchive', [
            'methods'             => 'POST',
            'callback'            => [ $chat_unarchive, 'handle' ],
            'permission_callback' => $hmac_required,
        ] );

        register_rest_route( self::NAMESPACE, '/chat/poll', [
            'methods'             => 'POST',
            'callback'            => [ $chat_poll, 'handle' ],
            'permission_callback' => $hmac_required,
        ] );

        register_rest_route( self::NAMESPACE, '/chat/send', [
            'methods'             => 'POST',
            'callback'            => [ $chat_send, 'handle' ],
            'permission_callback' => $hmac_required,
        ] );

        register_rest_route( self::NAMESPACE, '/admin/licenses', [
            [
                'methods'             => 'GET',
                'callback'            => [ $admin, 'index' ],
                'permission_callback' => [ $admin, 'can_manage_options' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $admin, 'create' ],
                'permission_callback' => [ $admin, 'can_manage_options' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/admin/licenses/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ $admin, 'update' ],
                'permission_callback' => [ $admin, 'can_manage_options' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $admin, 'delete' ],
                'permission_callback' => [ $admin, 'can_manage_options' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/admin/licenses/(?P<id>\d+)/deactivate-all', [
            'methods'             => 'POST',
            'callback'            => [ $admin, 'deactivate_all' ],
            'permission_callback' => [ $admin, 'can_manage_options' ],
        ] );

        $rotate = new RotateKeyController( $this->license_service );
        register_rest_route( self::NAMESPACE, '/admin/licenses/(?P<id>\d+)/rotate-key', [
            'methods'             => 'POST',
            'callback'            => [ $rotate, 'handle' ],
            'permission_callback' => [ $rotate, 'can_manage_options' ],
        ] );

        register_rest_route( self::NAMESPACE, '/admin/settings', [
            'methods'             => 'GET',
            'callback'            => [ $settings, 'get_settings' ],
            'permission_callback' => [ $settings, 'can_manage_options' ],
        ] );

        register_rest_route( self::NAMESPACE, '/admin/encryption-key', [
            'methods'             => 'GET',
            'callback'            => [ $settings, 'get_encryption_key' ],
            'permission_callback' => [ $settings, 'can_manage_options' ],
        ] );

        $webhook_receiver = new WebhookReceiverController( $this->license_repo, new KeyDerivationService() );
        register_rest_route( self::NAMESPACE, '/webhook', [
            'methods'             => 'POST',
            'callback'            => [ $webhook_receiver, 'handle' ],
            'permission_callback' => '__return_true',
        ] );
    }
}
