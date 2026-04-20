<?php
/**
 * Tests for chat thread archive/delete mutations.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\ChatMessageRepository;
use WpLicenseServer\Repositories\ChatThreadRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Services\ChatService;
use WpLicenseServer\Services\EncryptionService;

final class ChatServiceTest extends \WP_UnitTestCase {

    private LicenseRepository $license_repo;
    private ActivationRepository $activation_repo;
    private ChatThreadRepository $thread_repo;
    private ChatMessageRepository $message_repo;
    private ChatService $service;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        Schema::create_tables();

        $encryption            = new EncryptionService();
        $this->license_repo    = new LicenseRepository( $wpdb, $encryption );
        $this->activation_repo = new ActivationRepository( $wpdb, $encryption );
        $activity_repo         = new ActivityLogRepository( $wpdb );
        $this->thread_repo     = new ChatThreadRepository( $wpdb );
        $this->message_repo    = new ChatMessageRepository( $wpdb );
        $this->service         = new ChatService(
            $wpdb,
            $this->thread_repo,
            $this->message_repo,
            $this->activation_repo,
            $activity_repo
        );
    }

    public function tear_down(): void {
        global $wpdb;

        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_chat_messages" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_chat_threads" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activity_log" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activations" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );

        parent::tear_down();
    }

    private function create_license( string $role = 'customer' ): \WpLicenseServer\Models\License {
        return $this->license_repo->create(
            [
                'customer_name'  => ucfirst( $role ) . ' Test',
                'customer_email' => "{$role}@example.com",
                'role'           => $role,
                'tier'           => 'pro',
                'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
            ]
        );
    }

    private function activate_license( \WpLicenseServer\Models\License $license, string $domain ): void {
        $this->activation_repo->create(
            [
                'license_id' => $license->id,
                'domain'     => $domain,
            ]
        );
    }

    public function test_archive_thread_marks_thread_closed(): void {
        $license = $this->create_license( 'customer' );
        $domain  = 'customer.example';

        $this->activate_license( $license, $domain );
        $thread = $this->thread_repo->ensure_customer_thread( $license, $domain );

        $result = $this->service->archive_thread( $license, $domain, $thread->id );

        $this->assertIsArray( $result );
        $this->assertSame( $thread->id, $result['selectedThreadId'] );
        $this->assertSame( 'closed', $this->thread_repo->find_by_id( $thread->id )?->status );
    }

    public function test_send_message_rejects_closed_thread(): void {
        $license = $this->create_license( 'customer' );
        $domain  = 'customer.example';

        $this->activate_license( $license, $domain );
        $thread = $this->thread_repo->ensure_customer_thread( $license, $domain );
        $this->thread_repo->update_status( $thread->id, 'closed' );

        $result = $this->service->send_message( $license, $domain, $thread->id, 'Hello' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'chat_thread_closed', $result->get_error_code() );
    }

    public function test_unarchive_thread_marks_thread_open(): void {
        $license = $this->create_license( 'customer' );
        $domain  = 'customer.example';

        $this->activate_license( $license, $domain );
        $thread = $this->thread_repo->ensure_customer_thread( $license, $domain );
        $this->thread_repo->update_status( $thread->id, 'closed' );

        $result = $this->service->unarchive_thread( $license, $domain, $thread->id );

        $this->assertIsArray( $result );
        $this->assertSame( $thread->id, $result['selectedThreadId'] );
        $this->assertSame( 'open', $this->thread_repo->find_by_id( $thread->id )?->status );
    }

    public function test_delete_thread_removes_thread_and_messages_for_owner(): void {
        $license = $this->create_license( 'owner' );
        $domain  = 'owner.example';

        $this->activate_license( $license, $domain );
        $thread = $this->thread_repo->ensure_customer_thread( $license, $domain );
        $this->message_repo->create( $thread->id, 'customer', 'Customer', 'Need help' );

        $result = $this->service->delete_thread( $license, $domain, $thread->id );

        $this->assertIsArray( $result );
        $this->assertNull( $this->thread_repo->find_by_id( $thread->id ) );
        $this->assertSame( [], $this->message_repo->find_for_thread( $thread->id ) );
        $this->assertSame( [], $result['threads'] );
        $this->assertNull( $result['selectedThreadId'] );
    }

    public function test_delete_thread_requires_owner_role(): void {
        $license = $this->create_license( 'customer' );
        $domain  = 'customer.example';

        $this->activate_license( $license, $domain );
        $thread = $this->thread_repo->ensure_customer_thread( $license, $domain );

        $result = $this->service->delete_thread( $license, $domain, $thread->id );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'chat_thread_forbidden', $result->get_error_code() );
    }
}
