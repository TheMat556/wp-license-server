import { useStore } from 'zustand';
import { useShallow } from 'zustand/react/shallow';
import { licenseStore, computeStatus, type LicenseStoreState } from '../store/licenseStore';
import type { License, LicenseFeature, LicenseStatus } from '../types';

// Stable empty array reference — prevents new array identity on every selector call
// when the license has no features.
const EMPTY_FEATURES_ARRAY: LicenseFeature[] = [];

export interface LicenseSnapshot {
  license: License | null;
  status: LicenseStatus;
  features: LicenseFeature[];
  isLoading: boolean;
  error: string | null;
}

function selectLicenseSnapshot(state: LicenseStoreState): LicenseSnapshot {
  return {
    license: state.license,
    status: state.license ? computeStatus(state.license) : 'unknown',
    features: state.license?.features ?? EMPTY_FEATURES_ARRAY,
    isLoading: state.isLoading,
    error: state.error,
  };
}

// useShallow performs shallow equality on the returned snapshot object —
// consumers only re-render when a selected field actually changes value,
// not on every internal store mutation.
export function useLicenseSnapshot(): LicenseSnapshot {
  return useStore(licenseStore, useShallow(selectLicenseSnapshot));
}

export function useFeature(feature: LicenseFeature): boolean {
  return useStore(licenseStore, s =>
    (s.license?.features ?? EMPTY_FEATURES_ARRAY).includes(feature),
  );
}
