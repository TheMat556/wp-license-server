<?php
/**
 * Purpose-scoped key derivation via HKDF-SHA256.
 *
 * Each protocol purpose (API request signing, webhook signing) derives an
 * independent sub-key from the license key using a distinct info string.
 * This implements NIST SP 800-57 key-separation: a compromise of the signing
 * key does not reveal the webhook key, and neither reveals the master license key.
 *
 * Derivation is stateless and deterministic — call it per-request; never cache.
 *
 * @package WpLicenseServer\Services
 */

declare(strict_types=1);

namespace WpLicenseServer\Services;

final class KeyDerivationService {

    /**
     * HKDF info strings that scope each derived key to its purpose.
     * Changing these values is a BREAKING CHANGE — both client and server must update together.
     */
    public const INFO_API_SIGNING     = 'wplicense-hmac-signing-v1';
    public const INFO_WEBHOOK_SIGNING = 'wplicense-webhook-dispatch-v1';

    /**
     * Derive the API request signing key from a license key.
     *
     * Used by: HmacVerifier (server) and LicenseClient (client).
     *
     * @param string $license_key Raw 64-char hex license key.
     * @return string 32-byte binary signing key.
     */
    public function derive_signing_key( string $license_key ): string {
        return hash_hkdf( 'sha256', $license_key, 32, self::INFO_API_SIGNING );
    }

    /**
     * Derive the webhook dispatch signing key from a license key.
     *
     * Used by: WebhookDispatcher (server) and WebhookListener (client).
     *
     * @param string $license_key Raw 64-char hex license key.
     * @return string 32-byte binary signing key.
     */
    public function derive_webhook_key( string $license_key ): string {
        return hash_hkdf( 'sha256', $license_key, 32, self::INFO_WEBHOOK_SIGNING );
    }
}
