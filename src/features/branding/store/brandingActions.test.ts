import { afterEach, describe, expect, test, vi } from 'vitest';
import { saveBranding } from './brandingActions';
import { brandingApi } from './brandingStore';
import { navigationStore } from '../../navigation/store/navigationStore';
import type { Branding } from '../types';

const baseBranding: Branding = {
  siteName: 'Test Site',
  logoUrl: null,
  primaryColor: '#4f46e5',
  openInNewTabPatterns: [],
};

afterEach(() => {
  navigationStore.setState({ openInNewTabPatterns: [] });
  vi.restoreAllMocks();
});

describe('saveBranding', () => {
  test('saving branding with new patterns updates navigationStore immediately', async () => {
    navigationStore.setState({ openInNewTabPatterns: ['example.com'] });

    vi.spyOn(brandingApi, 'save').mockResolvedValue({
      success: true,
      data: { ...baseBranding, openInNewTabPatterns: ['newdomain.com', 'other.com'] },
    });

    await saveBranding({ openInNewTabPatterns: ['newdomain.com', 'other.com'] });

    expect(navigationStore.getState().openInNewTabPatterns).toEqual(['newdomain.com', 'other.com']);
  });

  test('does not update navigationStore when save fails', async () => {
    navigationStore.setState({ openInNewTabPatterns: ['original.com'] });

    vi.spyOn(brandingApi, 'save').mockResolvedValue({
      success: false,
      data: baseBranding,
    });

    await saveBranding({ openInNewTabPatterns: ['changed.com'] });

    expect(navigationStore.getState().openInNewTabPatterns).toEqual(['original.com']);
  });

  test('does not update navigationStore when draft omits openInNewTabPatterns', async () => {
    navigationStore.setState({ openInNewTabPatterns: ['keep.com'] });

    vi.spyOn(brandingApi, 'save').mockResolvedValue({
      success: true,
      data: baseBranding,
    });

    await saveBranding({ siteName: 'New Name' });

    expect(navigationStore.getState().openInNewTabPatterns).toEqual(['keep.com']);
  });

  test('returns true on success', async () => {
    vi.spyOn(brandingApi, 'save').mockResolvedValue({ success: true, data: baseBranding });
    const result = await saveBranding({});
    expect(result).toBe(true);
  });

  test('returns false when API returns success:false', async () => {
    vi.spyOn(brandingApi, 'save').mockResolvedValue({ success: false, data: baseBranding });
    const result = await saveBranding({ openInNewTabPatterns: ['x.com'] });
    expect(result).toBe(false);
  });

  test('returns false and does not throw on network error', async () => {
    vi.spyOn(brandingApi, 'save').mockRejectedValue(new Error('network'));
    await expect(saveBranding({})).resolves.toBe(false);
  });
});
