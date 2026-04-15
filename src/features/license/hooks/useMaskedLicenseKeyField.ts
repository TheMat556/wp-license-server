import { useState } from 'react';

const MASK_CHAR = '•';
const MASK_LENGTH = 24;

export interface MaskedLicenseKeyField {
  displayValue: string;
  draftKey: string;
  isRevealed: boolean;
  setDraftKey: (key: string) => void;
  toggleReveal: () => void;
  hasUnsavedKey: boolean;
}

export function useMaskedLicenseKeyField(
  initialKeyPrefix: string | null,
): MaskedLicenseKeyField {
  const [isRevealed, setIsRevealed] = useState(false);
  const [draftKey, setDraftKey] = useState('');

  const displayValue = isRevealed
    ? draftKey
    : initialKeyPrefix
      ? `${initialKeyPrefix}${MASK_CHAR.repeat(MASK_LENGTH)}`
      : '';

  return {
    displayValue,
    draftKey,
    isRevealed,
    setDraftKey,
    toggleReveal: () => setIsRevealed(v => !v),
    hasUnsavedKey: draftKey.length > 0,
  };
}
