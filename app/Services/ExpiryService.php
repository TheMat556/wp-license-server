<?php
/**
 * Cron-driven expiry checker.
 *
 * Finds licenses that are still marked 'active' but have passed their valid_until date,
 * updates their status to 'expired', and logs the event.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

use WpLicenseServer\Contracts\ActivityLogRepositoryInterface;
use WpLicenseServer\Contracts\LicenseRepositoryInterface;
use WpLicenseServer\Domain\LicenseState;
use WpLicenseServer\Domain\LicenseStateMachine;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\LicenseRepository;

final class ExpiryService {

    public const CRON_HOOK = 'wplicense_check_expiry';

    public function __construct(
        private readonly LicenseRepositoryInterface $license_repo,
        private readonly ActivityLogRepositoryInterface $activity_repo,
        private readonly WebhookService $webhook_service,
        private readonly ?LicenseStateMachine $state_machine = null,
    ) {}

    public function ensure_scheduled(): void {
        if ( function_exists( 'wp_get_scheduled_event' ) ) {
            $event = wp_get_scheduled_event( self::CRON_HOOK );

            if ( $event && 'daily' === $event->schedule ) {
                return;
            }

            if ( $event ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
            }
        } elseif ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
    }

    /**
     * Check for expired licenses and update their status.
     * Hooked to the expiry cron event.
     */
    public function check_expired(): void {
        $expired = $this->license_repo->find_expired_active();

        foreach ( $expired as $license ) {
            // Use state machine when available to confirm the license is truly expired.
            if ( $this->state_machine !== null ) {
                $state = $this->state_machine->compute_state( $license );
                if ( $state !== LicenseState::Expired ) {
                    continue; // Still active or in grace — skip.
                }
            }

            $this->license_repo->update_status( $license->id, 'expired' );

            $this->activity_repo->insert( [
                'license_id' => $license->id,
                'action'     => 'expired',
                'actor'      => 'system',
                'details'    => [
                    'valid_until' => $license->valid_until,
                    'detected_at' => current_time( 'mysql', true ),
                ],
            ] );

            $this->webhook_service->queue_event(
                $license->id,
                'license.expired',
                [
                    'tier'        => $license->tier,
                    'valid_until' => $license->valid_until,
                    'features'    => TierConfig::features_for_tier( $license->tier ),
                ],
                true // deterministic: prevents duplicate webhooks on repeated cron fires
            );
        }
    }
}
