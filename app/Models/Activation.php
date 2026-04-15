<?php
/**
 * Activation entity (readonly value object).
 *
 * @package WpLicenseServer\Models
 */

declare(strict_types=1);

namespace WpLicenseServer\Models;

final class Activation {

    /** Transition window for accepting the previous webhook secret (seconds). */
    public const WEBHOOK_SECRET_TRANSITION_SECONDS = 300; // 5 minutes

    public function __construct(
        public readonly int $id,
        public readonly int $license_id,
        public readonly string $domain,
        public readonly string $activated_at,
        public readonly ?string $last_heartbeat,
        public readonly ?string $plugin_version,
        public readonly ?string $wp_version,
        public readonly ?string $php_version,
        public readonly ?string $deactivated_at,
        public readonly ?string $webhook_secret,
        public readonly ?string $previous_webhook_secret = null,
        public readonly ?string $webhook_secret_rotated_at = null,
        public readonly int $webhook_secret_version = 1,
    ) {}

    public static function from_row( object $row ): static {
        return new static(
            id:                        (int) $row->id,
            license_id:                (int) $row->license_id,
            domain:                    $row->domain,
            activated_at:              $row->activated_at,
            last_heartbeat:            $row->last_heartbeat,
            plugin_version:            $row->plugin_version,
            wp_version:                $row->wp_version,
            php_version:               $row->php_version,
            deactivated_at:            $row->deactivated_at,
            webhook_secret:            $row->webhook_secret,
            previous_webhook_secret:   $row->previous_webhook_secret ?? null,
            webhook_secret_rotated_at: $row->webhook_secret_rotated_at ?? null,
            webhook_secret_version:    isset( $row->webhook_secret_version ) ? (int) $row->webhook_secret_version : 1,
        );
    }

    public function is_active(): bool {
        return $this->deactivated_at === null;
    }

    /**
     * Verify a webhook secret claim, accepting the previous secret within the 5-minute
     * transition window that follows a rotation.
     *
     * @param string   $claimed_secret Plaintext secret sent in the X-Webhook-Secret header.
     * @param int|null $at             Unix timestamp to evaluate the window against (defaults to now).
     */
    public function is_webhook_secret_valid( string $claimed_secret, ?int $at = null ): bool {
        $now = $at ?? time();

        if ( null !== $this->webhook_secret && hash_equals( $this->webhook_secret, $claimed_secret ) ) {
            return true;
        }

        if (
            null !== $this->previous_webhook_secret &&
            null !== $this->webhook_secret_rotated_at
        ) {
            $rotated_at = (int) strtotime( $this->webhook_secret_rotated_at );
            $window_end = $rotated_at + self::WEBHOOK_SECRET_TRANSITION_SECONDS;

            if ( $now <= $window_end && hash_equals( $this->previous_webhook_secret, $claimed_secret ) ) {
                return true;
            }
        }

        return false;
    }
}
