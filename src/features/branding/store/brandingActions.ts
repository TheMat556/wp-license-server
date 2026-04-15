import { navigationStore } from '../../navigation/store/navigationStore';
import { brandingApi, brandingStore } from './brandingStore';
import type { BrandingDraft } from '../types';

export async function saveBranding(draft: BrandingDraft): Promise<boolean> {
  try {
    const result = await brandingApi.save(draft);
    if (!result.success) return false;

    // Update branding store with persisted data
    brandingStore.getState().setBranding(result.data);

    // Push openInNewTabPatterns to the authoritative navigation store so the
    // running shell applies new patterns immediately — no reload needed.
    if (draft.openInNewTabPatterns !== undefined) {
      navigationStore.getState().setOpenInNewTabPatterns(draft.openInNewTabPatterns);
    }

    return true;
  } catch {
    return false;
  }
}
