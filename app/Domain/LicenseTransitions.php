<?php
/**
 * Defines the legal status-transition matrix for licenses.
 *
 * Acts as a domain rule enforcer — no infrastructure dependencies.
 * Any caller that wants to change a license status MUST go through validate().
 *
 * @package WpLicenseServer\Domain
 */

declare(strict_types=1);

namespace WpLicenseServer\Domain;

use WpLicenseServer\ErrorCodes;

final class LicenseTransitions {

    /**
     * Allowed transitions: from_status => [ allowed_to_statuses ].
     *
     * Rules:
     *  - cancelled is terminal — nothing transitions out of it.
     *  - expired → active is intentional: supports manual admin renewal.
     *  - pending exists for provisioning workflows before payment confirmation.
     */
    private const MATRIX = [
        'active'    => [ 'expired', 'suspended', 'cancelled' ],
        'expired'   => [ 'active', 'cancelled' ],
        'suspended' => [ 'active', 'cancelled' ],
        'cancelled' => [],
        'pending'   => [ 'active', 'cancelled' ],
    ];

    public static function is_allowed( string $from, string $to ): bool {
        return in_array( $to, self::MATRIX[ $from ] ?? [], true );
    }

    /** @return string[] */
    public static function allowed_from( string $from ): array {
        return self::MATRIX[ $from ] ?? [];
    }

    /** @return \WP_Error|true */
    public static function validate( string $from, string $to ) {
        if ( $from === $to ) {
            return true; // no-op is always valid
        }

        if ( ! self::is_allowed( $from, $to ) ) {
            $allowed_list = implode( ', ', self::allowed_from( $from ) ?: [ 'none' ] );
            return new \WP_Error(
                ErrorCodes::INVALID_TRANSITION->value,
                sprintf(
                    __( "Cannot transition from '%s' to '%s'. Allowed: %s.", 'wp-license-server' ),
                    $from,
                    $to,
                    $allowed_list
                ),
                [ 'status' => 422 ]
            );
        }

        return true;
    }
}
