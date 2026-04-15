import { createContext, useContext, type ReactNode } from 'react';
import { useStore } from 'zustand';
import { useShallow } from 'zustand/react/shallow';
import { licenseStore, computeStatus, type LicenseStoreState } from '../store/licenseStore';
import type { License, LicenseFeature, LicenseStatus } from '../types';

// Stable empty array reference — prevents new array identity on every selector call
// when the license has no features.
const EMPTY_FEATURES_ARRAY: LicenseFeature[] = [];

interface LicenseSnapshot {
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

const LicenseContext = createContext<LicenseSnapshot>({
  license: null,
  status: 'unknown',
  features: EMPTY_FEATURES_ARRAY,
  isLoading: false,
  error: null,
});

export function LicenseProvider({ children }: { children: ReactNode }) {
  // useShallow performs shallow equality on the returned snapshot object —
  // consumers only re-render when a selected field actually changes value,
  // not on every internal store mutation.
  const snapshot = useStore(licenseStore, useShallow(selectLicenseSnapshot));

  return <LicenseContext.Provider value={snapshot}>{children}</LicenseContext.Provider>;
}

export function useFeature(feature: LicenseFeature): boolean {
  const { features } = useContext(LicenseContext);
  return features.includes(feature);
}

export { LicenseContext };
