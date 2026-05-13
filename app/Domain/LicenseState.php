<?php
/**
 * Effective license state enumeration.
 *
 * Represents the computed state of a license at a given moment in time,
 * which may differ from the stored status column (e.g. expired status
 * vs. an active license past its valid_until that is still within grace).
 *
 * @package WpLicenseServer\Domain
 */

declare(strict_types=1);

namespace WpLicenseServer\Domain;

enum LicenseState: string {
    case Locked   = 'locked';
    case Active    = 'active';
    case Grace     = 'grace';
    case Expired   = 'expired';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    /**
     * Whether the license may still be used (access granted).
     */
    public function is_usable(): bool {
        return match( $this ) {
            self::Active, self::Grace => true,
            default                   => false,
        };
    }
}
