import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { createElement } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useChatPolling } from './useChatPolling';

function makeWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return createElement(QueryClientProvider, { client: queryClient }, children);
  };
}

describe('useChatPolling', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  test('calls onPoll immediately when enabled', async () => {
    const onPoll = vi.fn().mockResolvedValue(undefined);
    renderHook(() => useChatPolling(60, onPoll, true), { wrapper: makeWrapper() });
    await vi.advanceTimersByTimeAsync(0);
    expect(onPoll).toHaveBeenCalledTimes(1);
  });

  test('does not call onPoll when disabled', async () => {
    const onPoll = vi.fn().mockResolvedValue(undefined);
    renderHook(() => useChatPolling(60, onPoll, false), { wrapper: makeWrapper() });
    await vi.runAllTimersAsync();
    expect(onPoll).not.toHaveBeenCalled();
  });

  test('passes AbortSignal to onPoll', async () => {
    let receivedSignal: AbortSignal | undefined;
    const onPoll = vi.fn(async (signal: AbortSignal) => {
      receivedSignal = signal;
    });

    renderHook(() => useChatPolling(60, onPoll, true), { wrapper: makeWrapper() });
    await vi.advanceTimersByTimeAsync(0);
    expect(receivedSignal).toBeInstanceOf(AbortSignal);
  });

  test('polls again after interval once previous completes', async () => {
    const onPoll = vi.fn(async () => {
      // resolves immediately; test just checks multiple ticks fire
    });

    renderHook(() => useChatPolling(0.1, onPoll, true), { wrapper: makeWrapper() }); // 100ms interval
    await vi.advanceTimersByTimeAsync(50);  // first tick fires immediately
    await vi.advanceTimersByTimeAsync(150); // second tick after interval
    expect(onPoll.mock.calls.length).toBeGreaterThanOrEqual(2);
  });

  test('returns isPolling false when disabled', () => {
    const onPoll = vi.fn().mockResolvedValue(undefined);
    const { result } = renderHook(() => useChatPolling(60, onPoll, false), {
      wrapper: makeWrapper(),
    });
    expect(result.current.isPolling).toBe(false);
  });
});
