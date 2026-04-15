type StoreEventMap = {
  'branding:openInNewTabPatterns': { patterns: string[] };
};

type Listener<T> = (payload: T) => void;

const listeners = new Map<string, Set<Listener<unknown>>>();

export const storeEvents = {
  emit<K extends keyof StoreEventMap>(event: K, payload: StoreEventMap[K]): void {
    listeners.get(event)?.forEach(fn => fn(payload as unknown));
  },
  on<K extends keyof StoreEventMap>(event: K, fn: Listener<StoreEventMap[K]>): () => void {
    if (!listeners.has(event)) listeners.set(event, new Set());
    listeners.get(event)!.add(fn as Listener<unknown>);
    return () => listeners.get(event)?.delete(fn as Listener<unknown>);
  },
};
