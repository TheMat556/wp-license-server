<?php
declare(strict_types=1);

namespace WpLicenseServer\Rest\Dto;

final class ValidateRequest {
    public readonly string $keyId;
    public readonly string $domain;
    public readonly ?string $pluginVersion;
    public readonly ?string $wpVersion;
    public readonly ?string $phpVersion;

    public function __construct( \WP_REST_Request $request ) {
        $this->keyId  = sanitize_text_field( $request->get_header( 'X-License-Key-Id' ) ?? '' );
        $this->domain = sanitize_text_field( $request->get_header( 'X-License-Domain' ) ?? '' );
        $body = $request->get_json_params() ?? [];
        $this->pluginVersion = isset( $body['plugin_version'] ) ? sanitize_text_field( $body['plugin_version'] ) : null;
        $this->wpVersion     = isset( $body['wp_version'] ) ? sanitize_text_field( $body['wp_version'] ) : null;
        $this->phpVersion    = isset( $body['php_version'] ) ? sanitize_text_field( $body['php_version'] ) : null;
    }
}
