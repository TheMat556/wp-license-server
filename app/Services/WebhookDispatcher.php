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

final class WebhookDispatcher {

    public const CRON_HOOK = 'wplicense_dispatch_webhooks';
    private const SCHEDULE = 'wplicense_every_five_minutes';

    private DnsResolver $dns_resolver;
    private WebhookTargetValidator $target_validator;

    public function __construct(
        private readonly WebhookQueueRepositoryInterface $queue_repo,
        private readonly LicenseRepositoryInterface $license_repo,
        private readonly ActivityLogRepositoryInterface $activity_repo,
        private readonly WebhookRetrySchedule $retry_schedule,
        private readonly KeyDerivationService $key_derivation,
        ?DnsResolver $dns_resolver = null,
        ?WebhookTargetValidator $target_validator = null,
    ) {
        $this->dns_resolver     = $dns_resolver ?? new DnsResolver();
        $this->target_validator = $target_validator ?? new WebhookTargetValidator( $this->dns_resolver );
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
            $this->fail_job( $job, ErrorCodes::DECRYPTION_FAILED->value, 500 );
            return;
        }

        if ( ! $license ) {
            $this->fail_job( $job, ErrorCodes::LICENSE_NOT_FOUND->value, 404 );
            return;
        }

        $body = $this->build_body( $job, $license->license_key );

        if ( is_wp_error( $body ) ) {
            $this->fail_job( $job, $body->get_error_code(), (int) ( $body->get_error_data()['status'] ?? 500 ) );
            return;
        }

        // Resolve and cache IP before validation to prevent DNS rebinding.
        $resolved_ip = $this->resolve_target_ip( $job->domain );
        if ( is_wp_error( $resolved_ip ) ) {
            $this->fail_job( $job, ErrorCodes::DNS_RESOLUTION_FAILED->value, 400 );
            return;
        }

        $endpoint_url = $this->build_endpoint_url( $job->domain );

        if ( '' === $endpoint_url ) {
            $this->fail_job( $job, ErrorCodes::INVALID_DOMAIN->value, 400 );
            return;
        }

        $http_args = array(
            'timeout'            => (int) apply_filters( 'wplicense_webhook_timeout', 8 ),
            'redirection'        => 0,
            'reject_unsafe_urls' => true,
            'headers'            => array(
                'Content-Type' => 'application/json',
            ),
            'body'               => $body,
            'data_format'        => 'body',
        );

        // DNS pinning: pin the resolved IP to prevent rebinding between validation and connect.
        if ( \defined( 'CURLOPT_RESOLVE' ) ) {
            $http_args['headers']['Host'] = $job->domain; // preserve SNI
            add_action( 'http_api_curl', function ( $handle ) use ( $resolved_ip, $job ): void {
                curl_setopt( $handle, CURLOPT_RESOLVE, [ "{$job->domain}:443:{$resolved_ip}" ] );
            }, 10, 1 );
        } else {
            error_log(
                sprintf(
                    '[WPLicense] Webhook dispatch without DNS pinning for %s — install php-curl for SSRF protection.',
                    $job->domain
                )
            );
        }

        $response = wp_remote_post( $endpoint_url, $http_args );

        // Clean up the curl resolve pinning action hook to prevent leaks.
        remove_all_actions( 'http_api_curl' );

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

    /**
     * Resolve domain to IP and verify it is public, preventing DNS rebinding.
     *
     * @return string|\WP_Error Resolved IP address or WP_Error.
     */
    private function resolve_target_ip( string $domain ) {
        $normalized = $this->target_validator->normalize_domain( $domain );

        if ( '' === $normalized ) {
            return new \WP_Error(
                ErrorCodes::INVALID_DOMAIN->value,
                'Domain is empty.'
            );
        }

        $ips = $this->dns_resolver->resolve_ips( $normalized );

        if ( empty( $ips ) ) {
            return new \WP_Error(
                ErrorCodes::DNS_RESOLUTION_FAILED->value,
                'Domain could not be resolved.'
            );
        }

        $skip_private_ip_check = (bool) apply_filters( 'wplicense_allow_private_webhook_target', false, $normalized, $ips );

        if ( ! $skip_private_ip_check ) {
            foreach ( $ips as $ip ) {
                if ( ! $this->dns_resolver->is_public_ip( $ip ) ) {
                    return new \WP_Error(
                        ErrorCodes::PRIVATE_IP->value,
                        'Domain resolves to a private/reserved IP.'
                    );
                }
            }
        }

        return $ips[0];
    }

    private function build_endpoint_url( string $domain ): string {
        $validated_domain = $this->target_validator->validate_public_domain( $domain );

        if ( is_wp_error( $validated_domain ) ) {
            return '';
        }

        return 'https://' . $validated_domain . '/?rest_route=/license-server/v1/webhook';
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

        if ( '' === $key_prefix ) {
            return new \WP_Error(
                ErrorCodes::INVALID_WEBHOOK_PAYLOAD->value,
                'Webhook payload could not be encoded.',
                array( 'status' => 500 )
            );
        }

        // Derive purpose-scoped webhook signing key (NIST key separation).
        $signing_key = $this->key_derivation->derive_webhook_key( $license_key );

        $data_json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $data_json ) ) {
            sodium_memzero( $signing_key );
            return new \WP_Error(
                ErrorCodes::INVALID_WEBHOOK_PAYLOAD->value,
                'Webhook data could not be encoded.',
                array( 'status' => 500 )
            );
        }

        // v1.4+: use body_hash (SHA-256 of the raw JSON body) for deterministic verification.
        $body_hash = hash( 'sha256', $data_json );

        $signature = hash_hmac(
            'sha256',
            implode(
                "\n",
                array(
                    $job->event,
                    $event_id,
                    $key_prefix,
                    $timestamp,
                    $body_hash,
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
                'body_hash'          => $body_hash,
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
