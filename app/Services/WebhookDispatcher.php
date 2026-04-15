<?php
/**
 * Dispatches queued webhook jobs with retry/backoff handling.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

use WpLicenseServer\Contracts\ActivityLogRepositoryInterface;
use WpLicenseServer\Contracts\LicenseRepositoryInterface;
use WpLicenseServer\Contracts\WebhookQueueRepositoryInterface;
use WpLicenseServer\ErrorCodes;
use WpLicenseServer\Models\WebhookJob;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Repositories\WebhookQueueRepository;
use WpLicenseServer\Services\KeyDerivationService;

final class WebhookDispatcher {

    public const CRON_HOOK = 'wplicense_dispatch_webhooks';
    private const SCHEDULE = 'wplicense_every_five_minutes';
    private WebhookTargetValidator $target_validator;

    /**
     * @var callable
     */
    private $remote_post;

    public function __construct(
        private readonly WebhookQueueRepositoryInterface $queue_repo,
        private readonly LicenseRepositoryInterface $license_repo,
        private readonly ActivityLogRepositoryInterface $activity_repo,
        private readonly WebhookRetrySchedule $retry_schedule,
        private readonly KeyDerivationService $key_derivation,
        ?callable $remote_post = null,
        ?WebhookTargetValidator $target_validator = null,
    ) {
        $this->remote_post      = $remote_post ?? 'wp_remote_post';
        $this->target_validator = $target_validator ?? new WebhookTargetValidator();
    }

    /**
     * Adds the custom 5-minute cron schedule used by the dispatcher.
     *
     * @param array<string, array<string, int|string>> $schedules Existing schedules.
     * @return array<string, array<string, int|string>>
     */
    public function register_schedule( array $schedules ): array {
        $schedules[ self::SCHEDULE ] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 5 Minutes', 'wp-license-server' ),
        );

        return $schedules;
    }

    public function ensure_scheduled(): void {
        if ( function_exists( 'wp_get_scheduled_event' ) ) {
            $event = wp_get_scheduled_event( self::CRON_HOOK );

            if ( $event && self::SCHEDULE === $event->schedule ) {
                return;
            }

            if ( $event ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
            }
        } elseif ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        wp_schedule_event( time(), self::SCHEDULE, self::CRON_HOOK );
    }

    public function dispatch_pending(): void {
        $batch_size = (int) apply_filters( 'wplicense_webhook_dispatch_batch_size', 20 );
        $page_size  = max( 50, $batch_size * 5 );
        $last_seen_id = 0;
        $processed  = 0;

        while ( $processed < $batch_size ) {
            $jobs = $this->queue_repo->get_pending_batch( $page_size, $last_seen_id );

            if ( empty( $jobs ) ) {
                break;
            }

            foreach ( $jobs as $job ) {
                $last_seen_id = $job->id;

                if ( ! $this->retry_schedule->is_ready_for_retry( $job->attempts, $job->last_attempt ) ) {
                    continue;
                }

                $this->dispatch_job( $job );
                ++$processed;

                if ( $processed >= $batch_size ) {
                    break;
                }
            }
        }
    }

    private function dispatch_job( WebhookJob $job ): void {
        $license = $this->license_repo->find_by_id( $job->license_id );

        if ( is_wp_error( $license ) ) {
            $this->fail_job( $job, 'license_decrypt_failed', 500 );
            return;
        }

        if ( ! $license ) {
            $this->fail_job( $job, 'license_missing', 404 );
            return;
        }

        $body = $this->build_body( $job, $license->license_key );

        if ( is_wp_error( $body ) ) {
            $this->fail_job( $job, $body->get_error_code(), (int) ( $body->get_error_data()['status'] ?? 500 ) );
            return;
        }

        $endpoint_url = $this->build_endpoint_url( $job->domain );

        if ( '' === $endpoint_url ) {
            $this->fail_job( $job, 'invalid_webhook_domain', 400 );
            return;
        }

        $response = call_user_func(
            $this->remote_post,
            $endpoint_url,
            array(
                'timeout'            => (int) apply_filters( 'wplicense_webhook_timeout', 8 ),
                'redirection'        => 0,
                'reject_unsafe_urls' => true,
                'headers'            => array(
                    'Content-Type'     => 'application/json',
                    'X-Webhook-Secret' => $job->webhook_secret,
                ),
                'body'               => $body,
                'data_format'        => 'body',
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->fail_job( $job, $response->get_error_code(), 503 );
            return;
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );

        if ( 200 === $status_code ) {
            $this->queue_repo->mark_sent( $job->id );
            return;
        }

        $this->fail_job( $job, 'http_' . $status_code, $status_code );
    }

    private function build_endpoint_url( string $domain ): string {
        $validated_domain = $this->target_validator->validate_public_domain( $domain );

        if ( is_wp_error( $validated_domain ) ) {
            return '';
        }

        return 'https://' . trailingslashit( $validated_domain ) . 'wp-json/wp-react-ui/v1/license-webhook';
    }

    /**
     * @return string|\WP_Error
     */
    private function build_body( WebhookJob $job, string $license_key ) {
        $payload    = is_array( $job->payload ) ? $job->payload : array();
        $key_prefix = isset( $payload['license_key_prefix'] ) ? sanitize_text_field( (string) $payload['license_key_prefix'] ) : '';
        $data       = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
        $timestamp  = (string) time();
        $event_id   = $job->event_id;
        $data_json  = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        if ( '' === $key_prefix || ! is_string( $data_json ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_WEBHOOK_PAYLOAD->value,
                'Webhook payload could not be encoded.',
                array( 'status' => 500 )
            );
        }

        // Derive purpose-scoped webhook signing key (NIST key separation).
        $signing_key = $this->key_derivation->derive_webhook_key( $license_key );

        $signature = hash_hmac(
            'sha256',
            implode(
                "\n",
                array(
                    $job->event,
                    $event_id,
                    $key_prefix,
                    $timestamp,
                    $data_json,
                )
            ),
            $signing_key
        );
        sodium_memzero( $signing_key );

        $body = wp_json_encode(
            array(
                'event'              => $job->event,
                'event_id'           => $event_id,
                'license_key_prefix' => $key_prefix,
                'timestamp'          => $timestamp,
                'data'               => $data,
                'signature'          => $signature,
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ( ! is_string( $body ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_WEBHOOK_PAYLOAD->value,
                'Webhook request body could not be encoded.',
                array( 'status' => 500 )
            );
        }

        return $body;
    }

    private function fail_job( WebhookJob $job, string $error_code, int $status_code ): void {
        $attempts    = $job->attempts + 1;
        $mark_failed = $this->retry_schedule->should_mark_failed( $attempts );

        $this->queue_repo->record_failure( $job->id, $attempts, $mark_failed );

        if ( ! $mark_failed ) {
            return;
        }

        $failed_job = $this->queue_repo->find_by_id( $job->id );
        $payload    = array(
            'job_id'   => $job->id,
            'event'    => $job->event,
            'domain'   => $job->domain,
            'attempts' => $attempts,
            'error'    => sanitize_text_field( $error_code ),
            'status'   => $status_code,
        );

        $this->activity_repo->insert(
            array(
                'license_id' => $job->license_id,
                'action'     => 'webhook_failed',
                'domain'     => $job->domain,
                'actor'      => 'system',
                'details'    => $payload,
            )
        );

        do_action( 'wplicense_webhook_failed', $failed_job ?? $job );
    }
}
