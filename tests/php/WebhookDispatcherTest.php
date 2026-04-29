<?php
/**
 * Tests for queued webhook dispatch.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Database\Schema;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Repositories\WebhookQueueRepository;
use WpLicenseServer\Services\KeyDerivationService;
use WpLicenseServer\Services\WebhookDispatcher;
use WpLicenseServer\Services\WebhookRetrySchedule;

final class WebhookDispatcherTest extends \WP_UnitTestCase {

    private LicenseRepository $license_repo;
    private ActivationRepository $activation_repo;
    private ActivityLogRepository $activity_repo;
    private WebhookQueueRepository $queue_repo;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        Schema::create_tables();

        $encryption            = new \WpLicenseServer\Services\EncryptionService();
        $this->license_repo    = new LicenseRepository( $wpdb, $encryption );
        $this->activation_repo = new ActivationRepository( $wpdb, $encryption );
        $this->activity_repo   = new ActivityLogRepository( $wpdb );
        $this->queue_repo      = new WebhookQueueRepository( $wpdb );

        // Mock DNS resolution to avoid real network calls
        if ( extension_loaded( 'uopz' ) ) {
            uopz_set_return( 'dns_get_record', function () { return []; }, true );
            uopz_set_return( 'gethostbynamel', function () { return ['1.2.3.4']; }, true );
        }
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_webhook_queue" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activity_log" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activations" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        wp_clear_scheduled_hook( WebhookDispatcher::CRON_HOOK );
        if ( extension_loaded( 'uopz' ) ) {
            uopz_unset_return( 'dns_get_record' );
            uopz_unset_return( 'gethostbynamel' );
        }
        parent::tear_down();
    }

    public function test_dispatch_pending_marks_job_sent_on_http_200(): void {
        $license    = $this->create_license();
        $activation = $this->activation_repo->create(
            array(
                'license_id' => $license->id,
                'domain'     => 'site.example',
            )
        );
        $job_id     = $this->queue_job( $license->id, $activation->domain, (string) $activation->webhook_secret, $license->key_prefix );
        $captured   = array();

        // Mock HTTP to avoid DNS pinning + real requests.
        add_filter( 'pre_http_request', $http_mock = static function ( $response, $args, $url ) use ( &$captured ): array {
            $captured = array(
                'url' => $url,
                'args' => $args,
            );

            return array(
                'response' => array(
                    'code' => 200,
                ),
                'body'     => '',
            );
        }, 10, 3 );



        $dispatcher = new WebhookDispatcher(
            $this->queue_repo,
            $this->license_repo,
            $this->activity_repo,
            new WebhookRetrySchedule(),
            new KeyDerivationService(),
        );

        $dispatcher->dispatch_pending();

        remove_filter( 'pre_http_request', $http_mock );

        $job = $this->queue_repo->find_by_id( $job_id );

        $this->assertNotNull( $job );
        $this->assertSame( 'sent', $job->status );
        $this->assertSame( 'https://site.example/?rest_route=/license-server/v1/webhook', $captured['url'] );

        $body = json_decode( $captured['args']['body'], true );
        // error_log( 'Body JSON: ' . json_encode( $body ) );

        $this->assertIsArray( $body );
        $this->assertSame( 'license.expired', $body['event'] );
        $this->assertSame( $license->key_prefix, $body['license_key_prefix'] );
        $key_derivation = new KeyDerivationService();
        $signing_key = $key_derivation->derive_webhook_key( $license->license_key );
        // error_log( 'License key: ' . $license->license_key );
        // error_log( 'License key prefix: ' . $license->key_prefix );
        $this->assertSame(
            hash_hmac(
                'sha256',
                implode(
                    "\n",
                    array(
                        $body['event'],
                        $body['event_id'],
                        $body['license_key_prefix'],
                        (string) $body['timestamp'],
                        $body['body_hash'],
                    )
                ),
                $signing_key
            ),
            $body['signature']
        );
    }

    public function test_dispatch_failure_increments_attempts_and_keeps_job_pending(): void {
        $license    = $this->create_license();
        $activation = $this->activation_repo->create(
            array(
                'license_id' => $license->id,
                'domain'     => 'retry.example',
            )
        );
        $job_id     = $this->queue_job( $license->id, $activation->domain, (string) $activation->webhook_secret, $license->key_prefix );

        // Mock HTTP to avoid DNS pinning + real requests.
        add_filter( 'pre_http_request', $http_mock = static function ( $response, $args, $url ): \WP_Error {
            return new \WP_Error( 'remote_down', 'Server unavailable' );
        }, 10, 3 );

        $dispatcher = new WebhookDispatcher(
            $this->queue_repo,
            $this->license_repo,
            $this->activity_repo,
            new WebhookRetrySchedule(),
            new KeyDerivationService(),
        );

        $dispatcher->dispatch_pending();

        remove_filter( 'pre_http_request', $http_mock );

        $job = $this->queue_repo->find_by_id( $job_id );

        $this->assertNotNull( $job );
        $this->assertSame( 'pending', $job->status );
        $this->assertSame( 1, $job->attempts );
        $this->assertNotNull( $job->last_attempt );
    }

    public function test_terminal_failure_marks_job_failed_and_emits_hook(): void {
        global $wpdb;

        $license    = $this->create_license();
        $activation = $this->activation_repo->create(
            array(
                'license_id' => $license->id,
                'domain'     => 'failed.example',
            )
        );
        $job_id     = $this->queue_job( $license->id, $activation->domain, (string) $activation->webhook_secret, $license->key_prefix );
        $emitted    = null;

        add_action(
            'wplicense_webhook_failed',
            static function ( $job ) use ( &$emitted ): void {
                $emitted = $job;
            }
        );

        $wpdb->update(
            $wpdb->prefix . 'license_webhook_queue',
            array(
                'attempts'     => 4,
                'last_attempt' => gmdate( 'Y-m-d H:i:s', time() - ( 13 * HOUR_IN_SECONDS ) ),
            ),
            array( 'id' => $job_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        // Mock HTTP to avoid DNS pinning + real requests.
        add_filter( 'pre_http_request', $http_mock = static function ( $response, $args, $url ): \WP_Error {
            return new \WP_Error( 'remote_down', 'Server unavailable' );
        }, 10, 3 );

        $dispatcher = new WebhookDispatcher(
            $this->queue_repo,
            $this->license_repo,
            $this->activity_repo,
            new WebhookRetrySchedule(),
            new KeyDerivationService(),
        );

        $dispatcher->dispatch_pending();

        remove_filter( 'pre_http_request', $http_mock );

        $job = $this->queue_repo->find_by_id( $job_id );
        $log = $this->activity_repo->get_by_license( $license->id );

        $this->assertNotNull( $job );
        $this->assertSame( 'failed', $job->status );
        $this->assertSame( 5, $job->attempts );
        $this->assertNotNull( $emitted );
        $this->assertSame( $job_id, $emitted->id );
        $this->assertCount( 1, $log['items'] );
        $this->assertSame( 'webhook_failed', $log['items'][0]->action );
    }

    public function test_dispatch_pending_scans_past_cooling_jobs_to_find_ready_work(): void {
        global $wpdb;

        $license = $this->create_license();

        for ( $i = 0; $i < 55; ++$i ) {
            $activation = $this->activation_repo->create(
                array(
                    'license_id' => $license->id,
                    'domain'     => 'cooldown-' . $i . '.example',
                )
            );
            $job_id     = $this->queue_job( $license->id, $activation->domain, (string) $activation->webhook_secret, $license->key_prefix );

            $wpdb->update(
                $wpdb->prefix . 'license_webhook_queue',
                array(
                    'attempts'     => 1,
                    'last_attempt' => gmdate( 'Y-m-d H:i:s' ),
                ),
                array( 'id' => $job_id ),
                array( '%d', '%s' ),
                array( '%d' )
            );
        }

        $ready_activation = $this->activation_repo->create(
            array(
                'license_id' => $license->id,
                'domain'     => 'ready.example',
            )
        );
        $ready_job_id     = $this->queue_job( $license->id, $ready_activation->domain, (string) $ready_activation->webhook_secret, $license->key_prefix );

        // Mock HTTP to avoid DNS pinning + real requests.
        add_filter( 'pre_http_request', $http_mock = static function ( $response, $args, $url ): array {
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => '',
            );
        }, 10, 3 );

        $dispatcher = new WebhookDispatcher(
            $this->queue_repo,
            $this->license_repo,
            $this->activity_repo,
            new WebhookRetrySchedule(),
            new KeyDerivationService(),
        );

        $dispatcher->dispatch_pending();

        remove_filter( 'pre_http_request', $http_mock );

        $ready_job = $this->queue_repo->find_by_id( $ready_job_id );
        $this->assertNotNull( $ready_job );
        $this->assertSame( 'sent', $ready_job->status );
    }

    private function create_license(): \WpLicenseServer\Models\License {
        return $this->license_repo->create(
            array(
                'customer_name'  => 'Dispatcher Test',
                'customer_email' => 'dispatcher@example.com',
                'tier'           => 'pro',
                'valid_until'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
            )
        );
    }

    private function queue_job( int $license_id, string $domain, string $webhook_secret, string $key_prefix ): int {
        global $wpdb;
        error_log( 'queue_job key_prefix: ' . $key_prefix );

        $this->queue_repo->insert(
            array(
                'license_id'     => $license_id,
                'domain'         => $domain,
                'webhook_secret' => $webhook_secret,
                'event'          => 'license.expired',
                'event_id'       => wp_generate_uuid4(),
                'payload'        => array(
                    'license_key_prefix' => $key_prefix,
                    'data'               => array(
                        'tier' => 'pro',
                    ),
                ),
            )
        );

        return (int) $wpdb->insert_id;
    }
}
