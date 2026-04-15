import { createStore } from 'zustand';
import type { License, LicenseStatus } from '../types';

export interface LicenseStoreState {
  license: License | null;
  isLoading: boolean;
  error: string | null;
  /** Internal field — not part of the public context snapshot */
  internalDebounceCounter: number;
  setLicense: (license: License) => void;
  setLoading: (isLoading: boolean) => void;
  setError: (error: string | null) => void;
  /** Simulate internal bookkeeping that should not trigger consumer re-renders */
  tickDebounce: () => void;
}

export function computeStatus(license: License): LicenseStatus {
  return license.status;
}

export const licenseStore = createStore<LicenseStoreState>(set => ({
  license: null,
  isLoading: false,
  error: null,
  internalDebounceCounter: 0,
  setLicense: license => set({ license }),
  setLoading: isLoading => set({ isLoading }),
  setError: error => set({ error }),
  tickDebounce: () => set(s => ({ internalDebounceCounter: s.internalDebounceCounter + 1 })),
}));
