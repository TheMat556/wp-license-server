<?php
/**
 * Repository for license_keys table.
 *
 * Every query uses $wpdb->prepare(). Full license keys never appear in logs.
 *
 * @package WpLicenseServer\Repositories
 */

declare(strict_types=1);

namespace WpLicenseServer\Repositories;

use WpLicenseServer\Contracts\LicenseRepositoryInterface;
use WpLicenseServer\ErrorCodes;
use WpLicenseServer\Models\License;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\TierConfig;

final class LicenseRepository implements LicenseRepositoryInterface {

    private string $table;

    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly EncryptionService $encryption,
    ) {
        $this->table = $wpdb->prefix . 'license_keys';
    }

    /**
     * Create a new license. Generates the license key and key_prefix.
     *
     * @param array{
     *     customer_name: string,
     *     customer_email: string,
     *     role?: string,
     *     tier: string,
     *     valid_until: string,
     *     payment_interval?: string,
     *     auto_renewal?: bool,
     *     notes?: string,
     * } $data
     */
    public function create( array $data ): License {
        $key        = bin2hex( random_bytes( 32 ) );
        $key_prefix = substr( $key, 0, 8 );

        $this->wpdb->insert(
            $this->table,
            [
                'license_key'      => $this->encryption->encrypt( $key ),
                'key_prefix'       => $key_prefix,
                'customer_name'    => sanitize_text_field( $data['customer_name'] ?? '' ),
                'customer_email'   => sanitize_email( $data['customer_email'] ),
                'role'             => sanitize_key( $data['role'] ?? 'customer' ),
                'tier'             => sanitize_text_field( $data['tier'] ),
                'status'           => 'active',
                'max_activations'  => TierConfig::max_activations_for_tier( $data['tier'] ),
                'payment_interval' => sanitize_text_field( $data['payment_interval'] ?? 'yearly' ),
                'auto_renewal'     => isset( $data['auto_renewal'] ) ? (int) $data['auto_renewal'] : 1,
                'notes'            => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
                'valid_until'      => sanitize_text_field( $data['valid_until'] ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
        );

        $id = (int) $this->wpdb->insert_id;

        // Fetch and return the full object — never return the raw key in non-CLI contexts.
        $license = $this->find_by_id( $id );
        if ( ! $license instanceof License ) {
            throw new \RuntimeException( 'Failed to load newly created license after insert.' );
        }
        return $license;
    }

    /** @return License|\WP_Error|null */
    public function find_by_id( int $id ): License|\WP_Error|null {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d",
                $id
            )
        );

        return $row ? $this->decrypt_row( $row ) : null;
    }

    /**
     * Find a license by ID with a row-level lock (FOR UPDATE).
     * Must be called inside an active transaction (InnoDB).
     *
     * @return License|\WP_Error|null
     */
    public function find_by_id_for_update( int $id ): License|\WP_Error|null {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE",
                $id
            )
        );

        return $row ? $this->decrypt_row( $row ) : null;
    }

    public function find_by_key( string $key ): ?License {
        // With encryption, we cannot do a direct SQL lookup by plaintext key.
        // Look up by prefix and verify the full key after decryption.
        $prefix  = substr( $key, 0, 8 );
        $license = $this->find_by_key_prefix( $prefix );

        if ( is_wp_error( $license ) ) {
            return null;
        }

        if ( $license && hash_equals( $license->license_key, $key ) ) {
            return $license;
        }

        return null;
    }

    /** @return License|\WP_Error|null */
    public function find_by_key_prefix( string $prefix ): License|\WP_Error|null {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE key_prefix = %s",
                $prefix
            )
        );

        return $row ? $this->decrypt_row( $row ) : null;
    }

    /** @return License|\WP_Error|null */
    public function find_owner( ?int $exclude_id = null ): License|\WP_Error|null {
        if ( null !== $exclude_id ) {
            $row = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->table} WHERE role = %s AND id != %d ORDER BY created_at ASC LIMIT 1",
                    'owner',
                    $exclude_id
                )
            );
        } else {
            $row = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->table} WHERE role = %s ORDER BY created_at ASC LIMIT 1",
                    'owner'
                )
            );
        }

        return $row ? $this->decrypt_row( $row ) : null;
    }

    /**
     * @return License[]
     */
    public function find_all( ?string $status = null ): array {
        if ( $status !== null ) {
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at DESC",
                    sanitize_text_field( $status )
                )
            );
        } else {
            // No user input — safe static query.
            $rows = $this->wpdb->get_results(
                "SELECT * FROM {$this->table} ORDER BY created_at DESC"
            );
        }

        $decrypted = array_map( fn( $row ) => $this->decrypt_row( $row ), $rows ?: [] );
        return array_values( array_filter( $decrypted, fn( $item ) => ! is_wp_error( $item ) ) );
    }

    /**
     * Find all licenses that have expired but still have status='active'.
     *
     * @return License[]
     */
    public function find_expired_active(): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s AND valid_until < %s",
                'active',
                current_time( 'mysql', true )
            )
        );

        $decrypted = array_map( fn( $row ) => $this->decrypt_row( $row ), $rows ?: [] );
        return array_values( array_filter( $decrypted, fn( $item ) => ! is_wp_error( $item ) ) );
    }

    public function update_status( int $id, string $status ): bool {
        $result = $this->wpdb->update(
            $this->table,
            [ 'status' => sanitize_text_field( $status ) ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update( int $id, array $data ): bool {
        $allowed = [
            'customer_name', 'customer_email', 'role', 'tier', 'status',
            'max_activations', 'payment_interval', 'auto_renewal',
            'notes', 'valid_until',
        ];
        $filtered = [];
        $formats  = [];

        foreach ( array_intersect_key( $data, array_flip( $allowed ) ) as $key => $value ) {
            switch ( $key ) {
                case 'customer_name':
                    $filtered[ $key ] = sanitize_text_field( (string) $value );
                    $formats[]        = '%s';
                    break;
                case 'customer_email':
                    $filtered[ $key ] = sanitize_email( (string) $value );
                    $formats[]        = '%s';
                    break;
                case 'role':
                case 'status':
                case 'payment_interval':
                    $filtered[ $key ] = sanitize_key( (string) $value );
                    $formats[]        = '%s';
                    break;
                case 'tier':
                case 'valid_until':
                    $filtered[ $key ] = sanitize_text_field( (string) $value );
                    $formats[]        = '%s';
                    break;
                case 'max_activations':
                    $filtered[ $key ] = max( 1, absint( $value ) );
                    $formats[]        = '%d';
                    break;
                case 'auto_renewal':
                    $filtered[ $key ] = (int) (bool) $value;
                    $formats[]        = '%d';
                    break;
                case 'notes':
                    $filtered[ $key ] = null === $value || '' === $value ? null : sanitize_textarea_field( (string) $value );
                    $formats[]        = '%s';
                    break;
            }
        }

        if ( empty( $filtered ) ) {
            return false;
        }

        $result = $this->wpdb->update(
            $this->table,
            $filtered,
            [ 'id' => $id ],
            $formats,
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Return the raw license key for the owner license.
     *
     * Decrypts the stored ciphertext and returns the plaintext key.
     * The caller is responsible for never sending the full value to any
     * REST response or JavaScript context.
     */
    public function get_decrypted_license_key(): ?string {
        $owner = $this->find_owner();

        if ( is_wp_error( $owner ) ) {
            return null;
        }

        return $owner?->license_key;
    }

    public function delete( int $id ): bool {
        $result = $this->wpdb->delete(
            $this->table,
            [ 'id' => $id ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Store a key rotation: set new key/prefix and archive the old key.
     */
    public function store_rotation(
        int $license_id,
        string $old_key,
        string $new_key,
        string $new_prefix,
        int $new_version,
    ): bool {
        $old_prefix = substr( $old_key, 0, 8 );

        $result = $this->wpdb->update(
            $this->table,
            [
                'license_key'            => $this->encryption->encrypt( $new_key ),
                'key_prefix'             => $new_prefix,
                'key_version'            => $new_version,
                'previous_key_encrypted' => $this->encryption->encrypt( $old_key ),
                'previous_key_prefix'    => $old_prefix,
                'rotation_at'            => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ 'id' => $license_id ],
            [ '%s', '%s', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Lock a license: set status to 'locked' and save the previous status.
     *
     * Uses a compare-and-swap UPDATE keyed on (id, status) so a concurrent
     * caller that already won the row lock cannot overwrite pre_lock_status
     * with 'locked'. The caller has already passed find_by_id_for_update()
     * and LicenseTransitions::validate(), so this is defense-in-depth.
     *
     * Returns false when zero rows are affected — the row either no longer
     * exists or has already left the expected status.
     *
     * @param int    $id             License ID.
     * @param string $current_status The status to restore on unlock.
     * @return bool Whether the update succeeded.
     */
    public function lock( int $id, string $current_status ): bool {
        $expected_status = sanitize_key( $current_status );

        if ( 'locked' === $expected_status ) {
            return false;
        }

        $sql = $this->wpdb->prepare(
            "UPDATE {$this->table}
                SET status = %s, pre_lock_status = %s
              WHERE id = %d
                AND status = %s",
            'locked',
            $expected_status,
            $id,
            $expected_status
        );

        $result = $this->wpdb->query( $sql );

        return is_int( $result ) && $result > 0;
    }

    /**
     * Unlock a license: restore the given status and clear pre_lock_status.
     *
     * The caller (LicenseService::unlock()) is responsible for determining
     * the correct restore status — the repository does not read
     * pre_lock_status independently to avoid split-decision bugs.
     *
     * @param int    $id         License ID.
     * @param string $restore_to Status to write (e.g. 'active').
     * @return bool Whether the update succeeded.
     */
    public function unlock( int $id, string $restore_to ): bool {
        $result = $this->wpdb->update(
            $this->table,
            [
                'status'          => sanitize_key( $restore_to ),
                'pre_lock_status' => null,
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Clear the previous key after the transition window expires.
     */
    public function clear_rotation( int $license_id ): bool {
        $result = $this->wpdb->update(
            $this->table,
            [
                'previous_key_encrypted' => null,
                'previous_key_prefix'    => null,
                'rotation_at'            => null,
            ],
            [ 'id' => $license_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Find a license by its previous (pre-rotation) key prefix.
     *
     * Used during the 24h dual-key transition window.
     *
     * @return License|\WP_Error|null
     */
    public function find_by_previous_prefix( string $prefix ): License|\WP_Error|null {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE previous_key_prefix = %s AND rotation_at IS NOT NULL",
                $prefix
            )
        );

        return $row ? $this->decrypt_row( $row ) : null;
    }

    /**
     * Find all licenses with an expired rotation transition window.
     *
     * @return License[]
     */
    public function find_expired_rotations( int $transition_hours = 24 ): array {
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $transition_hours * 3600 ) );

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE rotation_at IS NOT NULL AND rotation_at < %s",
                $cutoff
            )
        );

        $decrypted = array_map( fn( $row ) => $this->decrypt_row( $row ), $rows ?: [] );
        return array_values( array_filter( $decrypted, fn( $item ) => ! is_wp_error( $item ) ) );
    }

    /**
     * Total count, optionally filtered by status.
     */
    public function count( ?string $status = null ): int {
        if ( $status !== null ) {
            return (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
                    sanitize_text_field( $status )
                )
            );
        }

        return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
    }

    /**
     * Decrypt license_key in a DB row if encrypted, then hydrate a License.
     *
     * Handles both encrypted and legacy plaintext values for migration
     * compatibility: if the value is not encrypted, it is used as-is.
     *
     * @return License|\WP_Error|null
     */
    private function decrypt_row( ?object $row ): License|\WP_Error|null {
        try {
            if ( null === $row ) {
                return null;
            }

            if ( $this->encryption->is_encrypted( $row->license_key ) ) {
                $row->license_key = $this->encryption->decrypt( $row->license_key );
            }

            return License::from_row( $row );
        } catch ( \Throwable $e ) {
            error_log(
                sprintf(
                    '[WPLicense] LicenseRepository: failed to decrypt/hydrate license row (ID %d) — %s',
                    $row->id ?? 0,
                    $e->getMessage()
                )
            );
            return new \WP_Error(
                ErrorCodes::DECRYPTION_FAILED->value,
                'License data could not be decrypted.',
                [ 'status' => 500 ]
            );
        }
    }
}
