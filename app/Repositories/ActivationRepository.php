<?php
/**
 * Repository for license_activations table.
 *
 * Webhook secrets are encrypted at rest using EncryptionService.
 * The model always exposes plaintext values; encryption/decryption lives here.
 *
 * @package WpLicenseServer\Repositories
 */

declare(strict_types=1);

namespace WpLicenseServer\Repositories;

use WpLicenseServer\Contracts\ActivationRepositoryInterface;
use WpLicenseServer\ErrorCodes;
use WpLicenseServer\Models\Activation;
use WpLicenseServer\Services\EncryptionService;
use function __;

final class ActivationRepository implements ActivationRepositoryInterface {

    private string $table;

    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly EncryptionService $encryption,
    ) {
        $this->table = $wpdb->prefix . 'license_activations';
    }

    /**
     * Create a new activation record. Generates and encrypts webhook_secret automatically.
     *
     * @param array{
     *     license_id: int,
     *     domain: string,
     *     plugin_version?: string,
     *     wp_version?: string,
     *     php_version?: string,
     * } $data
     * @return Activation|\WP_Error
     */
    public function create( array $data ): Activation|\WP_Error {
        $plaintext_secret = bin2hex( random_bytes( 16 ) );
        $license_id       = absint( $data['license_id'] );
        $domain           = sanitize_text_field( $data['domain'] );

        // The UNIQUE KEY (license_id, domain) blocks a fresh INSERT when a soft-deleted
        // row still exists for this slot. Remove it first so the INSERT can succeed.
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table}
                  WHERE license_id = %d AND domain = %s AND deactivated_at IS NOT NULL",
                $license_id,
                $domain
            )
        );

        $inserted = $this->wpdb->insert(
            $this->table,
            [
                'license_id'             => $license_id,
                'domain'                 => $domain,
                'plugin_version'         => isset( $data['plugin_version'] ) ? sanitize_text_field( $data['plugin_version'] ) : null,
                'wp_version'             => isset( $data['wp_version'] ) ? sanitize_text_field( $data['wp_version'] ) : null,
                'php_version'            => isset( $data['php_version'] ) ? sanitize_text_field( $data['php_version'] ) : null,
                'last_heartbeat'         => current_time( 'mysql', true ),
                'webhook_secret'         => $this->encryption->encrypt( $plaintext_secret ),
                'webhook_secret_version' => 1,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );

        $id = (int) $this->wpdb->insert_id;

        if ( false === $inserted || 0 === $id ) {
            error_log(
                sprintf(
                    'Failed to create activation for license %d on %s. DB error: %s',
                    $license_id,
                    $domain,
                    $this->wpdb->last_error
                )
            );
            return new \WP_Error(
                ErrorCodes::ACTIVATION_FAILED->value,
                __( 'Activation failed. Please try again later.', 'wp-license-server' ),
                [ 'status' => 500 ]
            );
        }

        $activation = $this->find_by_id( $id );

        if ( null === $activation ) {
            error_log(
                sprintf(
                    'Activation record %d not found after insert for license %d on %s.',
                    $id,
                    $license_id,
                    $domain
                )
            );
            return new \WP_Error(
                ErrorCodes::ACTIVATION_FAILED->value,
                __( 'Activation record could not be verified after creation.', 'wp-license-server' ),
                [ 'status' => 500 ]
            );
        }

        return $activation;
    }

    public function find_by_id( int $id ): ?Activation {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d",
                $id
            )
        );

        return $row ? $this->hydrate( $row ) : null;
    }

    /**
     * Find an active (non-deactivated) activation for a license + domain pair.
     */
    public function find_active( int $license_id, string $domain ): ?Activation {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE license_id = %d AND domain = %s AND deactivated_at IS NULL",
                $license_id,
                sanitize_text_field( $domain )
            )
        );

        return $row ? $this->hydrate( $row ) : null;
    }

    /**
     * @return Activation[]
     */
    public function get_all_active( int $license_id ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE license_id = %d AND deactivated_at IS NULL
                 ORDER BY activated_at ASC",
                $license_id
            )
        );

        return array_map( fn( $row ) => $this->hydrate( $row ), $rows ?: [] );
    }

    public function count_active( int $license_id ): int {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE license_id = %d AND deactivated_at IS NULL",
                $license_id
            )
        );
    }

    public function count_by_license_and_domain( int $license_id, string $domain ): int {
        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE license_id = %d AND domain = %s",
            $license_id,
            $domain
        ) );
    }

    /**
     * Soft-deactivate an activation by setting deactivated_at.
     */
    public function deactivate( int $license_id, string $domain ): bool {
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table}
                 SET deactivated_at = %s
                 WHERE license_id = %d AND domain = %s AND deactivated_at IS NULL",
                current_time( 'mysql', true ),
                $license_id,
                sanitize_text_field( $domain )
            )
        );

        return $result !== false && $result > 0;
    }

    /**
     * Update heartbeat timestamp and client version info.
     *
     * @param array<string, string|null> $versions
     */
    public function update_heartbeat( int $activation_id, array $versions ): bool {
        $data = [
            'last_heartbeat' => current_time( 'mysql', true ),
        ];

        if ( isset( $versions['plugin_version'] ) ) {
            $data['plugin_version'] = sanitize_text_field( $versions['plugin_version'] );
        }
        if ( isset( $versions['wp_version'] ) ) {
            $data['wp_version'] = sanitize_text_field( $versions['wp_version'] );
        }
        if ( isset( $versions['php_version'] ) ) {
            $data['php_version'] = sanitize_text_field( $versions['php_version'] );
        }

        $result = $this->wpdb->update(
            $this->table,
            $data,
            [ 'id' => $activation_id ],
        );

        return $result !== false;
    }

    /**
     * Rotate the webhook secret for an activation.
     *
     * - Moves the current secret to previous_webhook_secret (encrypted).
     * - Stores the new secret as webhook_secret (encrypted).
     * - Records webhook_secret_rotated_at and increments webhook_secret_version.
     *
     * Returns false if the activation does not exist or the DB update fails.
     */
    public function rotate_webhook_secret( int $activation_id, string $new_plaintext_secret ): bool {
        $activation = $this->find_by_id( $activation_id );

        if ( ! $activation ) {
            return false;
        }

        $previous_encrypted = null !== $activation->webhook_secret
            ? $this->encryption->encrypt( $activation->webhook_secret )
            : null;

        $result = $this->wpdb->update(
            $this->table,
            [
                'webhook_secret'          => $this->encryption->encrypt( $new_plaintext_secret ),
                'previous_webhook_secret' => $previous_encrypted,
                'webhook_secret_rotated_at' => current_time( 'mysql', true ),
                'webhook_secret_version'  => $activation->webhook_secret_version + 1,
            ],
            [ 'id' => $activation_id ],
            [ '%s', '%s', '%s', '%d' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Backfill a webhook_secret for legacy activations that were created before M4.
     *
     * Returns the existing plaintext secret if one is already present, otherwise
     * generates, encrypts, and stores a new one.
     */
    public function ensure_webhook_secret( int $activation_id ): ?string {
        $activation = $this->find_by_id( $activation_id );

        if ( ! $activation ) {
            return null;
        }

        if ( is_string( $activation->webhook_secret ) && '' !== $activation->webhook_secret ) {
            return $activation->webhook_secret;
        }

        $plaintext_secret = bin2hex( random_bytes( 16 ) );

        $result = $this->wpdb->update(
            $this->table,
            [ 'webhook_secret' => $this->encryption->encrypt( $plaintext_secret ) ],
            [ 'id' => $activation_id ],
            [ '%s' ],
            [ '%d' ]
        );

        if ( false === $result ) {
            return null;
        }

        return $plaintext_secret;
    }

    /**
     * Decrypt webhook secrets on a raw DB row and return a hydrated Activation.
     *
     * Falls back gracefully for legacy plaintext secrets (32-char hex) that
     * pre-date M4 encryption.
     */
    private function hydrate( object $row ): Activation {
        if ( null !== $row->webhook_secret ) {
            $row->webhook_secret = $this->decrypt_secret( $row->webhook_secret );
        }

        $prev = $row->previous_webhook_secret ?? null;
        if ( null !== $prev ) {
            $row->previous_webhook_secret = $this->decrypt_secret( $prev );
        }

        return Activation::from_row( $row );
    }

    /**
     * Decrypt a stored secret value, falling back to the raw value for legacy
     * plaintext secrets that were written before encryption was enabled.
     */
    private function decrypt_secret( string $stored ): string {
        if ( $this->encryption->is_encrypted( $stored ) ) {
            try {
                return $this->encryption->decrypt( $stored );
            } catch ( \RuntimeException ) {
                return $stored; // corrupted — surface as-is so callers can detect the problem.
            }
        }

        return $stored; // legacy plaintext fallback.
    }
}
