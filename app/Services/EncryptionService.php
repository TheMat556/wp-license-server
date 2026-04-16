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

	private string $key;

	public function __construct() {
		$this->key = $this->resolve_key();
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

		$stored = get_option( self::OPTION_KEY, '' );
		if ( is_string( $stored ) && $stored !== '' ) {
			return $this->decode_key( $stored );
		}

		throw new \RuntimeException( 'WPLICENSE_ENCRYPTION_KEY constant is required.' );
	}

	/**
	 * Decode and validate a base64-encoded 32-byte key.
	 */
	private function decode_key( string $encoded ): string {
		$decoded = base64_decode( $encoded, true );

		if ( $decoded === false || strlen( $decoded ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			throw new \RuntimeException(
				'Encryption key must be exactly '
				. SODIUM_CRYPTO_SECRETBOX_KEYBYTES
				. ' bytes, base64-encoded.'
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
			throw new \RuntimeException( 'Invalid ciphertext encoding.' );
		}

		$version = ord( $raw[0] );

		if ( $version !== 0x01 ) {
			throw new \RuntimeException( sprintf( 'Unknown encryption version: %d.', $version ) );
		}

		$nonce      = substr( $raw, 1, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key );

		if ( $plaintext === false ) {
			throw new \RuntimeException( 'Decryption failed — wrong key or corrupted data.' );
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
