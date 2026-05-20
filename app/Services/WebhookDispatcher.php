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
use WpLicenseServer\Services\EncryptionService;

use function __;
use function add_action;
use function remove_action;

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
        private readonly ?EncryptionService $encryption = null,
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

    private const DISPATCH_LOCK_KEY     = 'wplicense_webhook_dispatch_lock';
    private const DISPATCH_LOCK_GROUP   = 'wplicense';
    private const DISPATCH_LOCK_TIMEOUT = 120;

    public function dispatch_pending(): void {
        // Cron lock: prevent overlapping dispatches that could cross-contaminate
        // DNS pinning via the global http_api_curl action. Uses atomic
        // set-if-not-exists primitives to avoid the get/set TOCTOU race.
        if ( ! $this->acquire_dispatch_lock() ) {
            return;
        }

        $batch_size = (int) apply_filters( 'wplicense_webhook_dispatch_batch_size', 20 );
        $page_size  = max( 50, $batch_size * 5 );
        $last_seen_id = 0;
        $processed  = 0;

        try {
            while ( $processed < $batch_size ) {
                $jobs = $this->queue_repo->get_pending_batch( $page_size, $last_seen_id );

                if ( empty( $jobs ) ) {
                    break;
                }

                foreach ( $jobs as $job ) {
                    $last_seen_id = $job->id;

                    if ( ! $this->retry_schedule->is_ready_for_retry( $job->attempts, $job->last_attempt, $job->event ) ) {
                        continue;
                    }

                    $this->dispatch_job( $job );
                    ++$processed;

                    if ( $processed >= $batch_size ) {
                        break;
                    }
                }
            }
        } finally {
            $this->release_dispatch_lock();
        }
    }

    /**
     * Acquire the cron dispatch lock atomically.
     *
     * Prefers wp_cache_add() when a persistent object cache is available
     * (Redis, Memcached) because it is a true set-if-not-exists. Without a
     * persistent backend, wp_cache_add is per-process and unsafe across
     * cron forks — so we fall back to add_option(), which fails when the
     * row already exists and gives DB-level CAS.
     *
     * An expired DB lock is reclaimed: this avoids permanent lockouts when
     * a previous run died before reaching release_dispatch_lock().
     */
    private function acquire_dispatch_lock(): bool {
        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            return (bool) wp_cache_add(
                self::DISPATCH_LOCK_KEY,
                time(),
                self::DISPATCH_LOCK_GROUP,
                self::DISPATCH_LOCK_TIMEOUT
            );
        }

        $now = time();

        if ( add_option( self::DISPATCH_LOCK_KEY, $now, '', false ) ) {
            return true;
        }

        // Reclaim a stale lock left behind by a dead process.
        $existing = (int) get_option( self::DISPATCH_LOCK_KEY, 0 );
        if ( $existing > 0 && ( $now - $existing ) > self::DISPATCH_LOCK_TIMEOUT ) {
            delete_option( self::DISPATCH_LOCK_KEY );
            return (bool) add_option( self::DISPATCH_LOCK_KEY, $now, '', false );
        }

        return false;
    }

    private function release_dispatch_lock(): void {
        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            wp_cache_delete( self::DISPATCH_LOCK_KEY, self::DISPATCH_LOCK_GROUP );
            return;
        }

        delete_option( self::DISPATCH_LOCK_KEY );
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

        $endpoint_url = $this->build_endpoint_url( $job->domain, $job );

        if ( '' === $endpoint_url ) {
            $this->fail_job( $job, ErrorCodes::INVALID_DOMAIN->value, 400 );
            return;
        }

        // Check if endpoint was overridden via filter — if so, skip DNS
        // resolution and use the custom URL as-is (e.g. for reverse proxy
        // scenarios where the domain resolves to a private IP).
        $default_url = 'https://' . $this->target_validator->normalize_domain( $job->domain ) . '/?rest_route=/license-server/v1/webhook';
        $endpoint_overridden = $endpoint_url !== $default_url;

        $http_args = array(
            'timeout'            => (int) apply_filters( 'wplicense_webhook_timeout', 8 ),
            'redirection'        => 0,
            'reject_unsafe_urls' => ! $endpoint_overridden && ! self::is_dev_mode(),
            'headers'            => array(
                'Content-Type'     => 'application/json',
                'X-Webhook-Secret' => $this->resolve_webhook_secret( $job ),
            ),
            'body'               => $body,
            'data_format'        => 'body',
        );

        if ( $endpoint_overridden ) {
            // Custom endpoint — validate the host and apply DNS pinning
            // to prevent DNS rebinding against the filter-provided URL.
            $parsed_url    = wp_parse_url( $endpoint_url );
            $filtered_host = is_array( $parsed_url ) && isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
            $validated     = $this->target_validator->validate_public_domain( $filtered_host );

            if ( ! is_wp_error( $validated ) ) {
                $http_args['headers']['Host'] = $filtered_host;
                $http_args['reject_unsafe_urls'] = true;

                $resolved_ip = $this->resolve_target_ip( $filtered_host );
                if ( ! is_wp_error( $resolved_ip ) && \defined( 'CURLOPT_RESOLVE' ) ) {
                    $curl_callback = function ( $handle ) use ( $resolved_ip, $filtered_host ): void {
                        curl_setopt( $handle, CURLOPT_RESOLVE, [ "{$filtered_host}:443:{$resolved_ip}" ] );
                    };
                    add_action( 'http_api_curl', $curl_callback, 10, 1 );
                }
            } else {
                // Host failed validation — fail the job.
                $this->fail_job( $job, ErrorCodes::INVALID_DOMAIN->value, 400 );
                return;
            }
        } else {
            // Default DNS-based resolution — pin IP against DNS rebinding.
            $resolved_ip = $this->resolve_target_ip( $job->domain );
            if ( is_wp_error( $resolved_ip ) ) {
                $this->fail_job( $job, ErrorCodes::DNS_RESOLUTION_FAILED->value, 400 );
                return;
            }

            $normalized_domain = $this->target_validator->normalize_domain( $job->domain );
            $curl_callback = null;
            if ( \defined( 'CURLOPT_RESOLVE' ) ) {
                $http_args['headers']['Host'] = $normalized_domain; // preserve SNI
                $curl_callback = function ( $handle ) use ( $resolved_ip, $normalized_domain ): void {
                    curl_setopt( $handle, CURLOPT_RESOLVE, [ "{$normalized_domain}:443:{$resolved_ip}" ] );
                };
                add_action( 'http_api_curl', $curl_callback, 10, 1 );
            } else {
                error_log(
                    sprintf(
                        '[WPLicense] Webhook dispatch without DNS pinning for %s — install php-curl for SSRF protection.',
                        $job->domain
                    )
                );
            }
        }

        try {
            $response = wp_remote_post( $endpoint_url, $http_args );
        } finally {
            // Clean up the curl resolve pinning action hook to prevent leaks.
            if ( isset( $curl_callback ) && null !== $curl_callback ) {
                remove_action( 'http_api_curl', $curl_callback, 10 );
            }
        }

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
                __( 'Domain is empty.', 'wp-license-server' )
            );
        }

        $ips = $this->dns_resolver->resolve_ips( $normalized );

        if ( empty( $ips ) ) {
            return new \WP_Error(
                ErrorCodes::DNS_RESOLUTION_FAILED->value,
                __( 'Domain could not be resolved.', 'wp-license-server' )
            );
        }

        // In development mode, bypass all SSRF checks (matches validate_public_domain).
        if ( self::is_dev_mode() ) {
            return $ips[0];
        }

        $skip_private_ip_check = (bool) apply_filters( 'wplicense_allow_private_webhook_target', false, $normalized, $ips );

        if ( ! $skip_private_ip_check ) {
            foreach ( $ips as $ip ) {
                if ( ! $this->dns_resolver->is_public_ip( $ip ) ) {
                    return new \WP_Error(
                        ErrorCodes::PRIVATE_IP->value,
                        __( 'Domain resolves to a private/reserved IP.', 'wp-license-server' )
                    );
                }
            }
        }

        return $ips[0];
    }

    public static function is_dev_mode(): bool {
        // In production, refuse all dev-mode / SSRF bypass mechanisms.
        if ( function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type() ) {
            return false;
        }

        // Admin-toggle option (non-production only, auto-expires after 24h).
        $ssrf_bypass = get_option( 'wplicense_ssrf_bypass', '0' );
        if ( '1' === $ssrf_bypass ) {
            if ( get_transient( 'wplicense_ssrf_bypass_active' ) ) {
                return true;
            }
            delete_option( 'wplicense_ssrf_bypass' );
        }

        // Legacy constant — only honored outside production.
        if ( defined( 'WPLICENSE_DEV_MODE' ) ) {
            return (bool) WPLICENSE_DEV_MODE;
        }

        return false;
    }

    private function build_endpoint_url( string $domain, ?WebhookJob $job = null ): string {
        /**
         * Override the webhook endpoint URL for a given domain.
         *
         * Useful when the activation domain is behind a reverse proxy that
         * cannot be reached at its public DNS name from the license server.
         * Return a full URL (e.g. "https://192.168.10.150/?rest_route=...")
         * or null/false to fall through to the default DNS-based resolution.
         *
         * @param string|null $endpoint_url Default endpoint URL or null.
         * @param string      $domain       The activation domain.
         * @param int|null    $license_id   The license ID, if available.
         */
        $filtered = apply_filters( 'wplicense_webhook_endpoint', null, $domain, $job?->license_id ?? null );
        if ( is_string( $filtered ) && '' !== $filtered ) {
            // Validate the filtered URL: only https, no IP literals, port 443 or absent.
            $parsed = wp_parse_url( $filtered );
            $scheme = $parsed['scheme'] ?? '';
            $host   = $parsed['host'] ?? '';
            $port   = $parsed['port'] ?? '';

            if (
                'https' !== $scheme ||
                '' === $host ||
                filter_var( $host, FILTER_VALIDATE_IP ) ||
                ( '' !== $port && 443 !== $port )
            ) {
                error_log(
                    sprintf(
                        '[WPLicense] Ignored wplicense_webhook_endpoint filter for %s: invalid URL (host=%s, scheme=%s, port=%s).',
                        $domain,
                        $host,
                        $scheme,
                        (string) $port
                    )
                );
            } else {
                return $filtered;
            }
        }

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

    /**
     * Decrypt an encrypted webhook secret from the queue, or return cleartext.
     *
     * On failure, returns an empty string so the caller sends a blank
     * X-Webhook-Secret header, which the client's hash_equals will reject.
     * The job is marked failed after the dispatch attempt.
     */
    private function resolve_webhook_secret( WebhookJob $job ): string {
        if ( null === $this->encryption || ! $this->encryption->is_encrypted( $job->webhook_secret ) ) {
            return $job->webhook_secret;
        }

        try {
            return $this->encryption->decrypt( $job->webhook_secret );
        } catch ( \Throwable ) {
            error_log(
                sprintf(
                    '[WPLicense] Failed to decrypt webhook secret for job %d — returning empty.',
                    $job->id
                )
            );
            return '';
        }
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
