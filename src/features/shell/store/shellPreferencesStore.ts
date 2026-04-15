import { createStore } from 'zustand';

export interface ShellPreferences {
  sidebarCollapsed: boolean;
  highContrast: boolean;
}

interface ShellPreferencesActions {
  setSidebarCollapsed: (collapsed: boolean) => void;
  setHighContrast: (enabled: boolean) => void;
  hydrate: (prefs: Partial<ShellPreferences>) => void;
}

export type ShellPreferencesState = ShellPreferences & ShellPreferencesActions;

export const shellPreferencesStore = createStore<ShellPreferencesState>()(set => ({
  sidebarCollapsed: false,
  highContrast: false,

  setSidebarCollapsed: (collapsed) => set({ sidebarCollapsed: collapsed }),
  setHighContrast: (enabled) => set({ highContrast: enabled }),
  hydrate: (prefs) => set(state => ({ ...state, ...prefs })),
}));

/** Selector — returns only the fields that need to be synced to the server. */
export function selectSyncablePreferences(state: ShellPreferencesState): ShellPreferences {
  return {
    sidebarCollapsed: state.sidebarCollapsed,
    highContrast: state.highContrast,
  };
}
