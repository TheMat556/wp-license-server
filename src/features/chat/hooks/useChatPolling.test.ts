import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { useChatPolling } from './useChatPolling';

describe('useChatPolling', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  test('calls onPoll immediately when enabled', async () => {
    const onPoll = vi.fn().mockResolvedValue(undefined);
    renderHook(() => useChatPolling(60, onPoll, true));
    // tick() fires immediately without waiting for the interval
    await vi.advanceTimersByTimeAsync(0);
    expect(onPoll).toHaveBeenCalledTimes(1);
  });

  test('does not call onPoll when disabled', async () => {
    const onPoll = vi.fn().mockResolvedValue(undefined);
    renderHook(() => useChatPolling(60, onPoll, false));
    await vi.runAllTimersAsync();
    expect(onPoll).not.toHaveBeenCalled();
  });

  test('aborts in-flight request on unmount', async () => {
    const abortSpy = vi.fn();
    const onPoll = vi.fn(async (signal: AbortSignal) => {
      signal.addEventListener('abort', abortSpy);
      await new Promise(resolve => setTimeout(resolve, 500)); // slow poll
    });

    const { unmount } = renderHook(() => useChatPolling(60, onPoll, true));

    unmount();
    expect(abortSpy).toHaveBeenCalledTimes(1);
  });

  test('does not start a new poll if previous is still in-flight', async () => {
    let resolveFirst!: () => void;
    const onPoll = vi.fn(async () => {
      await new Promise<void>(r => (resolveFirst = r));
    });

    renderHook(() => useChatPolling(0.05, onPoll, true)); // 50ms interval
    await vi.advanceTimersByTimeAsync(200); // 4 interval ticks
    expect(onPoll).toHaveBeenCalledTimes(1); // only first tick fired
    resolveFirst();
  });

  test('polls again after interval once previous completes', async () => {
    const onPoll = vi.fn(async () => {
      // resolves immediately; test just checks multiple ticks fire
    });

    renderHook(() => useChatPolling(0.1, onPoll, true)); // 100ms interval
    await vi.advanceTimersByTimeAsync(50);  // first tick fires immediately
    await vi.advanceTimersByTimeAsync(150); // second tick after interval
    expect(onPoll.mock.calls.length).toBeGreaterThanOrEqual(2);
  });

  test('passes AbortSignal to onPoll', async () => {
    let receivedSignal: AbortSignal | undefined;
    const onPoll = vi.fn(async (signal: AbortSignal) => {
      receivedSignal = signal;
    });

    renderHook(() => useChatPolling(60, onPoll, true));
    await vi.advanceTimersByTimeAsync(0);
    expect(receivedSignal).toBeInstanceOf(AbortSignal);
  });

  test('silently ignores AbortError', async () => {
    const onPoll = vi.fn(async (signal: AbortSignal) => {
      // Simulate fetch throwing AbortError when signal fires
      await new Promise<void>((_, reject) => {
        signal.addEventListener('abort', () => {
          const err = new DOMException('Aborted', 'AbortError');
          reject(err);
        });
      });
    });

    const { unmount } = renderHook(() => useChatPolling(60, onPoll, true));
    // Should not throw
    expect(() => unmount()).not.toThrow();
  });
});
