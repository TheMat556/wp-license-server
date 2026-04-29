/**
 * Hook for fetching and caching license-server settings from the REST API.
 *
 * SECURITY NOTE: `storedLicenseKey` is a **masked display value only**
 * (e.g. "a1b2****ef12"). It must never be sent back to the server as an
 * authentication secret or HMAC key. The mask is applied server-side and
 * cannot be reversed.
 */

import { useQuery } from '@tanstack/react-query';
import { pluginRestClient } from '../../shared/pluginRestClient';

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

export function useLicenseServerSettings(): UseSettingsResult {
  const { data, isFetching, error, refetch } = useQuery({
    queryKey: ['settings'],
    queryFn: () => pluginRestClient.get<LicenseServerSettings>('/license-server/v1/admin/settings'),
  });

  return {
    settings: data ?? null,
    loading: isFetching,
    error: error instanceof Error ? error.message : error != null ? 'Unknown error' : null,
    refetch: () => { void refetch(); },
  };
}
