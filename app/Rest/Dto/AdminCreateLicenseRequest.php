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
        $rawCustomerName    = $request->get_param( 'customerName' );
        $rawCustomerEmail   = $request->get_param( 'customerEmail' );
        $rawRole            = $request->get_param( 'role' );
        $rawTier            = $request->get_param( 'tier' );
        $rawValidUntil      = $request->get_param( 'validUntil' );
        $rawPaymentInterval = $request->get_param( 'paymentInterval' );
        $rawNotes           = $request->get_param( 'notes' );

        $this->customerName    = $rawCustomerName !== null ? sanitize_text_field( (string) $rawCustomerName ) : null;
        $this->customerEmail   = $rawCustomerEmail !== null ? sanitize_email( (string) $rawCustomerEmail ) : null;
        $this->role            = $rawRole !== null ? sanitize_key( $rawRole ) : null;
        $this->tier            = $rawTier !== null ? sanitize_text_field( (string) $rawTier ) : null;
        $this->validUntil      = $rawValidUntil !== null ? sanitize_text_field( (string) $rawValidUntil ) : null;
        $this->paymentInterval = $rawPaymentInterval !== null ? sanitize_key( $rawPaymentInterval ) : null;
        $raw_auto_renewal      = $request->get_param( 'autoRenewal' );
        $this->autoRenewal     = $raw_auto_renewal !== null ? (bool) $raw_auto_renewal : true;
        $this->notes           = $rawNotes !== null ? sanitize_textarea_field( (string) $rawNotes ) : null;
    }
}
