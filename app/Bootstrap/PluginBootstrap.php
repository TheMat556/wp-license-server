<?php
/**
 * Orchestrates hook registration for all plugin subsystems.
 *
 * @package WpLicenseServer\Bootstrap
 */

declare(strict_types=1);

namespace WpLicenseServer\Bootstrap;

use WpLicenseServer\Admin\AdminPage;
use WpLicenseServer\Admin\LicenseHealthMonitor;
use WpLicenseServer\Rest\RestApi;
use WpLicenseServer\Services\ExpiryService;
use WpLicenseServer\Services\WebhookDispatcher;

final class PluginBootstrap {

    public function __construct(
        private readonly RestApi $rest_api,
        private readonly AdminPage $admin_page,
        private readonly LicenseHealthMonitor $health_monitor,
        private readonly ExpiryService $expiry_service,
        private readonly WebhookDispatcher $webhook_dispatcher,
    ) {}

    public function register(): void {
        // REST API routes.
        add_action( 'rest_api_init', [ $this->rest_api, 'register_routes' ] );
        add_filter( 'cron_schedules', [ $this->webhook_dispatcher, 'register_schedule' ] );

        // Admin UI — only load in admin context.
        if ( is_admin() ) {
            add_action( 'admin_menu', [ $this->admin_page, 'register_menu' ] );
            add_action( 'admin_notices', [ $this->health_monitor, 'check_health' ] );
        }

        // Cron schedules for expiry checks and webhook dispatching.
        add_action( ExpiryService::CRON_HOOK, [ $this->expiry_service, 'check_expired' ] );
        add_action( WebhookDispatcher::CRON_HOOK, [ $this->webhook_dispatcher, 'dispatch_pending' ] );

        $this->expiry_service->ensure_scheduled();
        $this->webhook_dispatcher->ensure_scheduled();
    }
}
