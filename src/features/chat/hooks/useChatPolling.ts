import { useEffect, useRef, useState } from 'react';

export function useChatPolling(
  pollIntervalSeconds: number,
  onPoll: (signal: AbortSignal) => Promise<void>,
  enabled: boolean,
): { isPolling: boolean } {
  const [isPolling, setIsPolling] = useState(false);
  const inFlightRef = useRef<AbortController | null>(null);

  useEffect(() => {
    if (!enabled) return;

    const tick = async () => {
      // Skip this tick if a poll is already in-flight (prevent overlap)
      if (inFlightRef.current) return;

      const controller = new AbortController();
      inFlightRef.current = controller;
      setIsPolling(true);

      try {
        await onPoll(controller.signal);
      } catch (err) {
        if (err instanceof DOMException && err.name === 'AbortError') {
          return; // expected on unmount — not an error
        }
        // real errors surface here for callers to handle via onPoll's own error boundary
      } finally {
        inFlightRef.current = null;
        setIsPolling(false);
      }
    };

    tick(); // immediate first poll
    const id = setInterval(tick, pollIntervalSeconds * 1000);

    return () => {
      clearInterval(id);
      inFlightRef.current?.abort();
      inFlightRef.current = null;
    };
  }, [enabled, pollIntervalSeconds, onPoll]);

  return { isPolling };
}
