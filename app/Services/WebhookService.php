<?php
/**
 * Queues webhook jobs for active license activations.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

use WpLicenseServer\Contracts\ActivationRepositoryInterface;
use WpLicenseServer\Contracts\LicenseRepositoryInterface;
use WpLicenseServer\Contracts\WebhookQueueRepositoryInterface;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Repositories\WebhookQueueRepository;
use WpLicenseServer\Services\EncryptionService;

final class WebhookService {

    public function __construct(
        private readonly LicenseRepositoryInterface $license_repo,
        private readonly ActivationRepositoryInterface $activation_repo,
        private readonly WebhookQueueRepositoryInterface $queue_repo,
        private readonly ?EncryptionService $encryption = null,
    ) {}

    /**
     * Queue one webhook job per active activation.
     *
     * Passing $deterministic = true generates a day-scoped event_id so that
     * scheduled events (e.g. license.expired) fired multiple times on the same
     * day for the same license+domain produce only one queue row.
     *
     * Passing $deterministic = false (default) uses a random UUID so that
     * manually triggered events (e.g. admin key rotation) each create a new job.
     *
     * @param int                  $license_id    License ID.
     * @param string               $event         Event name.
     * @param array<string, mixed> $data          Minimal event payload data.
     * @param bool                 $deterministic Use day-scoped deterministic event_id.
     */
    public function queue_event( int $license_id, string $event, array $data, bool $deterministic = false ): void {
        $license = $this->license_repo->find_by_id( $license_id );

        if ( is_wp_error( $license ) || ! $license ) {
            return;
        }

        $activations = $this->activation_repo->get_all_active( $license_id );

        if ( empty( $activations ) ) {
            return;
        }

        $payload = array(
            'license_key_prefix' => $license->key_prefix,
            'data'               => $this->sanitize_payload_data( $data ),
        );

        foreach ( $activations as $activation ) {
            $secret = $activation->webhook_secret;

            if ( null === $secret || '' === $secret ) {
                $secret = $this->activation_repo->ensure_webhook_secret( $activation->id );
            }

            if ( null === $secret || '' === $secret ) {
                continue;
            }

            // Encrypt at rest — prevents cleartext secret in the queue table.
            $encrypted_secret = $secret;
            if ( null !== $this->encryption ) {
                try {
                    $encrypted_secret = $this->encryption->encrypt( $secret );
                } catch ( \Throwable ) {
                    // Encryption unavailable — store cleartext as fallback.
                }
            }

            $event_id = $this->make_event_id( $event, $license_id, $activation->domain, $deterministic );

            $this->queue_repo->insert(
                array(
                    'license_id'     => $license_id,
                    'domain'         => $activation->domain,
                    'webhook_secret' => $encrypted_secret,
                    'event'          => sanitize_text_field( $event ),
                    'event_id'       => $event_id,
                    'payload'        => $payload,
                )
            );
        }
    }

    /**
     * Generates a stable or random event identifier for a queue row.
     *
     * Deterministic: day-scoped SHA-256 hash — calling queue_event() twice on
     * the same day for the same event/license/domain produces the same id, so
     * the UNIQUE KEY silently discards the second insert.
     *
     * Non-deterministic: random UUID4 — each call is a distinct job.
     */
    private function make_event_id( string $event, int $license_id, string $domain, bool $deterministic ): string {
        if ( $deterministic ) {
            return hash( 'sha256', $event . '|' . $license_id . '|' . $domain . '|' . gmdate( 'Y-m-d' ) );
        }

        return wp_generate_uuid4();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitize_payload_data( array $data ): array {
        $sanitized = array();

        foreach ( $data as $key => $value ) {
            $normalized_key = sanitize_key( (string) $key );

            if ( '' === $normalized_key ) {
                continue;
            }

            if ( is_array( $value ) ) {
                $sanitized[ $normalized_key ] = $this->sanitize_payload_data( $value );
                continue;
            }

            if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
                $sanitized[ $normalized_key ] = $value;
                continue;
            }

            $sanitized[ $normalized_key ] = sanitize_text_field( (string) $value );
        }

        return $sanitized;
    }
}
