import type { BrandingDraft } from './types';

export function createBrandingDraft(initial: Partial<BrandingDraft> = {}): BrandingDraft {
  return {
    siteName: initial.siteName ?? '',
    logoUrl: initial.logoUrl ?? null,
    primaryColor: initial.primaryColor ?? '#4f46e5',
    // openInNewTabPatterns is stored here only for the unsaved draft;
    // the live value applied at navigation time is owned by navigationStore.
    openInNewTabPatterns: initial.openInNewTabPatterns ?? [],
  };
}

export function applyDraftPatch(
  draft: BrandingDraft,
  patch: Partial<BrandingDraft>,
): BrandingDraft {
  return { ...draft, ...patch };
}
