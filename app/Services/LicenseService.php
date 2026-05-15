<?php
/**
 * Core business logic for license operations.
 *
 * Orchestrates repositories, enforces business rules, and logs all actions.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

use WpLicenseServer\Contracts\ActivationRepositoryInterface;
use WpLicenseServer\Contracts\ActivityLogRepositoryInterface;
use WpLicenseServer\Contracts\LicenseRepositoryInterface;
use WpLicenseServer\Domain\LicenseState;
use WpLicenseServer\Domain\LicenseStateMachine;
use WpLicenseServer\Domain\LicenseTransitions;
use WpLicenseServer\ErrorCodes;
use WpLicenseServer\Models\License;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\ActivityLogRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Services\NotificationService;
use WpLicenseServer\Services\WebhookDispatcher;
use WpLicenseServer\Services\WebhookService;
use function __;

final class LicenseService {

    private const OWNER_LOCK_NAME = 'wp_license_server_single_owner';
    private const ALLOWED_ROLES = [ 'owner', 'customer' ];
    private const ALLOWED_STATUSES = [ 'active', 'expired', 'suspended', 'cancelled', 'locked' ];
    private const ALLOWED_PAYMENT_INTERVALS = [ 'monthly', 'yearly' ];
    private const ROTATION_TRANSITION_HOURS = 24;
    private WebhookTargetValidator $target_validator;

    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly LicenseRepositoryInterface $license_repo,
        private readonly ActivationRepositoryInterface $activation_repo,
        private readonly ActivityLogRepositoryInterface $activity_repo,
        WebhookTargetValidator $target_validator,
        private readonly ?WebhookService $webhook_service = null,
        private readonly ?LicenseStateMachine $state_machine = null,
        private readonly ?NotificationService $notification_service = null,
        private readonly ?WebhookDispatcher $webhook_dispatcher = null,
    ) {
        $this->target_validator = $target_validator;
    }

    /**
     * Create a new license.
     *
     * @param array{
     *     customer_name?: string,
     *     customer_email: string,
     *     role?: string,
     *     tier: string,
     *     valid_until: string,
     *     payment_interval?: string,
     *     auto_renewal?: bool,
     *     notes?: string,
     * } $data
     */
    public function create( array $data ): License|\WP_Error {
        // Validate tier.
        if ( ! TierConfig::is_valid_tier( $data['tier'] ?? '' ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_TIER->value,
                sprintf( __( 'Invalid tier. Allowed: %s.', 'wp-license-server' ), implode( ', ', TierConfig::valid_tiers() ) ),
                [ 'status' => 400 ]
            );
        }

        // Validate email.
        $email = sanitize_email( $data['customer_email'] ?? '' );
        if ( ! is_email( $email ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_EMAIL->value,
                __( 'A valid customer email is required.', 'wp-license-server' ),
                [ 'status' => 400 ]
            );
        }

        $role = sanitize_key( (string) ( $data['role'] ?? 'customer' ) );
        if ( ! in_array( $role, self::ALLOWED_ROLES, true ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_ROLE->value,
                __( 'License role must be owner or customer.', 'wp-license-server' ),
                [ 'status' => 400 ]
            );
        }

        $payment_interval = sanitize_key( (string) ( $data['payment_interval'] ?? 'yearly' ) );
        if ( ! in_array( $payment_interval, self::ALLOWED_PAYMENT_INTERVALS, true ) ) {
            return new \WP_Error(
                ErrorCodes::INVALID_PAYMENT_INTERVAL->value,
                __( 'Payment interval must be monthly or yearly.', 'wp-license-server' ),
                [ 'status' => 400 ]
            );
        }

        // Validate valid_until is a future date.
        $valid_until = sanitize_text_field( $data['valid_until'] ?? '' );
        if ( empty( $valid_until ) ) {
            return new \WP_Error(
                ErrorCodes::MISSING_VALID_UNTIL->value,
                __( 'The valid_until date is required.', 'wp-license-server' ),
                [ 'status' => 400 ]
            );
        }

        $expiry = $this->parse_valid_until( $valid_until );

        if ( is_wp_error( $expiry ) ) {
            return $expiry;
        }

        if ( $expiry <= new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) ) {
            return new \WP_Error(
                ErrorCodes::DATE_IN_PAST->value,
                __( 'The valid_until date must be in the future.', 'wp-license-server' ),
                [ 'status' => 400 ]
            );
        }

        $data['customer_email'] = $email;
        $data['role']           = $role;
        $data['payment_interval'] = $payment_interval;
        $data['valid_until']    = $expiry->format( 'Y-m-d H:i:s' );

        $create_license = function () use ( $data ): License|\WP_Error {
            $existing_owner = $this->license_repo->find_owner();
            if ( is_wp_error( $existing_owner ) ) {
                return $existing_owner;
            }
            if ( $existing_owner ) {
                return new \WP_Error(
                    ErrorCodes::OWNER_EXISTS->value,
                    __( 'Only one owner license can exist at a time. Change the existing owner before assigning another one.', 'wp-license-server' ),
                    [ 'status' => 409 ]
                );
            }

            return $this->license_repo->create( $data );
        };

        if ( 'owner' === $role ) {
            $license = $this->with_owner_lock( $create_license );
        } else {
            $license = $this->license_repo->create( $data );
        }

        if ( is_wp_error( $license ) ) {
            return $license;
        }

        // Determine actor.
        $actor = 'system';
        $user  = wp_get_current_user();
        if ( $user->exists() ) {
            $actor = 'admin:' . $user->user_login;
        }

        $this->activity_repo->insert( [
            'license_id' => $license->id,
            'action'     => 'created',
            'actor'      => $actor,
            'details'    => [
                'tier'        => $license->tier,
                'role'        => $license->role,
                'valid_until' => $license->valid_until,
            ],
        ] );

        return $license;
    }

    /**
     * Update an existing license from the admin interface.
     *
     * @param array{
     *     customer_name?: string,
     *     customer_email?: string,
     *     role?: string,
     *     tier?: string,
     *     status?: string,
     *     valid_until?: string,
     *     payment_interval?: string,
     *     auto_renewal?: bool|int|string,
     *     max_activations?: int,
     *     notes?: string|null,
     * } $data
     */
    public function update( int $license_id, array $data ): License|\WP_Error {
        $license = $this->license_repo->find_by_id( $license_id );

        if ( is_wp_error( $license ) ) {
            return $license;
        }

        if ( ! $license ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_FOUND->value,
                __( 'License not found.', 'wp-license-server' ),
                [ 'status' => 404 ]
            );
        }

        $normalized = [];

        if ( array_key_exists( 'customer_name', $data ) ) {
            $normalized['customer_name'] = sanitize_text_field( (string) $data['customer_name'] );
        }

        if ( array_key_exists( 'customer_email', $data ) ) {
            $email = sanitize_email( (string) $data['customer_email'] );
            if ( ! is_email( $email ) ) {
                return new \WP_Error(
                    ErrorCodes::INVALID_EMAIL->value,
                    __( 'A valid customer email is required.', 'wp-license-server' ),
                    [ 'status' => 400 ]
                );
            }

            $normalized['customer_email'] = $email;
        }

        if ( array_key_exists( 'role', $data ) ) {
            $role = sanitize_key( (string) $data['role'] );
            if ( ! in_array( $role, self::ALLOWED_ROLES, true ) ) {
                return new \WP_Error(
                    ErrorCodes::INVALID_ROLE->value,
                    __( 'License role must be owner or customer.', 'wp-license-server' ),
                    [ 'status' => 400 ]
                );
            }

            $normalized['role'] = $role;
        }

        if ( array_key_exists( 'tier', $data ) ) {
            $tier = sanitize_text_field( (string) $data['tier'] );
            if ( ! TierConfig::is_valid_tier( $tier ) ) {
                return new \WP_Error(
                    ErrorCodes::INVALID_TIER->value,
                    sprintf( __( 'Invalid tier. Allowed: %s.', 'wp-license-server' ), implode( ', ', TierConfig::valid_tiers() ) ),
                    [ 'status' => 400 ]
                );
            }

            $normalized['tier'] = $tier;
        }

        if ( array_key_exists( 'status', $data ) ) {
            $status = sanitize_key( (string) $data['status'] );
            if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
                return new \WP_Error(
                    ErrorCodes::INVALID_STATUS->value,
                    __( 'License status must be active, expired, suspended, or cancelled.', 'wp-license-server' ),
                    [ 'status' => 400 ]
                );
            }

            // Enforce the transition matrix — prevents arbitrary status jumps.
            $transition_check = LicenseTransitions::validate( $license->status, $status );
            if ( is_wp_error( $transition_check ) ) {
                return $transition_check;
            }

            $normalized['status'] = $status;
        }

        if ( array_key_exists( 'payment_interval', $data ) ) {
            $payment_interval = sanitize_key( (string) $data['payment_interval'] );
            if ( ! in_array( $payment_interval, self::ALLOWED_PAYMENT_INTERVALS, true ) ) {
                return new \WP_Error(
                    ErrorCodes::INVALID_PAYMENT_INTERVAL->value,
                    __( 'Payment interval must be monthly or yearly.', 'wp-license-server' ),
                    [ 'status' => 400 ]
                );
            }

            $normalized['payment_interval'] = $payment_interval;
        }

        if ( array_key_exists( 'auto_renewal', $data ) ) {
            $normalized['auto_renewal'] = rest_sanitize_boolean( $data['auto_renewal'] );
        }

        if ( array_key_exists( 'notes', $data ) ) {
            $notes               = is_string( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '';
            $normalized['notes'] = '' === $notes ? null : $notes;
        }

        if ( array_key_exists( 'valid_until', $data ) ) {
            $expiry = $this->parse_valid_until( sanitize_text_field( (string) $data['valid_until'] ) );

            if ( is_wp_error( $expiry ) ) {
                return $expiry;
            }

            $normalized['valid_until'] = $expiry->format( 'Y-m-d H:i:s' );
        }

        $next_status      = $normalized['status'] ?? $license->status;
        $next_valid_until = $normalized['valid_until'] ?? $license->valid_until;
        $next_expiry      = new \DateTime( $next_valid_until, new \DateTimeZone( 'UTC' ) );

        if ( 'active' === $next_status && $next_expiry <= new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) ) {
            return new \WP_Error(
                ErrorCodes::DATE_IN_PAST->value,
                __( 'Active licenses must have a future valid_until date.', 'wp-license-server' ),
                [ 'status' => 400 ]
            );
        }

        $max_activations = null;
        if ( array_key_exists( 'max_activations', $data ) ) {
            $max_activations = max( 1, absint( $data['max_activations'] ) );
        } elseif ( isset( $normalized['tier'] ) ) {
            $max_activations = TierConfig::max_activations_for_tier( $normalized['tier'] );
        }

        if ( null !== $max_activations ) {
            $current_activations = $this->activation_repo->count_active( $license->id );
            if ( $max_activations < $current_activations ) {
                return new \WP_Error(
                    ErrorCodes::INVALID_MAX_ACTIVATIONS->value,
                    __( 'Maximum activations cannot be lower than the number of active domains.', 'wp-license-server' ),
                    [ 'status' => 400 ]
                );
            }

            $normalized['max_activations'] = $max_activations;
        }

        if ( empty( $normalized ) ) {
            return new \WP_Error(
                ErrorCodes::EMPTY_UPDATE->value,
                __( 'No editable license fields were provided.', 'wp-license-server' ),
                [ 'status' => 400 ]
            );
        }

        $perform_update = function () use ( $license, $normalized ): bool|\WP_Error {
            if ( isset( $normalized['role'] ) && 'owner' === $normalized['role'] ) {
                $existing_owner = $this->license_repo->find_owner( $license->id );
                if ( is_wp_error( $existing_owner ) ) {
                    return $existing_owner;
                }
                if ( $existing_owner ) {
                    return new \WP_Error(
                        ErrorCodes::OWNER_EXISTS->value,
                        __( 'Only one owner license can exist at a time. Change the existing owner before assigning another one.', 'wp-license-server' ),
                        [ 'status' => 409 ]
                    );
                }

            }

            if ( ! $this->license_repo->update( $license->id, $normalized ) ) {
                return new \WP_Error(
                    ErrorCodes::UPDATE_FAILED->value,
                    __( 'The license could not be updated.', 'wp-license-server' ),
                    [ 'status' => 500 ]
                );
            }

            return true;
        };

        $update_result =
            isset( $normalized['role'] ) && 'owner' === $normalized['role']
                ? $this->with_owner_lock( $perform_update )
                : $perform_update();

        if ( is_wp_error( $update_result ) ) {
            return $update_result;
        }

        $updated = $this->license_repo->find_by_id( $license->id );
        if ( is_wp_error( $updated ) ) {
            return $updated;
        }
        if ( ! $updated ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_FOUND->value,
                __( 'License not found after update.', 'wp-license-server' ),
                [ 'status' => 404 ]
            );
        }

        $this->activity_repo->insert( [
            'license_id' => $updated->id,
            'action'     => 'updated',
            'actor'      => $this->current_actor(),
            'details'    => $normalized,
        ] );

        // Log a dedicated status_changed entry when status actually changed.
        if ( isset( $normalized['status'] ) && $normalized['status'] !== $license->status ) {
            $this->activity_repo->insert( [
                'license_id' => $updated->id,
                'action'     => 'status_changed',
                'actor'      => $this->current_actor(),
                'details'    => [
                    'from'  => $license->status,
                    'to'    => $normalized['status'],
                    'actor' => get_current_user_id(),
                ],
            ] );
        }

        return $updated;
    }

    /**
     * Activate a license on a domain.
     *
     * @param array<string, string|null> $versions
     * @return array{status: string, license: array<string, mixed>, webhook_secret: string}|\WP_Error
     */
    public function activate( string $key_prefix, string $domain, array $versions = [] ): array|\WP_Error {
        // Validate that the domain is public and reachable (SSRF prevention).
        $validated_domain = $this->target_validator->validate_public_domain( $domain );
        if ( is_wp_error( $validated_domain ) ) {
            return $validated_domain;
        }
        $domain = $validated_domain;

        $license = $this->license_repo->find_by_key_prefix( $key_prefix );
        if ( is_wp_error( $license ) ) {
            return $license;
        }
        if ( ! $license ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_FOUND->value,
                __( 'License key not recognized.', 'wp-license-server' ),
                [ 'status' => 403 ]
            );
        }

        $state = $this->state_machine->compute_state( $license );
        if ( $state === LicenseState::Locked ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_LOCKED->value,
                __( 'License has been locked.', 'wp-license-server' ),
                [
                    'status'         => 403,
                    'license_status' => 'locked',
                ]
            );
        }

        if ( ! $state->is_usable() ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_VALID->value,
                sprintf( __( 'License is %s.', 'wp-license-server' ), $this->translate_status( $license->status ) ),
                [
                    'status'      => 403,
                    'license_status' => $license->status,
                    'valid_until' => $license->valid_until,
                ]
            );
        }

        // Optimistic pre-check: avoids transaction overhead for obvious duplicates.
        $existing = $this->activation_repo->find_active( $license->id, $domain );

        if ( $existing ) {
            return new \WP_Error(
                ErrorCodes::ALREADY_ACTIVATED->value,
                sprintf( __( 'License is already activated on %s.', 'wp-license-server' ), $domain ),
                [ 'status' => 409 ]
            );
        }

        // Lock the license row so concurrent requests cannot both pass the
        // seat-count check.  SELECT ... FOR UPDATE requires InnoDB and an
        // active transaction.
        $this->wpdb->query( 'START TRANSACTION' );
        try {
            $locked = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, max_activations FROM {$this->wpdb->prefix}license_keys
                 WHERE id = %d FOR UPDATE",
                $license->id
            )
        );

        if ( ! $locked ) {
            $this->wpdb->query( 'ROLLBACK' );
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_FOUND->value,
                __( 'License not found.', 'wp-license-server' ),
                [ 'status' => 404 ]
            );
        }

        // Re-check inside the lock: a concurrent request may have just
        // activated this domain between the optimistic check above and now.
        $existing_in_tx = $this->activation_repo->find_active( $license->id, $domain );
        if ( $existing_in_tx ) {
            $this->wpdb->query( 'ROLLBACK' );
            return new \WP_Error(
                ErrorCodes::ALREADY_ACTIVATED->value,
                sprintf( __( 'License is already activated on %s.', 'wp-license-server' ), $domain ),
                [ 'status' => 409 ]
            );
        }

        // Authoritative count inside the exclusive lock.
        $current_count   = $this->activation_repo->count_active( $license->id );
        $max_activations = (int) $locked->max_activations;

        if ( $current_count >= $max_activations ) {
            $active = $this->activation_repo->get_all_active( $license->id );
            $this->wpdb->query( 'ROLLBACK' );

            // Log the blocked attempt after rollback so it is always visible.
            $this->activity_repo->insert( [
                'license_id' => $license->id,
                'action'     => 'activation_limit_blocked',
                'domain'     => $domain,
                'actor'      => 'api:' . $domain,
                'details'    => [
                    'max_activations' => $max_activations,
                    'current_count'   => $current_count,
                ],
            ] );

            return new \WP_Error(
                ErrorCodes::ACTIVATION_LIMIT_REACHED->value,
                sprintf(
                    __( 'Maximum activations (%d) reached. Deactivate a domain first.', 'wp-license-server' ),
                    $max_activations
                ),
                [
                    'status'          => 403,
                    'max_activations' => $max_activations,
                    'active_count'    => count( $active ),
                ]
            );
        }

        $activation = $this->activation_repo->create( [
            'license_id'     => $license->id,
            'domain'         => $domain,
            'plugin_version' => $versions['plugin_version'] ?? null,
            'wp_version'     => $versions['wp_version'] ?? null,
            'php_version'    => $versions['php_version'] ?? null,
        ] );

        if ( is_wp_error( $activation ) ) {
            $this->wpdb->query( 'ROLLBACK' );
            return $activation;
        }

        $this->wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $this->wpdb->query( 'ROLLBACK' );
            throw $e;
        }

        // Activity log and response are outside the transaction to keep the
        // lock window as short as possible.
        $this->activity_repo->insert( [
            'license_id' => $license->id,
            'action'     => 'activated',
            'domain'     => $domain,
            'actor'      => 'api:' . $domain,
            'details'    => array_filter( $versions ),
        ] );

        // Fire new-domain activation alert (email + webhook) on first activation of this domain.
        if ( null !== $this->notification_service ) {
            $is_new_domain = $this->activation_repo->count_by_license_and_domain(
                $license->id,
                $domain
            ) === 1;

            if ( $is_new_domain ) {
                $ip_resolver = new \WpLicenseServer\Services\IpResolver();
                $client_ip   = $ip_resolver->get_client_ip();
                $this->notification_service->on_new_activation(
                    $license->id,
                    $license->customer_email,
                    $domain,
                    $client_ip,
                    (string) $activation->id
                );
            }
        }

        $features = TierConfig::features_for_tier( $license->tier );

        return [
            'status'  => 'activated',
            'license' => [
                'role'                => $license->role,
                'tier'                => $license->tier,
                'valid_until'         => $license->valid_until,
                'max_activations'     => $max_activations,
                'current_activations' => $current_count + 1,
                'features'            => $features,
            ],
            'webhook_secret' => $activation->webhook_secret,
        ];
    }

    /**
     * Validate a license (heartbeat).
     *
     * @param array<string, string|null> $versions
     * @return array{status: string, license: array<string, mixed>, webhook_secret?: string}|\WP_Error
     */
    public function validate( string $key_prefix, string $domain, array $versions = [] ): array|\WP_Error {
        // Validate that the domain is public and reachable (SSRF prevention).
        $validated_domain = $this->target_validator->validate_public_domain( $domain );
        if ( is_wp_error( $validated_domain ) ) {
            return $validated_domain;
        }
        $domain = $validated_domain;

        $license = $this->license_repo->find_by_key_prefix( sanitize_text_field( $key_prefix ) );
        if ( is_wp_error( $license ) ) {
            return $license;
        }
        if ( ! $license ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_FOUND->value,
                __( 'License key not recognized.', 'wp-license-server' ),
                [ 'status' => 403 ]
            );
        }

        $activation = $this->activation_repo->find_active( $license->id, $domain );

        if ( ! $activation ) {
            return new \WP_Error(
                ErrorCodes::NOT_ACTIVATED->value,
                sprintf( __( 'License is not activated on %s.', 'wp-license-server' ), $domain ),
                [ 'status' => 403 ]
            );
        }

        // Rotate the webhook secret on every heartbeat (M4).
        $new_secret = bin2hex( random_bytes( 16 ) );
        $rotated    = $this->activation_repo->rotate_webhook_secret( $activation->id, $new_secret );

        if ( ! $rotated ) {
            // Fallback: try to ensure a secret exists even if rotation failed.
            $new_secret = $this->activation_repo->ensure_webhook_secret( $activation->id );
        }

        $webhook_secret = $new_secret;

        if ( null === $webhook_secret || '' === $webhook_secret ) {
            return new \WP_Error(
                ErrorCodes::ACTIVATION_SECRET_UNAVAILABLE->value,
                __( 'Activation webhook secret could not be initialized.', 'wp-license-server' ),
                array( 'status' => 500 )
            );
        }

        // Update heartbeat.
        $this->activation_repo->update_heartbeat( $activation->id, $versions );

        // Determine effective license state.
        $state    = $this->state_machine->compute_state( $license );
        $features = TierConfig::features_for_tier( $license->tier );

        if ( $state === LicenseState::Locked ) {
            return [
                'status'  => 'locked',
                'license' => [
                    'role'        => $license->role,
                    'tier'        => $license->tier,
                    'valid_until' => $license->valid_until,
                    'features'    => $features,
                ],
                // No webhook_secret — locked licenses do not receive secrets.
            ];
        }

        if ( $state === LicenseState::Suspended || $state === LicenseState::Cancelled ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_VALID->value,
                sprintf( __( 'License is %s.', 'wp-license-server' ), $this->translate_status( $state->value ) ),
                [
                    'status'         => 403,
                    'license_status' => $state->value,
                ]
            );
        }

        if ( $state === LicenseState::Grace ) {
            $grace_deadline      = $this->state_machine->grace_deadline( $license );
            $now_immutable       = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
            $grace_days_remaining = max( 0, (int) $now_immutable->diff( $grace_deadline )->days );
            return [
                'status'  => 'grace',
                'license' => [
                    'role'                => $license->role,
                    'tier'                => $license->tier,
                    'valid_until'         => $license->valid_until,
                    'features'            => $features,
                    'grace_days_remaining' => $grace_days_remaining,
                ],
                'webhook_secret' => $webhook_secret,
            ];
        }

        if ( $state === LicenseState::Expired ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_EXPIRED->value,
                __( 'License has expired and the grace period has ended.', 'wp-license-server' ),
                [
                    'status'      => 403,
                    'valid_until' => $license->valid_until,
                ]
            );
        }

        // LicenseState::Active — fall through to activity log + valid response.

        $this->activity_repo->insert( [
            'license_id' => $license->id,
            'action'     => 'validated',
            'domain'     => $domain,
            'actor'      => 'api:' . $domain,
        ] );

        return [
            'status'  => 'valid',
            'license' => [
                'role'                => $license->role,
                'tier'                => $license->tier,
                'valid_until'         => $license->valid_until,
                'max_activations'     => $license->max_activations,
                'current_activations' => $this->activation_repo->count_active( $license->id ),
                'features'            => $features,
            ],
            'webhook_secret' => $webhook_secret,
        ];
    }

    /**
     * Deactivate a license from a domain.
     */
    public function deactivate( string $key_prefix, string $domain ): bool|\WP_Error {
        $license = $this->license_repo->find_by_key_prefix( sanitize_text_field( $key_prefix ) );
        if ( is_wp_error( $license ) ) {
            return $license;
        }
        if ( ! $license ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_FOUND->value,
                __( 'License key not recognized.', 'wp-license-server' ),
                [ 'status' => 403 ]
            );
        }

        $domain = $this->normalize_domain( $domain );

        $activation = $this->activation_repo->find_active( $license->id, $domain );

        if ( ! $activation ) {
            return new \WP_Error(
                ErrorCodes::NOT_ACTIVATED->value,
                sprintf( __( 'No active activation found for domain %s.', 'wp-license-server' ), $domain ),
                [ 'status' => 404 ]
            );
        }

        $success = $this->activation_repo->deactivate( $license->id, $domain );

        if ( $success ) {
            $this->activity_repo->insert( [
                'license_id' => $license->id,
                'action'     => 'deactivated',
                'domain'     => $domain,
                'actor'      => 'api:' . $domain,
            ] );
        }

        return $success;
    }

    /**
     * Lock a license, preventing all client access.
     *
     * @return License|\WP_Error
     */
    public function lock( int $license_id ): License|\WP_Error {
        $license = $this->license_repo->find_by_id( $license_id );

        if ( is_wp_error( $license ) ) {
            return $license;
        }
        if ( ! $license ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_FOUND->value,
                __( 'License not found.', 'wp-license-server' ),
                [ 'status' => 404 ]
            );
        }

        // Owner licenses cannot be locked — protects the site owner.
        if ( 'owner' === $license->role ) {
            return new \WP_Error(
                ErrorCodes::OWNER_LOCK_IMMUNE->value,
                __( 'Owner licenses cannot be locked.', 'wp-license-server' ),
                [ 'status' => 403 ]
            );
        }

            if ( $license->status === 'locked' ) {
                // Rate-limit: only re-queue once per 5 minutes per license to
                // prevent CSRF amplification from nonce-bearing GET requests.
                $rate_key = 'wplicense_lock_requeue_' . $license_id;
                if ( get_transient( $rate_key ) ) {
                    return new \WP_Error(
                        ErrorCodes::RATE_LIMIT_EXCEEDED->value,
                        __( 'Lock re-queue rate-limited. Try again later.', 'wp-license-server' ),
                        [ 'status' => 429 ]
                    );
                }
                set_transient( $rate_key, 1, 5 * MINUTE_IN_SECONDS );

                // Already locked; re-queue the webhook so clients that missed
                // the original notification (e.g. due to a temporary delivery
                // failure) receive it on this retry.
                $this->activity_repo->insert( [
                    'license_id' => $license->id,
                    'action'     => 'locked_requeue',
                    'actor'      => $this->current_actor(),
                    'details'    => [
                        'pre_lock_status' => $license->pre_lock_status ?? 'active',
                    ],
                ] );

                if ( null !== $this->webhook_service ) {
                    $this->webhook_service->queue_event(
                        $license_id,
                        'license.locked',
                        [ 'pre_lock_status' => $license->pre_lock_status ?? 'active' ]
                    );
                }

                // Queue async dispatch instead of blocking on synchronous delivery.
                self::schedule_async_dispatch();

                return $license;
            }

        $transition_check = LicenseTransitions::validate( $license->status, 'locked' );
        if ( is_wp_error( $transition_check ) ) {
            return $transition_check;
        }

        $this->wpdb->query( 'START TRANSACTION' );
        try {
            if ( ! $this->license_repo->lock( $license->id, $license->status ) ) {
                $this->wpdb->query( 'ROLLBACK' );
                return new \WP_Error(
                    ErrorCodes::LOCK_FAILED->value,
                    __( 'The license could not be locked.', 'wp-license-server' ),
                    [ 'status' => 500 ]
                );
            }

            $this->activity_repo->insert( [
                'license_id' => $license->id,
                'action'     => 'locked',
                'actor'      => $this->current_actor(),
                'details'    => [
                    'pre_lock_status' => $license->status,
                ],
            ] );

            if ( null !== $this->webhook_service ) {
                $this->webhook_service->queue_event(
                    $license_id,
                    'license.locked',
                    [ 'pre_lock_status' => $license->status ]
                );
            }

            $this->wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $this->wpdb->query( 'ROLLBACK' );
            throw $e;
        }

        // Queue async dispatch instead of blocking on synchronous delivery.
        self::schedule_async_dispatch();

        $updated = $this->license_repo->find_by_id( $license->id );
        return $updated instanceof License ? $updated : new \WP_Error(
            ErrorCodes::LOCK_FAILED->value,
            __( 'License locked but could not be re-read.', 'wp-license-server' ),
            [ 'status' => 500 ]
        );
    }

    /**
     * Unlock a license, restoring the pre-lock status.
     *
     * The Service is the single source of truth for the restore decision.
     * pre_lock_status is validated; if corrupt/missing, falls back to
     * 'active' and logs a warning. The Repository receives the resolved
     * value via unlock($id, $restore_to) — no split-brain.
     *
     * @return License|\WP_Error
     */
    public function unlock( int $license_id ): License|\WP_Error {
        $license = $this->license_repo->find_by_id( $license_id );

        if ( is_wp_error( $license ) ) {
            return $license;
        }
        if ( ! $license ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_FOUND->value,
                __( 'License not found.', 'wp-license-server' ),
                [ 'status' => 404 ]
            );
        }

        if ( $license->status !== 'locked' ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_LOCKED->value,
                __( 'License is not locked.', 'wp-license-server' ),
                [ 'status' => 409 ]
            );
        }

        // Determine the status to restore (single source of truth).
        $restored_status = $license->pre_lock_status;
        $log_details     = [ 'restored_status' => $restored_status ];

        if ( empty( $restored_status ) || ! in_array( $restored_status, self::ALLOWED_STATUSES, true ) ) {
            $restored_status = 'active';
            $log_details     = [
                'restored_status'   => 'active',
                'pre_lock_fallback' => true,
                'message'           => 'pre_lock_status was invalid or missing, defaulted to active',
            ];

            $this->activity_repo->insert( [
                'license_id' => $license->id,
                'action'     => 'warning',
                'actor'      => $this->current_actor(),
                'details'    => $log_details,
            ] );
        }

        // Pass resolved status to repo — repo does not read pre_lock_status.
        $this->wpdb->query( 'START TRANSACTION' );
        try {
            if ( ! $this->license_repo->unlock( $license->id, $restored_status ) ) {
                $this->wpdb->query( 'ROLLBACK' );
                return new \WP_Error(
                    ErrorCodes::UNLOCK_FAILED->value,
                    __( 'The license could not be unlocked.', 'wp-license-server' ),
                    [ 'status' => 500 ]
                );
            }

            $this->activity_repo->insert( [
                'license_id' => $license->id,
                'action'     => 'unlocked',
                'actor'      => $this->current_actor(),
                'details'    => $log_details,
            ] );

            if ( null !== $this->webhook_service ) {
                $this->webhook_service->queue_event(
                    $license_id,
                    'license.unlocked',
                    [ 'restored_status' => $restored_status ]
                );
            }

            $this->wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $this->wpdb->query( 'ROLLBACK' );
            throw $e;
        }

        // Queue async dispatch instead of blocking on synchronous delivery.
        self::schedule_async_dispatch();

        $updated = $this->license_repo->find_by_id( $license->id );
        return $updated instanceof License ? $updated : new \WP_Error(
            ErrorCodes::UNLOCK_FAILED->value,
            __( 'License unlocked but could not be re-read.', 'wp-license-server' ),
            [ 'status' => 500 ]
        );
    }

    /**
     * Rotate a license key.
     *
     * Generates a new key, archives the old one for a 24h transition window,
     * bumps the key_version, and queues a webhook to notify active sites.
     *
     * @return array{new_key: string, new_prefix: string, key_version: int, transition_until: string}|\WP_Error
     */
    public function rotate_key( int $license_id ): array|\WP_Error {
        $license = $this->license_repo->find_by_id( $license_id );

        if ( is_wp_error( $license ) ) {
            return $license;
        }

        if ( ! $license ) {
            return new \WP_Error(
                ErrorCodes::LICENSE_NOT_FOUND->value,
                __( 'License not found.', 'wp-license-server' ),
                [ 'status' => 404 ]
            );
        }

        // Prevent rotation if one is already in progress.
        if ( null !== $license->rotation_at ) {
            $rotation_expiry = strtotime( $license->rotation_at ) + ( self::ROTATION_TRANSITION_HOURS * 3600 );
            if ( $rotation_expiry > time() ) {
                return new \WP_Error(
                    ErrorCodes::ROTATION_IN_PROGRESS->value,
                    sprintf(
                        __( 'A key rotation is already in progress. The transition window expires at %s.', 'wp-license-server' ),
                        gmdate( 'c', $rotation_expiry )
                    ),
                    [ 'status' => 409 ]
                );
            }
        }

        $new_key    = bin2hex( random_bytes( 32 ) );
        $new_prefix = substr( $new_key, 0, 8 );

        // Ensure new prefix is unique.
        $existing = $this->license_repo->find_by_key_prefix( $new_prefix );
        if ( ! is_wp_error( $existing ) && $existing && $existing->id !== $license_id ) {
            // Extremely unlikely collision — regenerate once.
            $new_key    = bin2hex( random_bytes( 32 ) );
            $new_prefix = substr( $new_key, 0, 8 );
        }

        $old_key     = $license->license_key;
        $new_version = $license->key_version + 1;

        $stored = $this->license_repo->store_rotation(
            $license_id,
            old_key:     $old_key,
            new_key:     $new_key,
            new_prefix:  $new_prefix,
            new_version: $new_version,
        );

        if ( ! $stored ) {
            return new \WP_Error(
                ErrorCodes::ROTATION_FAILED->value,
                __( 'Failed to store the rotated key.', 'wp-license-server' ),
                [ 'status' => 500 ]
            );
        }

        $transition_until = gmdate( 'c', time() + ( self::ROTATION_TRANSITION_HOURS * 3600 ) );

        // Queue webhook to notify all active sites.
        if ( null !== $this->webhook_service ) {
            $this->webhook_service->queue_event( $license_id, 'license.key_rotated', [
                'new_key_prefix'   => $new_prefix,
                'key_version'      => $new_version,
                'transition_until' => $transition_until,
            ] );
        }

        // Use async dispatch so the admin REST request doesn't block on
        // up to 20 webhook deliveries at 8s each (~160s potential wait).
        self::schedule_async_dispatch();

        // Schedule cron to clean up the old key after the transition window.
        if ( ! wp_next_scheduled( 'wplicense_cleanup_rotation', [ $license_id ] ) ) {
            wp_schedule_single_event(
                time() + ( self::ROTATION_TRANSITION_HOURS * 3600 ),
                'wplicense_cleanup_rotation',
                [ $license_id ]
            );
        }

        // Log the rotation.
        $this->activity_repo->insert( [
            'license_id' => $license_id,
            'action'     => 'key_rotated',
            'actor'      => $this->current_actor(),
            'details'    => [
                'key_version'      => $new_version,
                'new_prefix'       => $new_prefix,
                'transition_until' => $transition_until,
            ],
        ] );

        return [
            'new_key'          => $new_key,
            'new_prefix'       => $new_prefix,
            'key_version'      => $new_version,
            'transition_until' => $transition_until,
        ];
    }

    /**
     * Clear expired rotation transition windows.
     *
     * Called by WP cron (wplicense_cleanup_rotation) after the 24h window.
     */
    public function cleanup_rotation( int $license_id ): void {
        $license = $this->license_repo->find_by_id( $license_id );

        if ( is_wp_error( $license ) || ! $license || null === $license->rotation_at ) {
            return;
        }

        $rotation_expiry = strtotime( $license->rotation_at ) + ( self::ROTATION_TRANSITION_HOURS * 3600 );

        if ( $rotation_expiry > time() ) {
            // Not yet expired — reschedule.
            wp_schedule_single_event( $rotation_expiry, 'wplicense_cleanup_rotation', [ $license_id ] );
            return;
        }

        $this->license_repo->clear_rotation( $license_id );

        $this->activity_repo->insert( [
            'license_id' => $license_id,
            'action'     => 'rotation_completed',
            'actor'      => 'system',
            'details'    => [
                'old_key_cleared' => true,
            ],
        ] );
    }
    /**
     * Schedule an immediate async webhook dispatch via cron.
     *
     * Replaces the old synchronous dispatch_pending() call that blocked
     * the REST response for up to 8s per pending job. The cron-based
     * dispatcher runs every 5 minutes anyway; this schedules a
     * near-immediate run so priority events (lock/unlock) are not
     * delayed by the full interval.
     */
    private static function schedule_async_dispatch(): void {
        $next = wp_next_scheduled( WebhookDispatcher::CRON_HOOK, [] );
        // Schedule a priority single event only when there isn't one already
        // pending within the next 30 seconds. This prevents the guard from
        // matching against the far-future recurring schedule event.
        if ( ! $next || $next > time() + 30 ) {
            wp_schedule_single_event( time() + 5, WebhookDispatcher::CRON_HOOK );
        }
    }

    private function normalize_domain( string $domain ): string {
        return $this->target_validator->normalize_domain( $domain );
    }

    private function parse_valid_until( string $valid_until ): \DateTime|\WP_Error {
        try {
            $expiry = new \DateTime( $valid_until, new \DateTimeZone( 'UTC' ) );
        } catch ( \Exception ) {
            return new \WP_Error(
                ErrorCodes::INVALID_DATE->value,
                __( 'The valid_until date is not a valid date format.', 'wp-license-server' ),
                [ 'status' => 400 ]
            );
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valid_until ) ) {
            $expiry->setTime( 23, 59, 59 );
        }

        return $expiry;
    }

    private function current_actor(): string {
        $actor = 'system';
        $user  = wp_get_current_user();

        if ( $user->exists() ) {
            $actor = 'admin:' . $user->user_login;
        }

        return $actor;
    }

    /**
     * @template T
     *
     * @param callable(): (T|\WP_Error) $callback
     * @return T|\WP_Error
     */
    private function with_owner_lock( callable $callback ) {
        $lock_result = $this->acquire_owner_lock();
        if ( is_wp_error( $lock_result ) ) {
            return $lock_result;
        }

        try {
            return $callback();
        } finally {
            $this->release_owner_lock();
        }
    }

    private function acquire_owner_lock(): true|\WP_Error {
        $lock_name = $this->wpdb->prefix . self::OWNER_LOCK_NAME;
        $result    = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT GET_LOCK(%s, 5)',
                $lock_name
            )
        );

        if ( '1' !== (string) $result ) {
            return new \WP_Error(
                ErrorCodes::OWNER_LOCK_FAILED->value,
                __( 'The owner assignment lock could not be acquired.', 'wp-license-server' ),
                [ 'status' => 500 ]
            );
        }

        return true;
    }

    private function translate_status( string $status ): string {
        return match ( $status ) {
            'active'     => __( 'Active', 'wp-license-server' ),
            'grace'      => __( 'Grace period', 'wp-license-server' ),
            'expired'    => __( 'Expired', 'wp-license-server' ),
            'suspended'  => __( 'Suspended', 'wp-license-server' ),
            'cancelled'  => __( 'Cancelled', 'wp-license-server' ),
            'pending'    => __( 'Pending', 'wp-license-server' ),
            default      => $status,
        };
    }

    private function release_owner_lock(): void {
        $lock_name = $this->wpdb->prefix . self::OWNER_LOCK_NAME;
        $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT RELEASE_LOCK(%s)',
                $lock_name
            )
        );
    }
}
