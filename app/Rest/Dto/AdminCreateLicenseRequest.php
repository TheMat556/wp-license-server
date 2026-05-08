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
        $this->customerName    = $request->get_param( 'customerName' ) !== null ? sanitize_text_field( (string) $request->get_param( 'customerName' ) ) : null;
        $this->customerEmail   = $request->get_param( 'customerEmail' ) !== null ? sanitize_text_field( (string) $request->get_param( 'customerEmail' ) ) : null;
        $this->role            = $request->get_param( 'role' ) !== null ? sanitize_key( $request->get_param( 'role' ) ) : null;
        $this->tier            = $request->get_param( 'tier' ) !== null ? sanitize_text_field( (string) $request->get_param( 'tier' ) ) : null;
        $this->validUntil      = $request->get_param( 'validUntil' ) !== null ? sanitize_text_field( (string) $request->get_param( 'validUntil' ) ) : null;
        $this->paymentInterval = $request->get_param( 'paymentInterval' ) !== null ? sanitize_key( $request->get_param( 'paymentInterval' ) ) : null;
        $raw_auto_renewal      = $request->get_param( 'autoRenewal' );
        $this->autoRenewal     = $raw_auto_renewal !== null ? (bool) $raw_auto_renewal : true;
        $this->notes           = $request->get_param( 'notes' ) !== null ? sanitize_textarea_field( (string) $request->get_param( 'notes' ) ) : null;
    }
}
