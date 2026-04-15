import { createStore } from 'zustand';

interface SessionState {
  isExpired: boolean;
  markExpired: () => void;
  markActive: () => void;
}

export const sessionStore = createStore<SessionState>(set => ({
  isExpired: false,
  markExpired: () => set({ isExpired: true }),
  markActive: () => set({ isExpired: false }),
}));
