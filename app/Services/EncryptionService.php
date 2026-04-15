<?php
/**
 * Authenticated encryption for license keys at rest.
 *
 * Key resolution (in priority order):
 *  1. WPLICENSE_ENCRYPTION_KEY constant in wp-config.php (manual override)
 *  2. Auto-generated key persisted in wp_options (first-run provisioning)
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

	private const OPTION_KEY = 'wplicense_encryption_key';

	private string $key;

	public function __construct() {
		$this->key = $this->resolve_key();
	}

	/**
	 * Resolve the master encryption key.
	 *
	 * Returns a 32-byte raw key. Constant > stored option > auto-generate.
	 */
	private function resolve_key(): string {
		// 1. Constant wins (backwards-compat + manual override).
		if ( defined( 'WPLICENSE_ENCRYPTION_KEY' ) ) {
			return $this->decode_key( WPLICENSE_ENCRYPTION_KEY );
		}

		// 2. Try stored option.
		$stored = get_option( self::OPTION_KEY, '' );

		if ( is_string( $stored ) && $stored !== '' ) {
			return $this->decode_key( $stored );
		}

		// 3. First run — generate, persist, and return.
		$generated = base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		add_option( self::OPTION_KEY, $generated, '', 'no' );

		return $this->decode_key( $generated );
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
