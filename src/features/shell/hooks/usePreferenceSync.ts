import { useEffect } from 'react';
import { useStore } from 'zustand';
import { useShallow } from 'zustand/react/shallow';
import { shellPreferencesStore, selectSyncablePreferences } from '../store/shellPreferencesStore';
import { preferencesApi } from '../preferencesApi';

const DEBOUNCE_MS = 800;

/**
 * Subscribes to syncable preferences and debounces saves to the server.
 * Mount once near the shell root — not inside per-setting components.
 * The debounce timer is owned by the effect, not the store.
 */
export function usePreferenceSync(): void {
  const preferences = useStore(shellPreferencesStore, useShallow(selectSyncablePreferences));

  useEffect(() => {
    const id = setTimeout(() => {
      preferencesApi.save(preferences);
    }, DEBOUNCE_MS);

    return () => clearTimeout(id);
  }, [preferences]);
}
