import { useRef } from 'react';
import { useQuery } from '@tanstack/react-query';

export function useChatPolling(
  pollIntervalSeconds: number,
  onPoll: (signal: AbortSignal) => Promise<void>,
  enabled: boolean,
): { isPolling: boolean } {
  // Keep a ref so the latest onPoll closure is always used without
  // invalidating the query key when it changes.
  const onPollRef = useRef(onPoll);
  onPollRef.current = onPoll;

  const { isFetching } = useQuery({
    queryKey: ['chat', 'poll'],
    queryFn: ({ signal }) => onPollRef.current(signal),
    refetchInterval: pollIntervalSeconds * 1000,
    enabled,
    retry: false,
  });

  return { isPolling: isFetching };
}
