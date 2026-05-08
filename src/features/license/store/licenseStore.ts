import { createStore } from 'zustand';
import type { License, LicenseStatus } from '../types';

export interface LicenseStoreState {
  license: License | null;
  isLoading: boolean;
  error: string | null;
  setLicense: (license: License) => void;
  setLoading: (isLoading: boolean) => void;
  setError: (error: string | null) => void;
}

export function computeStatus(license: License): LicenseStatus {
  return license.status;
}

export const licenseStore = createStore<LicenseStoreState>(set => ({
  license: null,
  isLoading: false,
  error: null,
  setLicense: license => set({ license }),
  setLoading: isLoading => set({ isLoading }),
  setError: error => set({ error }),
}));
