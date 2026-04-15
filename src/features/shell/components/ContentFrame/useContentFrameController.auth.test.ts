import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, test } from 'vitest';
import { useContentFrameController } from './useContentFrameController';
import { sessionStore } from '../../store/sessionStore';

afterEach(() => {
  sessionStore.setState({ isExpired: false });
});

describe('useContentFrameController auth expiry', () => {
  test('shell:auth-required CustomEvent triggers markExpired in session store', () => {
    sessionStore.setState({ isExpired: false });
    renderHook(() => useContentFrameController());

    act(() => {
      window.dispatchEvent(new CustomEvent('shell:auth-required'));
    });

    expect(sessionStore.getState().isExpired).toBe(true);
  });

  test('isExpired reflects sessionStore state reactively', () => {
    sessionStore.setState({ isExpired: false });
    const { result } = renderHook(() => useContentFrameController());

    expect(result.current.isExpired).toBe(false);

    act(() => {
      window.dispatchEvent(new CustomEvent('shell:auth-required'));
    });

    expect(result.current.isExpired).toBe(true);
  });

  test('markActive resets expired state (simulates re-auth)', () => {
    sessionStore.setState({ isExpired: true });
    const { result } = renderHook(() => useContentFrameController());

    expect(result.current.isExpired).toBe(true);

    act(() => {
      result.current.markActive();
    });

    expect(result.current.isExpired).toBe(false);
    expect(sessionStore.getState().isExpired).toBe(false);
  });

  test('event listener is removed on unmount — no markExpired after cleanup', () => {
    sessionStore.setState({ isExpired: false });
    const { unmount } = renderHook(() => useContentFrameController());

    unmount();

    act(() => {
      window.dispatchEvent(new CustomEvent('shell:auth-required'));
    });

    expect(sessionStore.getState().isExpired).toBe(false);
  });

  test('multiple dispatches are idempotent — isExpired stays true', () => {
    sessionStore.setState({ isExpired: false });
    renderHook(() => useContentFrameController());

    act(() => {
      window.dispatchEvent(new CustomEvent('shell:auth-required'));
      window.dispatchEvent(new CustomEvent('shell:auth-required'));
    });

    expect(sessionStore.getState().isExpired).toBe(true);
  });
});
