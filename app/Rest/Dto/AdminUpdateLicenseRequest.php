<?php
declare(strict_types=1);

namespace WpLicenseServer\Rest\Dto;

final class AdminUpdateLicenseRequest {
    public readonly int $id;
    public readonly ?string $customerName;
    public readonly ?string $customerEmail;
    public readonly ?string $role;
    public readonly ?string $tier;
    public readonly ?string $status;
    public readonly ?string $validUntil;
    public readonly ?string $paymentInterval;
    public readonly ?bool $autoRenewal;
    public readonly ?int $maxActivations;
    public readonly ?string $notes;

    public function __construct( \WP_REST_Request $request ) {
        $this->id             = absint( $request->get_param( 'id' ) );
        $this->customerName   = $request->has_param( 'customerName' ) ? $request->get_param( 'customerName' ) : null;
        $this->customerEmail  = $request->has_param( 'customerEmail' ) ? $request->get_param( 'customerEmail' ) : null;
        $this->role           = $request->has_param( 'role' ) ? $request->get_param( 'role' ) : null;
        $this->tier           = $request->has_param( 'tier' ) ? $request->get_param( 'tier' ) : null;
        $this->status         = $request->has_param( 'status' ) ? $request->get_param( 'status' ) : null;
        $this->validUntil     = $request->has_param( 'validUntil' ) ? $request->get_param( 'validUntil' ) : null;
        $this->paymentInterval = $request->has_param( 'paymentInterval' ) ? $request->get_param( 'paymentInterval' ) : null;
        $raw = $request->has_param( 'autoRenewal' ) ? $request->get_param( 'autoRenewal' ) : null;
        $this->autoRenewal    = $raw !== null ? (bool) $raw : null;
        $raw_max = $request->has_param( 'maxActivations' ) ? $request->get_param( 'maxActivations' ) : null;
        $this->maxActivations = $raw_max !== null ? absint( $raw_max ) : null;
        $this->notes          = $request->has_param( 'notes' ) ? $request->get_param( 'notes' ) : null;
    }

    /**
     * Build the payload array for LicenseService::update().
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array {
        $payload = [];
        $fields = [
            'customer_name'    => $this->customerName,
            'customer_email'   => $this->customerEmail,
            'role'             => $this->role,
            'tier'             => $this->tier,
            'status'           => $this->status,
            'valid_until'      => $this->validUntil,
            'payment_interval' => $this->paymentInterval,
            'auto_renewal'     => $this->autoRenewal,
            'max_activations'  => $this->maxActivations,
            'notes'            => $this->notes,
        ];
        foreach ( $fields as $key => $value ) {
            if ( $value !== null ) {
                $payload[ $key ] = $value;
            }
        }
        return $payload;
    }
}
