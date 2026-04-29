import { createStore } from 'zustand';
import { pluginRestClient } from '../../../shared/pluginRestClient';
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
  setBranding: branding => set({ branding }),
  setSaving: isSaving => set({ isSaving }),
}));

/** Minimal API surface used by brandingActions — swap out in tests. */
export const brandingApi = {
  async save(
    draft: import('../types').BrandingDraft,
  ): Promise<import('../types').BrandingApiSaveResult> {
    try {
      return await pluginRestClient.post<import('../types').BrandingApiSaveResult>(
        '/license-server/v1/branding',
        draft,
      );
    } catch {
      return { success: false, data: brandingStore.getState().branding! };
    }
  },
};
