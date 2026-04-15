import { useMutation } from '@tanstack/react-query';
import { saveBranding } from '../store/brandingActions';
import type { BrandingDraft } from '../types';

export function useSaveBranding() {
  return useMutation({
    mutationFn: (draft: BrandingDraft) => saveBranding(draft),
  });
}
