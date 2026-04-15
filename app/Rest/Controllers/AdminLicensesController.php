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
use WpLicenseServer\ErrorCodes;
use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Rest\Dto\AdminCreateLicenseRequest;
use WpLicenseServer\Rest\Dto\AdminUpdateLicenseRequest;
use WpLicenseServer\Rest\Dto\RotateKeyRequest;
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

    public function index( WP_REST_Request $request ): \WP_REST_Response {
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

    public function create( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $dto    = new AdminCreateLicenseRequest( $request );
        $result = $this->license_service->create(
            [
                'customer_name'    => $dto->customerName,
                'customer_email'   => $dto->customerEmail,
                'role'             => $dto->role,
                'tier'             => $dto->tier,
                'valid_until'      => $dto->validUntil,
                'payment_interval' => $dto->paymentInterval,
                'notes'            => $dto->notes,
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

    public function update( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $dto = new AdminUpdateLicenseRequest( $request );

        if ( $dto->id <= 0 ) {
            return new \WP_Error(
                ErrorCodes::INVALID_LICENSE_ID->value,
                'A valid license ID is required.',
                [ 'status' => 400 ]
            );
        }

        $result = $this->license_service->update( $dto->id, $dto->toPayload() );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            [
                'item' => $this->map_license( $result ),
            ]
        );
    }

    public function delete( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $dto = new RotateKeyRequest( $request );

        if ( $dto->id <= 0 ) {
            return new \WP_Error(
                ErrorCodes::INVALID_LICENSE_ID->value,
                'A valid license ID is required.',
                [ 'status' => 400 ]
            );
        }

        if ( ! $this->license_repo->delete( $dto->id ) ) {
            return new \WP_Error(
                ErrorCodes::DELETE_FAILED->value,
                'The license could not be deleted.',
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response( [ 'deleted' => true ] );
    }

    public function deactivate_all( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $dto = new RotateKeyRequest( $request );

        if ( $dto->id <= 0 ) {
            return new \WP_Error(
                ErrorCodes::INVALID_LICENSE_ID->value,
                'A valid license ID is required.',
                [ 'status' => 400 ]
            );
        }

        $active = $this->activation_repo->get_all_active( $dto->id );

        foreach ( $active as $activation ) {
            $this->activation_repo->deactivate( $dto->id, $activation->domain );
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
                'features'       => $config['features'],
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
