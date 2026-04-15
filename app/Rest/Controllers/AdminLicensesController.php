<?php
/**
 * Admin-only REST controller for managing licenses from wp-admin.
 *
 * @package WpLicenseServer\Rest\Controllers
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Controllers;

use WP_REST_Request;
use WpLicenseServer\Contracts\ActivationRepositoryInterface;
use WpLicenseServer\Contracts\LicenseRepositoryInterface;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Services\LicenseService;
use WpLicenseServer\Services\TierConfig;

final class AdminLicensesController {

    public function __construct(
        private readonly LicenseRepositoryInterface $license_repo,
        private readonly ActivationRepositoryInterface $activation_repo,
        private readonly LicenseService $license_service,
    ) {}

    public function can_manage_options(): bool {
        return current_user_can( 'manage_options' );
    }

    public function index( WP_REST_Request $request ) {
        $status = $request->get_param( 'status' );
        $status = is_string( $status ) && '' !== $status ? sanitize_text_field( $status ) : null;

        return rest_ensure_response(
            [
                'items' => array_map(
                    [ $this, 'map_license' ],
                    $this->license_repo->find_all( $status )
                ),
                'tiers'         => $this->get_tier_options(),
                'ownerLicenseId' => $this->license_repo->find_owner()?->id,
            ]
        );
    }

    public function create( WP_REST_Request $request ) {
        $result = $this->license_service->create(
            [
                'customer_name'    => $request->get_param( 'customerName' ),
                'customer_email'   => $request->get_param( 'customerEmail' ),
                'role'             => $request->get_param( 'role' ),
                'tier'             => $request->get_param( 'tier' ),
                'valid_until'      => $request->get_param( 'validUntil' ),
                'payment_interval' => $request->get_param( 'paymentInterval' ),
                'notes'            => $request->get_param( 'notes' ),
            ]
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            [
                'item'       => $this->map_license( $result ),
                'licenseKey' => $result->license_key,
            ]
        );
    }

    public function update( WP_REST_Request $request ) {
        $license_id = absint( $request->get_param( 'id' ) );

        if ( $license_id <= 0 ) {
            return new \WP_Error(
                'invalid_license_id',
                'A valid license ID is required.',
                [ 'status' => 400 ]
            );
        }

        $payload = [];
        $field_map = [
            'customerName'    => 'customer_name',
            'customerEmail'   => 'customer_email',
            'role'            => 'role',
            'tier'            => 'tier',
            'status'          => 'status',
            'validUntil'      => 'valid_until',
            'paymentInterval' => 'payment_interval',
            'autoRenewal'     => 'auto_renewal',
            'maxActivations'  => 'max_activations',
            'notes'           => 'notes',
        ];

        foreach ( $field_map as $request_key => $payload_key ) {
            if ( $request->has_param( $request_key ) ) {
                $payload[ $payload_key ] = $request->get_param( $request_key );
            }
        }

        $result = $this->license_service->update( $license_id, $payload );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            [
                'item' => $this->map_license( $result ),
            ]
        );
    }

    public function delete( WP_REST_Request $request ) {
        $license_id = absint( $request->get_param( 'id' ) );

        if ( $license_id <= 0 ) {
            return new \WP_Error(
                'invalid_license_id',
                'A valid license ID is required.',
                [ 'status' => 400 ]
            );
        }

        if ( ! $this->license_repo->delete( $license_id ) ) {
            return new \WP_Error(
                'delete_failed',
                'The license could not be deleted.',
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response( [ 'deleted' => true ] );
    }

    public function deactivate_all( WP_REST_Request $request ) {
        $license_id = absint( $request->get_param( 'id' ) );

        if ( $license_id <= 0 ) {
            return new \WP_Error(
                'invalid_license_id',
                'A valid license ID is required.',
                [ 'status' => 400 ]
            );
        }

        $active = $this->activation_repo->get_all_active( $license_id );

        foreach ( $active as $activation ) {
            $this->activation_repo->deactivate( $license_id, $activation->domain );
        }

        return rest_ensure_response(
            [
                'deactivated' => count( $active ),
            ]
        );
    }

    /**
     * @return array{value: string, label: string, maxActivations: int, features: array<int, string>}[]
     */
    private function get_tier_options(): array {
        $tiers = [];

        foreach ( TierConfig::TIERS as $key => $config ) {
            $tiers[] = [
                'value'          => $key,
                'label'          => $config['label'],
                'maxActivations' => (int) $config['max_activations'],
                'features'       => array_values( $config['features'] ),
            ];
        }

        return $tiers;
    }

    /**
     * @return array{id: int, keyPrefix: string, customerName: string, customerEmail: string, role: string, tier: string, status: string, maxActivations: int, currentActivations: int, paymentInterval: string, autoRenewal: bool, notes: ?string, createdAt: string, validUntil: string}
     */
    private function map_license( object $license ): array {
        return [
            'id'                 => (int) $license->id,
            'keyPrefix'          => (string) $license->key_prefix,
            'customerName'       => (string) $license->customer_name,
            'customerEmail'      => (string) $license->customer_email,
            'role'               => (string) $license->role,
            'tier'               => (string) $license->tier,
            'status'             => (string) $license->status,
            'maxActivations'     => (int) $license->max_activations,
            'currentActivations' => $this->activation_repo->count_active( (int) $license->id ),
            'paymentInterval'    => (string) $license->payment_interval,
            'autoRenewal'        => (bool) $license->auto_renewal,
            'notes'              => is_string( $license->notes ) && '' !== $license->notes ? $license->notes : null,
            'createdAt'          => (string) $license->created_at,
            'validUntil'         => (string) $license->valid_until,
        ];
    }
}
