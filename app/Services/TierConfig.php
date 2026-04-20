<?php
/**
 * Tier/package configuration.
 *
 * Single source of truth for feature sets and activation limits per tier.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

final class TierConfig {

    public const TIERS = [
        'basic'  => [
            'label'           => 'Basic',
            'max_activations' => 1,
            'features'        => [ 'dashboard', 'content', 'white_label' ],
        ],
        'pro'    => [
            'label'           => 'Pro',
            'max_activations' => 3,
            'features'        => [ 'dashboard', 'content', 'chat', 'backup_status', 'white_label' ],
        ],
        'agency' => [
            'label'           => 'Agency',
            'max_activations' => 25,
            'features'        => [
                'dashboard', 'content', 'chat', 'backup_status',
                'priority_support', 'white_label',
            ],
        ],
    ];

    /** Maps REST route slugs to the feature flag they require. */
    public const ROUTE_FEATURES = [
        'chat/bootstrap' => 'chat',
        'chat/archive'   => 'chat',
        'chat/unarchive' => 'chat',
        'chat/delete'    => 'chat',
        'chat/send'      => 'chat',
        'chat/poll'      => 'chat',
    ];

    /**
     * @return string[]
     */
    public static function features_for_tier( string $tier ): array {
        return self::TIERS[ $tier ]['features'] ?? [];
    }

    public static function max_activations_for_tier( string $tier ): int {
        return self::TIERS[ $tier ]['max_activations'] ?? 1;
    }

    /**
     * @return string[]
     */
    public static function valid_tiers(): array {
        return array_keys( self::TIERS );
    }

    public static function is_valid_tier( string $tier ): bool {
        return isset( self::TIERS[ $tier ] );
    }
}
