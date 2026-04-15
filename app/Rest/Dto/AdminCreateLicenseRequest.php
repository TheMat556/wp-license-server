<?php
declare(strict_types=1);

namespace WpLicenseServer\Rest\Dto;

final class AdminCreateLicenseRequest {
    public readonly ?string $customerName;
    public readonly ?string $customerEmail;
    public readonly ?string $role;
    public readonly ?string $tier;
    public readonly ?string $validUntil;
    public readonly ?string $paymentInterval;
    public readonly bool $autoRenewal;
    public readonly ?string $notes;

    public function __construct( \WP_REST_Request $request ) {
        $this->customerName    = $request->get_param( 'customerName' );
        $this->customerEmail   = $request->get_param( 'customerEmail' );
        $this->role            = $request->get_param( 'role' );
        $this->tier            = $request->get_param( 'tier' );
        $this->validUntil      = $request->get_param( 'validUntil' );
        $this->paymentInterval = $request->get_param( 'paymentInterval' );
        $raw_auto_renewal      = $request->get_param( 'autoRenewal' );
        $this->autoRenewal     = $raw_auto_renewal !== null ? (bool) $raw_auto_renewal : true;
        $this->notes           = $request->get_param( 'notes' );
    }
}
