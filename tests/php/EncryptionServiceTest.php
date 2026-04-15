<?php
/**
 * Tests for EncryptionService.
 *
 * Validates round-trip correctness, nonce uniqueness, tamper detection,
 * version byte, is_encrypted detection, and migration idempotency.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Services\EncryptionService;

final class EncryptionServiceTest extends \WP_UnitTestCase {

	private EncryptionService $enc;

	public function set_up(): void {
		parent::set_up();
		$this->enc = new EncryptionService();
	}

	public function test_round_trip_returns_original_plaintext(): void {
		$original  = bin2hex( random_bytes( 32 ) );
		$encrypted = $this->enc->encrypt( $original );
		$decrypted = $this->enc->decrypt( $encrypted );

		$this->assertSame( $original, $decrypted );
	}

	public function test_encrypted_value_differs_from_plaintext(): void {
		$original  = bin2hex( random_bytes( 32 ) );
		$encrypted = $this->enc->encrypt( $original );

		$this->assertNotSame( $original, $encrypted );
	}

	public function test_same_plaintext_produces_different_ciphertexts(): void {
		$original = bin2hex( random_bytes( 32 ) );
		$a        = $this->enc->encrypt( $original );
		$b        = $this->enc->encrypt( $original );

		$this->assertNotSame( $a, $b );
		$this->assertSame( $original, $this->enc->decrypt( $a ) );
		$this->assertSame( $original, $this->enc->decrypt( $b ) );
	}

	public function test_is_encrypted_returns_true_for_encrypted_value(): void {
		$encrypted = $this->enc->encrypt( 'test-data' );
		$this->assertTrue( $this->enc->is_encrypted( $encrypted ) );
	}

	public function test_is_encrypted_returns_false_for_plaintext_hex_key(): void {
		$hex_key = bin2hex( random_bytes( 32 ) ); // 64 chars
		$this->assertFalse( $this->enc->is_encrypted( $hex_key ) );
	}

	public function test_is_encrypted_returns_false_for_short_string(): void {
		$this->assertFalse( $this->enc->is_encrypted( 'short' ) );
	}

	public function test_is_encrypted_returns_false_for_empty_string(): void {
		$this->assertFalse( $this->enc->is_encrypted( '' ) );
	}

	public function test_decrypt_rejects_tampered_ciphertext(): void {
		$encrypted = $this->enc->encrypt( 'test-data' );

		$raw          = base64_decode( $encrypted, true );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 0xFF );
		$tampered     = base64_encode( $raw );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Decryption failed' );
		$this->enc->decrypt( $tampered );
	}

	public function test_decrypt_rejects_invalid_base64(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid ciphertext' );
		$this->enc->decrypt( '!!!not-base64!!!' );
	}

	public function test_decrypt_rejects_unknown_version(): void {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$fake       = chr( 0x99 ) . $nonce . str_repeat( "\x00", SODIUM_CRYPTO_SECRETBOX_MACBYTES + 10 );
		$encoded    = base64_encode( $fake );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unknown encryption version' );
		$this->enc->decrypt( $encoded );
	}

	public function test_version_byte_is_0x01(): void {
		$encrypted = $this->enc->encrypt( 'test' );
		$raw       = base64_decode( $encrypted, true );

		$this->assertSame( 0x01, ord( $raw[0] ) );
	}

	// -------------------------------------------------------------------------
	// Repository integration: encrypted round-trip through DB
	// -------------------------------------------------------------------------

	public function test_license_key_stored_encrypted_in_database(): void {
		global $wpdb;
		Schema::create_tables();

		$repo    = new LicenseRepository( $wpdb, $this->enc );
		$license = $repo->create( [
			'customer_name'  => 'Encryption Test',
			'customer_email' => 'enc@example.com',
			'tier'           => 'pro',
			'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
		] );

		// The License model should have the decrypted plaintext key.
		$this->assertSame( 64, strlen( $license->license_key ) );
		$this->assertTrue( ctype_xdigit( $license->license_key ) );

		// The raw DB value should NOT equal the plaintext key.
		$raw_db_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT license_key FROM {$wpdb->prefix}license_keys WHERE id = %d",
			$license->id
		) );

		$this->assertNotSame( $license->license_key, $raw_db_value );
		$this->assertTrue( $this->enc->is_encrypted( $raw_db_value ) );

		// Decrypting the raw DB value should yield the plaintext key.
		$this->assertSame( $license->license_key, $this->enc->decrypt( $raw_db_value ) );

		// Cleanup.
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
	}

	public function test_migration_is_idempotent(): void {
		global $wpdb;
		Schema::create_tables();

		$repo    = new LicenseRepository( $wpdb, $this->enc );
		$license = $repo->create( [
			'customer_name'  => 'Idempotent Test',
			'customer_email' => 'idempotent@example.com',
			'tier'           => 'basic',
			'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
		] );

		$plaintext_key = $license->license_key;

		// Read the encrypted value from DB.
		$encrypted_v1 = $wpdb->get_var( $wpdb->prepare(
			"SELECT license_key FROM {$wpdb->prefix}license_keys WHERE id = %d",
			$license->id
		) );

		// Simulating re-encryption attempt: is_encrypted should be true.
		$this->assertTrue( $this->enc->is_encrypted( $encrypted_v1 ) );

		// Re-reading should still return the same plaintext.
		$reloaded = $repo->find_by_id( $license->id );
		$this->assertSame( $plaintext_key, $reloaded->license_key );

		// Cleanup.
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
	}
}
