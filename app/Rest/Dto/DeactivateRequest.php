<?php
declare(strict_types=1);

namespace WpLicenseServer\Rest\Dto;

final class DeactivateRequest {
    public readonly string $keyId;
    public readonly string $domain;

    public function __construct( \WP_REST_Request $request ) {
        $this->keyId  = sanitize_text_field( $request->get_header( 'X-License-Key-Id' ) ?? '' );
        $this->domain = sanitize_text_field( $request->get_header( 'X-License-Domain' ) ?? '' );
    }
}
