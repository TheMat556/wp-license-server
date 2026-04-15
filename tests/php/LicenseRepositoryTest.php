<?php
/**
 * Tests for LicenseRepository.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\LicenseRepository;

final class LicenseRepositoryTest extends \WP_UnitTestCase {

    private LicenseRepository $repo;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        Schema::create_tables();
        $this->repo = new LicenseRepository( $wpdb, new \WpLicenseServer\Services\EncryptionService() );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        parent::tear_down();
    }

    public function test_create_generates_64_char_key(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Test User',
            'customer_email' => 'test@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $this->assertSame( 64, strlen( $license->license_key ) );
        $this->assertTrue( ctype_xdigit( $license->license_key ) );
    }

    public function test_create_sets_key_prefix_to_first_8_chars(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Test User',
            'customer_email' => 'test@example.com',
            'tier'           => 'basic',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $this->assertSame( 8, strlen( $license->key_prefix ) );
        $this->assertSame( substr( $license->license_key, 0, 8 ), $license->key_prefix );
    }

    public function test_find_by_key_prefix_returns_correct_license(): void {
        $created = $this->repo->create( [
            'customer_name'  => 'Find Me',
            'customer_email' => 'find@example.com',
            'tier'           => 'agency',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $found = $this->repo->find_by_key_prefix( $created->key_prefix );

        $this->assertNotNull( $found );
        $this->assertSame( $created->id, $found->id );
        $this->assertSame( $created->license_key, $found->license_key );
    }

    public function test_find_by_key_prefix_returns_null_for_unknown(): void {
        $found = $this->repo->find_by_key_prefix( '00000000' );
        $this->assertNull( $found );
    }

    public function test_find_all_returns_all_when_no_status_filter(): void {
        $this->repo->create( [
            'customer_name'  => 'User A',
            'customer_email' => 'a@example.com',
            'tier'           => 'basic',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );
        $this->repo->create( [
            'customer_name'  => 'User B',
            'customer_email' => 'b@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $all = $this->repo->find_all();
        $this->assertCount( 2, $all );
    }

    public function test_find_all_filters_by_status(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'User',
            'customer_email' => 'user@example.com',
            'tier'           => 'basic',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $this->repo->update_status( $license->id, 'expired' );

        $active  = $this->repo->find_all( 'active' );
        $expired = $this->repo->find_all( 'expired' );

        $this->assertCount( 0, $active );
        $this->assertCount( 1, $expired );
    }

    public function test_update_status_changes_status(): void {
        $license = $this->repo->create( [
            'customer_name'  => 'Status Test',
            'customer_email' => 'status@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $this->assertSame( 'active', $license->status );

        $result = $this->repo->update_status( $license->id, 'suspended' );
        $this->assertTrue( $result );

        $refreshed = $this->repo->find_by_key_prefix( $license->key_prefix );
        $this->assertSame( 'suspended', $refreshed->status );
    }

    public function test_find_by_key_prefix_returns_wp_error_on_corrupt_ciphertext(): void {
        global $wpdb;

        $license = $this->repo->create( [
            'customer_name'  => 'Corrupt Test',
            'customer_email' => 'corrupt@example.com',
            'tier'           => 'basic',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        // Build a value that passes is_encrypted() (version byte 0x01 + 40 garbage bytes)
        // but fails sodium_crypto_secretbox_open → triggers RuntimeException in decrypt().
        $corrupt = base64_encode( chr( 0x01 ) . str_repeat( "\x00", 64 ) );

        $wpdb->update(
            $wpdb->prefix . 'license_keys',
            [ 'license_key' => $corrupt ],
            [ 'id' => $license->id ],
            [ '%s' ],
            [ '%d' ]
        );

        $result = $this->repo->find_by_key_prefix( $license->key_prefix );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'license_decrypt_failed', $result->get_error_code() );
    }

    public function test_find_by_id_returns_wp_error_on_corrupt_ciphertext(): void {
        global $wpdb;

        $license = $this->repo->create( [
            'customer_name'  => 'Corrupt ID Test',
            'customer_email' => 'corruptid@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $corrupt = base64_encode( chr( 0x01 ) . str_repeat( "\x00", 64 ) );

        $wpdb->update(
            $wpdb->prefix . 'license_keys',
            [ 'license_key' => $corrupt ],
            [ 'id' => $license->id ],
            [ '%s' ],
            [ '%d' ]
        );

        $result = $this->repo->find_by_id( $license->id );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'license_decrypt_failed', $result->get_error_code() );
    }

    public function test_find_all_filters_out_corrupt_rows(): void {
        global $wpdb;

        $good = $this->repo->create( [
            'customer_name'  => 'Good License',
            'customer_email' => 'good@example.com',
            'tier'           => 'pro',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $bad = $this->repo->create( [
            'customer_name'  => 'Bad License',
            'customer_email' => 'bad@example.com',
            'tier'           => 'basic',
            'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
        ] );

        $corrupt = base64_encode( chr( 0x01 ) . str_repeat( "\x00", 64 ) );

        $wpdb->update(
            $wpdb->prefix . 'license_keys',
            [ 'license_key' => $corrupt ],
            [ 'id' => $bad->id ],
            [ '%s' ],
            [ '%d' ]
        );

        $results = $this->repo->find_all();

        $this->assertCount( 1, $results );
        $this->assertSame( $good->id, $results[0]->id );
    }
}
