<?php
declare(strict_types=1);

namespace WpLicenseServer\Rest\Dto;

final class ChatBootstrapRequest {
    public readonly string $keyId;
    public readonly string $domain;
    public readonly ?int $selectedThreadId;

    public function __construct( \WP_REST_Request $request ) {
        $this->keyId   = sanitize_text_field( $request->get_header( 'X-License-Key-Id' ) ?? '' );
        $this->domain  = sanitize_text_field( $request->get_header( 'X-License-Domain' ) ?? '' );
        $body = $request->get_json_params() ?? [];
        $this->selectedThreadId = isset( $body['selectedThreadId'] ) ? absint( $body['selectedThreadId'] ) : null;
    }
}
