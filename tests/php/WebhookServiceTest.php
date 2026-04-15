<?php
/**
 * Tests for queued webhook creation.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Repositories\WebhookQueueRepository;
use WpLicenseServer\Services\WebhookService;

final class WebhookServiceTest extends \WP_UnitTestCase {

    private LicenseRepository $license_repo;
    private ActivationRepository $activation_repo;
    private WebhookQueueRepository $queue_repo;
    private WebhookService $service;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        Schema::create_tables();

        $encryption            = new \WpLicenseServer\Services\EncryptionService();
        $this->license_repo    = new LicenseRepository( $wpdb, $encryption );
        $this->activation_repo = new ActivationRepository( $wpdb, $encryption );
        $this->queue_repo      = new WebhookQueueRepository( $wpdb );
        $this->service         = new WebhookService( $this->license_repo, $this->activation_repo, $this->queue_repo );
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_webhook_queue" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activations" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        parent::tear_down();
    }

    public function test_queue_event_creates_one_job_per_active_activation(): void {
        $license = $this->create_license();

        $first  = $this->activation_repo->create(
            array(
                'license_id' => $license->id,
                'domain'     => 'alpha.example',
            )
        );
        $second = $this->activation_repo->create(
            array(
                'license_id' => $license->id,
                'domain'     => 'beta.example',
            )
        );
        $third  = $this->activation_repo->create(
            array(
                'license_id' => $license->id,
                'domain'     => 'gamma.example',
            )
        );

        $this->activation_repo->deactivate( $license->id, $third->domain );

        $this->service->queue_event(
            $license->id,
            'license.expired',
            array(
                'tier'             => 'pro',
                'valid_until'      => '2030-01-01 00:00:00',
                'features'         => array( 'chat', 'dashboard' ),
                'unsafe key name!' => '<script>alert(1)</script>',
            )
        );

        $jobs = $this->queue_repo->get_pending_batch( 10 );

        $this->assertCount( 2, $jobs );
        $this->assertSame( array( 'alpha.example', 'beta.example' ), wp_list_pluck( $jobs, 'domain' ) );
        $this->assertSame( $license->key_prefix, $jobs[0]->payload['license_key_prefix'] );
        $this->assertArrayNotHasKey( 'license_key', $jobs[0]->payload );
        $this->assertArrayHasKey( 'unsafekeyname', $jobs[0]->payload['data'] );
        $this->assertStringNotContainsString( $license->license_key, wp_json_encode( $jobs[0]->payload ) );
        $this->assertSame( $first->webhook_secret, $jobs[0]->webhook_secret );
        $this->assertSame( $second->webhook_secret, $jobs[1]->webhook_secret );
    }

    public function test_queue_event_skips_unknown_license(): void {
        $this->service->queue_event( 999999, 'license.expired', array( 'tier' => 'pro' ) );

        $this->assertCount( 0, $this->queue_repo->get_pending_batch( 10 ) );
    }

    public function test_queue_event_repairs_missing_webhook_secret_for_legacy_activation(): void {
        global $wpdb;

        $license    = $this->create_license();
        $activation = $this->activation_repo->create(
            array(
                'license_id' => $license->id,
                'domain'     => 'legacy.example',
            )
        );

        $wpdb->update(
            $wpdb->prefix . 'license_activations',
            array(
                'webhook_secret' => null,
            ),
            array(
                'id' => $activation->id,
            ),
            array( '%s' ),
            array( '%d' )
        );

        $this->service->queue_event( $license->id, 'license.expired', array( 'tier' => 'pro' ) );

        $jobs = $this->queue_repo->get_pending_batch( 10 );

        $this->assertCount( 1, $jobs );
        $this->assertNotEmpty( $jobs[0]->webhook_secret );

        $reloaded = $this->activation_repo->find_active( $license->id, 'legacy.example' );
        $this->assertNotNull( $reloaded );
        $this->assertNotEmpty( $reloaded->webhook_secret );
    }

    private function create_license(): \WpLicenseServer\Models\License {
        return $this->license_repo->create(
            array(
                'customer_name'  => 'Webhook Test',
                'customer_email' => 'webhook@example.com',
                'tier'           => 'pro',
                'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
            )
        );
    }
}
