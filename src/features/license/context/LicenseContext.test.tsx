import { act, render, renderHook, screen } from '@testing-library/react';
import { afterEach, describe, expect, test } from 'vitest';
import { useLicenseSnapshot, useFeature } from './LicenseContext';
import { licenseStore } from '../store/licenseStore';
import type { License } from '../types';

const mockLicense: License = {
  id: 1,
  key: 'TEST-KEY-0001',
  status: 'active',
  features: ['chat', 'advanced_analytics'],
  expiresAt: '2027-01-01T00:00:00Z',
  domain: 'example.com',
};

afterEach(() => {
  licenseStore.setState({
    license: null,
    isLoading: false,
    error: null,
    internalDebounceCounter: 0,
  });
});

describe('useLicenseSnapshot', () => {
  test('provides default snapshot when store is empty', () => {
    const { result } = renderHook(() => useLicenseSnapshot());
    expect(result.current.license).toBeNull();
    expect(result.current.status).toBe('unknown');
    expect(result.current.features).toEqual([]);
    expect(result.current.isLoading).toBe(false);
    expect(result.current.error).toBeNull();
  });

  test('reflects license when store is populated', () => {
    act(() => licenseStore.setState({ license: mockLicense }));
    const { result } = renderHook(() => useLicenseSnapshot());
    expect(result.current.license).toBe(mockLicense);
    expect(result.current.status).toBe('active');
    expect(result.current.features).toEqual(['chat', 'advanced_analytics']);
  });

  test('reflects isLoading and error from store', () => {
    act(() => licenseStore.setState({ isLoading: true, error: 'Network failure' }));
    const { result } = renderHook(() => useLicenseSnapshot());
    expect(result.current.isLoading).toBe(true);
    expect(result.current.error).toBe('Network failure');
  });

  test('consumers do not re-render when unrelated store fields change', () => {
    const renderCount = { current: 0 };
    function Consumer() {
      useLicenseSnapshot();
      renderCount.current++;
      return null;
    }

    render(<Consumer />);
    expect(renderCount.current).toBe(1);

    act(() => licenseStore.setState({ internalDebounceCounter: 99 }));
    expect(renderCount.current).toBe(1); // no re-render
  });

  test('consumers re-render when a selected field changes', () => {
    const renderCount = { current: 0 };
    function Consumer() {
      useLicenseSnapshot();
      renderCount.current++;
      return null;
    }

    render(<Consumer />);
    const before = renderCount.current;

    act(() => licenseStore.setState({ isLoading: true }));
    expect(renderCount.current).toBeGreaterThan(before);
  });
});

describe('useFeature', () => {
  test('returns true for a feature the license has', () => {
    act(() => licenseStore.setState({ license: mockLicense }));
    const { result } = renderHook(() => useFeature('chat'));
    expect(result.current).toBe(true);
  });

  test('returns false for a feature the license does not have', () => {
    act(() => licenseStore.setState({ license: mockLicense }));
    const { result } = renderHook(() => useFeature('custom_branding'));
    expect(result.current).toBe(false);
  });

  test('returns false when license is null', () => {
    const { result } = renderHook(() => useFeature('chat'));
    expect(result.current).toBe(false);
  });

  test('returns false for unknown feature name', () => {
    act(() => licenseStore.setState({ license: mockLicense }));
    // @ts-expect-error — intentional invalid feature for runtime test
    const { result } = renderHook(() => useFeature('nonexistent_feature'));
    expect(result.current).toBe(false);
  });

  test('useFeature renders a visible result', () => {
    act(() => licenseStore.setState({ license: mockLicense }));
    function FeatureGate() {
      const hasChat = useFeature('chat');
      return <div>{hasChat ? 'enabled' : 'disabled'}</div>;
    }
    render(<FeatureGate />);
    expect(screen.getByText('enabled')).toBeInTheDocument();
  });
});
