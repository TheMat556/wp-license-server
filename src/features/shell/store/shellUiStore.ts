import { createStore } from 'zustand';

interface ShellUiActions {
  openCommandPalette: () => void;
  closeCommandPalette: () => void;
  setActiveSidebarPanel: (panel: string | null) => void;
}

export interface ShellUiState {
  isCommandPaletteOpen: boolean;
  activeSidebarPanel: string | null;
}

export const shellUiStore = createStore<ShellUiState & ShellUiActions>()(set => ({
  isCommandPaletteOpen: false,
  activeSidebarPanel: null,

  openCommandPalette: () => set({ isCommandPaletteOpen: true }),
  closeCommandPalette: () => set({ isCommandPaletteOpen: false }),
  setActiveSidebarPanel: (panel) => set({ activeSidebarPanel: panel }),
}));
