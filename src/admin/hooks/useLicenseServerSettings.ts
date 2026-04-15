/**
 * Hook for fetching and caching license-server settings from the REST API.
 *
 * SECURITY NOTE: `storedLicenseKey` is a **masked display value only**
 * (e.g. "a1b2****ef12"). It must never be sent back to the server as an
 * authentication secret or HMAC key. The mask is applied server-side and
 * cannot be reversed.
 */

import { useCallback, useEffect, useState } from "react";

/** Settings payload returned by GET /admin/settings. */
export interface LicenseServerSettings {
  /** Masked display value only, e.g. "ABCD****WXYZ". Never the full key. */
  storedLicenseKey: string;
  /** True when an owner license is configured. */
  hasOwnerLicense: boolean;
}

interface UseSettingsResult {
  settings: LicenseServerSettings | null;
  loading: boolean;
  error: string | null;
  refetch: () => void;
}

declare const window: Window & {
  WpLicenseServerAdmin?: {
    restBase: string;
    nonce: string;
  };
};

export function useLicenseServerSettings(): UseSettingsResult {
  const [settings, setSettings] = useState<LicenseServerSettings | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchSettings = useCallback(async () => {
    const config = window.WpLicenseServerAdmin;
    if (!config) return;

    setLoading(true);
    setError(null);

    try {
      const res = await fetch(`${config.restBase}/settings`, {
        method: "GET",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": config.nonce,
        },
      });

      if (!res.ok) {
        throw new Error(`Settings request failed: ${res.status}`);
      }

      const data = (await res.json()) as LicenseServerSettings;
      setSettings(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unknown error");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void fetchSettings();
  }, [fetchSettings]);

  return { settings, loading, error, refetch: fetchSettings };
}
