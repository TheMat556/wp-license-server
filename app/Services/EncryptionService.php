<?php
/**
 * Authenticated encryption for license keys at rest.
 *
 * Prefers the WPLICENSE_ENCRYPTION_KEY constant (best security — define in wp-config.php).
 * Falls back to a key auto-generated on plugin activation and stored in wp_options.
 * When the fallback is active an admin notice is shown urging the operator to move the
 * key into wp-config.php.
 *
 * The constant (or stored value) must be a base64-encoded 32-byte (256-bit) key.
 *
 * Uses sodium_crypto_secretbox (XSalsa20-Poly1305) with a per-encrypt random
 * nonce. Ciphertexts are prefixed with a version byte (0x01) to support future
 * algorithm migration, then base64-encoded for DB storage.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

final class EncryptionService {

	/** Returns where the active key came from — useful for admin UI status display. */
	public static function get_key_source(): string {
		return defined( 'WPLICENSE_ENCRYPTION_KEY' ) ? 'constant' : 'database';
	}

	/** wp_options key used when the constant is not defined. */
	public const OPTION_KEY = 'wplicense_encryption_key';

	/** Transient key used to flag that key setup is needed after activation. */
	public const SETUP_PENDING_TRANSIENT = 'wplicense_key_setup_pending';

	/**
	 * Set a transient flag indicating that the encryption key needs to be
	 * set up after plugin activation. Skips if the constant is already defined.
	 * Called on plugin activation instead of maybe_generate_key() so the user
	 * can choose whether to have the key auto-written to wp-config.php.
	 */
	public static function mark_key_setup_pending(): void {
		if ( defined( 'WPLICENSE_ENCRYPTION_KEY' ) ) {
			return;
		}
		set_transient( self::SETUP_PENDING_TRANSIENT, 1, HOUR_IN_SECONDS );
	}

	/**
	 * Check whether key setup is still pending.
	 */
	public static function is_key_setup_pending(): bool {
		return (bool) get_transient( self::SETUP_PENDING_TRANSIENT );
	}

	/**
	 * Clear the key setup pending flag.
	 */
	public static function clear_key_setup_pending(): void {
		delete_transient( self::SETUP_PENDING_TRANSIENT );
	}

	private string $key;

	public function __construct() {
		$this->key = $this->resolve_key();
	}

	/**
	 * Register the admin_notices hook to warn when the key lives in wp_options.
	 * Call this once from the plugin bootstrap (e.g. on 'init').
	 */
	public static function register_admin_notice(): void {
		add_action( 'admin_notices', [ self::class, 'render_key_source_notice' ] );
	}

	/**
	 * Display a dismissible banner when the encryption key is stored in wp_options
	 * instead of a server-side constant or environment variable.
	 *
	 * Hooked to 'admin_notices'. Only visible to users with manage_options.
	 * Dismissed state is persisted via the 'wls_enc_key_notice_dismissed' transient.
	 */
	public static function render_key_source_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Already using a server constant — nothing to warn about.
		if ( self::get_key_source() === 'constant' ) {
			return;
		}

		// Respect the admin's dismissal.
		if ( get_transient( 'wls_enc_key_notice_dismissed' ) ) {
			return;
		}

		// Handle the dismiss action (nonce-verified).
		if (
			isset( $_GET['wls_dismiss_enc_notice'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'wls_dismiss_enc_notice' )
		) {
			// Dismiss for 30 days.
			set_transient( 'wls_enc_key_notice_dismissed', 1, 30 * DAY_IN_SECONDS );
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'wls_dismiss_enc_notice', '1' ),
			'wls_dismiss_enc_notice'
		);

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s:</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'WP License Server', 'wp-license-server' ),
			esc_html__( 'The encryption key is stored in wp_options (database). For stronger security, define WPLICENSE_ENCRYPTION_KEY as a constant in wp-config.php or set it via a server-side environment variable so the key is never stored alongside the data it protects.', 'wp-license-server' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss for 30 days', 'wp-license-server' )
		);
	}

	/**
	 * Generate a new random key and persist it in wp_options (idempotent).
	 * Called on plugin activation so the plugin works out of the box.
	 */
	public static function maybe_generate_key(): void {
		if ( defined( 'WPLICENSE_ENCRYPTION_KEY' ) ) {
			return;
		}

		if ( get_option( self::OPTION_KEY ) ) {
			return;
		}

		$raw = sodium_crypto_secretbox_keygen();
		update_option( self::OPTION_KEY, base64_encode( $raw ), false );
	}

	/**
	 * Resolve the master encryption key.
	 *
	 * Priority: WPLICENSE_ENCRYPTION_KEY constant → wp_options auto-key.
	 *
	 * @throws \RuntimeException When no key is available at all.
	 */
	private function resolve_key(): string {
		if ( defined( 'WPLICENSE_ENCRYPTION_KEY' ) ) {
			return $this->decode_key( WPLICENSE_ENCRYPTION_KEY );
		}

		// Production safety: refuse the wp_options fallback unless the operator explicitly
		// opts in. Storing the encryption key in the same database as the ciphertext defeats
		// encryption-at-rest if a DB dump leaks. Override with the
		// 'wplicense_allow_db_encryption_key' filter when DB storage is an accepted risk.
		$env             = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$allow_db_in_env = ( 'production' !== $env );
		$allow_db_key    = (bool) apply_filters( 'wplicense_allow_db_encryption_key', $allow_db_in_env, $env );

		if ( ! $allow_db_key ) {
			throw new \RuntimeException(
				__( 'WPLICENSE_ENCRYPTION_KEY constant is required in production. Define it in wp-config.php, or set the wplicense_allow_db_encryption_key filter to true to accept the database fallback.', 'wp-license-server' )
			);
		}

		$stored = get_option( self::OPTION_KEY, '' );
		if ( is_string( $stored ) && $stored !== '' ) {
			return $this->decode_key( $stored );
		}

		throw new \RuntimeException( __( 'WPLICENSE_ENCRYPTION_KEY constant is required.', 'wp-license-server' ) );
	}

	/**
	 * Decode and validate a base64-encoded 32-byte key.
	 */
	private function decode_key( string $encoded ): string {
		$decoded = base64_decode( $encoded, true );

		if ( $decoded === false || strlen( $decoded ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: number of bytes required */
					__( 'Encryption key must be exactly %d bytes, base64-encoded.', 'wp-license-server' ),
					SODIUM_CRYPTO_SECRETBOX_KEYBYTES
				)
			);
		}

		return $decoded;
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * Output format (base64-encoded):
	 *   [version:1 byte][nonce:24 bytes][ciphertext+tag]
	 */
	public function encrypt( string $plaintext ): string {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );

		return base64_encode( chr( 0x01 ) . $nonce . $ciphertext );
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @throws \RuntimeException On invalid encoding, unknown version, or auth failure.
	 */
	public function decrypt( string $encoded ): string {
		$raw = base64_decode( $encoded, true );

		$min_len = 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

		if ( $raw === false || strlen( $raw ) < $min_len ) {
			throw new \RuntimeException( __( 'Invalid ciphertext encoding.', 'wp-license-server' ) );
		}

		$version = ord( $raw[0] );

		if ( $version !== 0x01 ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: encryption version number */
					__( 'Unknown encryption version: %d.', 'wp-license-server' ),
					$version
				)
			);
		}

		$nonce      = substr( $raw, 1, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key );

		if ( $plaintext === false ) {
			throw new \RuntimeException( __( 'Decryption failed — wrong key or corrupted data.', 'wp-license-server' ) );
		}

		return $plaintext;
	}

	/**
	 * Detect whether a stored value is already encrypted.
	 *
	 * Plaintext license keys are 64-char hex strings. Encrypted values are
	 * base64-encoded and always longer than 64 characters, with a 0x01
	 * version byte prefix in the decoded bytes.
	 */
	public function is_encrypted( string $value ): bool {
		// Plaintext hex keys are exactly 64 chars; encrypted base64 is ~140+ chars.
		if ( strlen( $value ) <= 64 ) {
			return false;
		}

		$raw = base64_decode( $value, true );

		if ( $raw === false ) {
			return false;
		}

		$min_len = 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

		return strlen( $raw ) >= $min_len && ord( $raw[0] ) === 0x01;
	}
}
