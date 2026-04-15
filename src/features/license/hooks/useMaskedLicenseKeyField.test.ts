import { act, renderHook } from '@testing-library/react';
import { describe, expect, test } from 'vitest';
import { useMaskedLicenseKeyField } from './useMaskedLicenseKeyField';

describe('useMaskedLicenseKeyField', () => {
  test('displays masked value when not revealed and prefix is set', () => {
    const { result } = renderHook(() => useMaskedLicenseKeyField('ABCD1234'));
    expect(result.current.displayValue).toMatch(/^ABCD1234•+$/);
    expect(result.current.isRevealed).toBe(false);
  });

  test('displays empty string when prefix is null and not revealed', () => {
    const { result } = renderHook(() => useMaskedLicenseKeyField(null));
    expect(result.current.displayValue).toBe('');
  });

  test('displays draft key when revealed', () => {
    const { result } = renderHook(() => useMaskedLicenseKeyField('ABCD1234'));
    act(() => {
      result.current.setDraftKey('MY-SECRET-KEY-1234');
      result.current.toggleReveal();
    });
    expect(result.current.displayValue).toBe('MY-SECRET-KEY-1234');
    expect(result.current.isRevealed).toBe(true);
  });

  test('toggleReveal switches display mode', () => {
    const { result } = renderHook(() => useMaskedLicenseKeyField('PREFIX'));
    expect(result.current.isRevealed).toBe(false);
    act(() => result.current.toggleReveal());
    expect(result.current.isRevealed).toBe(true);
    act(() => result.current.toggleReveal());
    expect(result.current.isRevealed).toBe(false);
  });

  test('hasUnsavedKey is false when draftKey is empty', () => {
    const { result } = renderHook(() => useMaskedLicenseKeyField(null));
    expect(result.current.hasUnsavedKey).toBe(false);
  });

  test('hasUnsavedKey is true when draftKey is non-empty', () => {
    const { result } = renderHook(() => useMaskedLicenseKeyField(null));
    act(() => result.current.setDraftKey('some-key'));
    expect(result.current.hasUnsavedKey).toBe(true);
  });

  test('mask length is 24 characters', () => {
    const { result } = renderHook(() => useMaskedLicenseKeyField('PREFIX'));
    const maskPart = result.current.displayValue.slice('PREFIX'.length);
    expect(maskPart).toHaveLength(24);
    expect([...maskPart].every(c => c === '•')).toBe(true);
  });
});
