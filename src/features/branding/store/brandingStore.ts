import { createStore } from 'zustand';
import type { Branding } from '../types';

interface BrandingStoreState {
  branding: Branding | null;
  isSaving: boolean;
  setBranding: (branding: Branding) => void;
  setSaving: (isSaving: boolean) => void;
}

export const brandingStore = createStore<BrandingStoreState>(set => ({
  branding: null,
  isSaving: false,
  setBranding: (branding) => set({ branding }),
  setSaving: (isSaving) => set({ isSaving }),
}));

/** Minimal API surface used by brandingActions — swap out in tests. */
export const brandingApi = {
  async save(draft: import('../types').BrandingDraft): Promise<import('../types').BrandingApiSaveResult> {
    const response = await fetch('/wp-json/wplicense/v1/branding', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(draft),
    });
    if (!response.ok) {
      return { success: false, data: brandingStore.getState().branding! };
    }
    return response.json();
  },
};
