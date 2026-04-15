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
    }

    public function tear_down(): void {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_webhook_queue" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activity_log" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_activations" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}license_keys" );
        wp_clear_scheduled_hook( WebhookDispatcher::CRON_HOOK );
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

        $dispatcher = new WebhookDispatcher(
            $this->queue_repo,
            $this->license_repo,
            $this->activity_repo,
            new WebhookRetrySchedule(),
            static function ( string $url, array $args ) use ( &$captured ): array {
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
            }
        );

        $dispatcher->dispatch_pending();

        $job = $this->queue_repo->find_by_id( $job_id );

        $this->assertNotNull( $job );
        $this->assertSame( 'sent', $job->status );
        $this->assertSame( 'https://site.example/wp-json/wp-react-ui/v1/license-webhook', $captured['url'] );
        $this->assertSame( $activation->webhook_secret, $captured['args']['headers']['X-Webhook-Secret'] );

        $body = json_decode( $captured['args']['body'], true );

        $this->assertIsArray( $body );
        $this->assertSame( 'license.expired', $body['event'] );
        $this->assertSame( $license->key_prefix, $body['license_key_prefix'] );
        $this->assertSame(
            hash_hmac(
                'sha256',
                implode(
                    "\n",
                    array(
                        $body['event'],
                        $body['license_key_prefix'],
                        (string) $body['timestamp'],
                        wp_json_encode( $body['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                    )
                ),
                $license->license_key
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

        $dispatcher = new WebhookDispatcher(
            $this->queue_repo,
            $this->license_repo,
            $this->activity_repo,
            new WebhookRetrySchedule(),
            static fn ( string $url, array $args ): \WP_Error => new \WP_Error( 'remote_down', 'Server unavailable' )
        );

        $dispatcher->dispatch_pending();

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

        $dispatcher = new WebhookDispatcher(
            $this->queue_repo,
            $this->license_repo,
            $this->activity_repo,
            new WebhookRetrySchedule(),
            static fn ( string $url, array $args ): \WP_Error => new \WP_Error( 'remote_down', 'Server unavailable' )
        );

        $dispatcher->dispatch_pending();

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

        $dispatcher = new WebhookDispatcher(
            $this->queue_repo,
            $this->license_repo,
            $this->activity_repo,
            new WebhookRetrySchedule(),
            static fn ( string $url, array $args ): array => array(
                'response' => array( 'code' => 200 ),
                'body'     => '',
            )
        );

        $dispatcher->dispatch_pending();

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

        $this->queue_repo->insert(
            array(
                'license_id'     => $license_id,
                'domain'         => $domain,
                'webhook_secret' => $webhook_secret,
                'event'          => 'license.expired',
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
