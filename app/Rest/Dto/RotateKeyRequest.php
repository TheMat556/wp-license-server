<?php
declare(strict_types=1);

namespace WpLicenseServer\Rest\Dto;

final class RotateKeyRequest {
    public readonly int $id;

    public function __construct( \WP_REST_Request $request ) {
        $this->id = absint( $request->get_param( 'id' ) );
    }
}
