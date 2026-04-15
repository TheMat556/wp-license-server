<?php
/**
 * WP-CLI command to encrypt existing plaintext license keys.
 *
 * Idempotent: already-encrypted keys are skipped via is_encrypted() check.
 * Run after updating the plugin to the version that introduced encryption.
 *
 * Usage:
 *   wp license-server migrate-encryption --dry-run
 *   wp license-server migrate-encryption
 *   wp license-server migrate-encryption --batch-size=100
 *
 * @package WpLicenseServer\CLI
 */

declare(strict_types=1);

namespace WpLicenseServer\CLI;

use WpLicenseServer\Services\EncryptionService;

final class MigrateEncryptionCommand {

	public function __construct(
		private readonly EncryptionService $encryption,
	) {}

	/**
	 * Encrypts all plaintext license keys in the database.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be encrypted without making changes.
	 *
	 * [--batch-size=<size>]
	 * : Number of rows to process per batch.
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp license-server migrate-encryption --dry-run
	 *     wp license-server migrate-encryption
	 *     wp license-server migrate-encryption --batch-size=100
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;

		$dry_run    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$batch_size = max( 1, absint( $assoc_args['batch-size'] ?? 50 ) );
		$table      = $wpdb->prefix . 'license_keys';
		$offset     = 0;
		$encrypted  = 0;
		$skipped    = 0;
		$errors     = 0;

		if ( $dry_run ) {
			\WP_CLI::log( 'DRY RUN — no changes will be made.' );
		}

		\WP_CLI::log( sprintf( 'Processing license keys in batches of %d...', $batch_size ) );

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, license_key FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( $this->encryption->is_encrypted( $row->license_key ) ) {
					++$skipped;
					continue;
				}

				if ( $dry_run ) {
					\WP_CLI::log( sprintf(
						'  Would encrypt license #%d (prefix: %s)',
						$row->id,
						substr( $row->license_key, 0, 8 )
					) );
					++$encrypted;
					continue;
				}

				try {
					$cipher = $this->encryption->encrypt( $row->license_key );

					$result = $wpdb->update(
						$table,
						[ 'license_key' => $cipher ],
						[ 'id' => $row->id ],
						[ '%s' ],
						[ '%d' ]
					);

					if ( $result === false ) {
						\WP_CLI::warning( sprintf( 'Failed to update license #%d.', $row->id ) );
						++$errors;
					} else {
						++$encrypted;
					}
				} catch ( \Throwable $e ) {
					\WP_CLI::warning( sprintf(
						'Error encrypting license #%d: %s',
						$row->id,
						$e->getMessage()
					) );
					++$errors;
				}
			}

			$offset += $batch_size;
		} while ( count( $rows ) === $batch_size );

		$verb = $dry_run ? 'Would encrypt' : 'Encrypted';
		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf(
			'%s: %d  |  Already encrypted: %d  |  Errors: %d',
			$verb,
			$encrypted,
			$skipped,
			$errors
		) );

		if ( $errors > 0 ) {
			\WP_CLI::error( 'Some keys could not be encrypted. Review warnings above.' );
		} else {
			\WP_CLI::success( $dry_run ? 'Dry run complete.' : 'All license keys encrypted.' );
		}
	}
}
