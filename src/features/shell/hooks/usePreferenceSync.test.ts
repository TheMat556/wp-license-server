import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { usePreferenceSync } from './usePreferenceSync';
import { preferencesApi } from '../preferencesApi';
import { shellPreferencesStore } from '../store/shellPreferencesStore';

beforeEach(() => {
  vi.useFakeTimers();
  vi.spyOn(preferencesApi, 'save').mockResolvedValue(undefined);
});

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
  shellPreferencesStore.setState({ sidebarCollapsed: false, highContrast: false });
});

describe('usePreferenceSync', () => {
  test('triggers debounced save when preferences change', async () => {
    const { rerender } = renderHook(() => usePreferenceSync());

    shellPreferencesStore.getState().setHighContrast(true);
    rerender();

    // Not called yet — debounce window still open
    expect(preferencesApi.save).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(800);
    expect(preferencesApi.save).toHaveBeenCalledTimes(1);
    expect(preferencesApi.save).toHaveBeenCalledWith({
      sidebarCollapsed: false,
      highContrast: true,
    });
  });

  test('debounces multiple rapid changes into a single save', async () => {
    const { rerender } = renderHook(() => usePreferenceSync());

    shellPreferencesStore.getState().setHighContrast(true);
    rerender();
    await vi.advanceTimersByTimeAsync(400);

    shellPreferencesStore.getState().setSidebarCollapsed(true);
    rerender();
    await vi.advanceTimersByTimeAsync(400);

    // Still inside debounce window of the second change
    expect(preferencesApi.save).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(400);
    expect(preferencesApi.save).toHaveBeenCalledTimes(1);
  });

  test('cancels pending save on unmount', async () => {
    const { rerender, unmount } = renderHook(() => usePreferenceSync());

    shellPreferencesStore.getState().setHighContrast(true);
    rerender();

    await vi.advanceTimersByTimeAsync(400); // partway through debounce
    unmount();

    await vi.advanceTimersByTimeAsync(800); // would have fired
    expect(preferencesApi.save).not.toHaveBeenCalled();
  });
});
