<?php
/**
 * Server-side feature gating based on license tier.
 *
 * Guards REST endpoints against access by tiers that do not include the
 * required feature — OWASP API5 (Broken Function Level Authorization).
 *
 * @package WpLicenseServer\Rest\Middleware
 */

declare(strict_types=1);

namespace WpLicenseServer\Rest\Middleware;

use WpLicenseServer\ErrorCodes;
use WpLicenseServer\Models\License;
use WpLicenseServer\Services\TierConfig;
use function __;

final class FeatureGate {

    /**
     * Assert that the license's tier includes the requested feature.
     *
     * @return true|\WP_Error True when allowed; WP_Error 403 when not.
     */
    public function require_feature( string $feature, License $license ): true|\WP_Error {
        $allowed = TierConfig::features_for_tier( $license->tier );

        if ( ! in_array( $feature, $allowed, true ) ) {
            return new \WP_Error(
                ErrorCodes::FEATURE_NOT_AVAILABLE->value,
                sprintf( __( "Feature '%1\$s' is not available on the '%2\$s' plan.", 'wp-license-server' ), $feature, $license->tier ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }
}
