import { createStore } from 'zustand';
import { registerNavigationAdapter } from '../../../shared/navigation/navigationAdapterRegistry';
import type { NavigationAdapter } from '../../../shared/navigation/NavigationAdapter';
import { isEmbedMessage } from '../../../types/embedMessages';

export interface NavigationStoreState {
  currentRoute: string;
  openInNewTabPatterns: string[];
  trustedOrigin: string | null;
  overlayOpen: boolean;
  navigate: (url: string) => void;
  setCurrentRoute: (route: string) => void;
  setOpenInNewTabPatterns: (patterns: string[]) => void;
  setTrustedOrigin: (origin: string) => void;
  getCurrentRoute: () => string;
  isActiveRoute: (url: string) => boolean;
  shouldOpenInNewTab: (url: string) => boolean;
  handleEmbedMessage: (event: MessageEvent) => void;
}

export const navigationStore = createStore<NavigationStoreState>((set, get) => ({
  currentRoute: typeof window !== 'undefined' ? window.location.pathname : '/',
  openInNewTabPatterns: [],
  trustedOrigin: null,
  overlayOpen: false,

  navigate(url: string): void {
    const resolved = url.startsWith('http') ? new URL(url).pathname : url;
    if (resolved === get().currentRoute) return;
    window.history.pushState(null, '', url);
    set({ currentRoute: resolved });
  },

  setCurrentRoute: (route) => set({ currentRoute: route }),

  setOpenInNewTabPatterns: (patterns) => set({ openInNewTabPatterns: patterns }),

  setTrustedOrigin: (origin) => set({ trustedOrigin: origin }),

  getCurrentRoute: () => get().currentRoute,

  isActiveRoute(url: string): boolean {
    const path = url.startsWith('http') ? new URL(url).pathname : url;
    const current = get().currentRoute;
    return current === path || current.startsWith(path + '/');
  },

  shouldOpenInNewTab(url: string): boolean {
    return get().openInNewTabPatterns.some(pattern => url.includes(pattern));
  },

  handleEmbedMessage(event: MessageEvent): void {
    const { trustedOrigin } = get();
    if (!trustedOrigin || event.origin !== trustedOrigin) return;
    if (!isEmbedMessage(event.data)) return;

    const msg = event.data;
    switch (msg.type) {
      case 'page-ready': {
        try {
          const url = new URL(msg.url);
          if (url.origin !== trustedOrigin) return;
          if (!url.searchParams.has('wp_shell_embed')) return;
          set({ currentRoute: url.pathname + url.search });
        } catch {
          return;
        }
        break;
      }
      case 'overlay-state':
        set({ overlayOpen: msg.isOpen });
        break;
      case 'session-expired':
        window.dispatchEvent(new CustomEvent('shell:auth-required'));
        break;
      case 'title-change':
      case 'breakout':
        break;
    }
  },
}));

/** NavigationAdapter implementation backed by the Zustand store. */
export const navigationStoreAdapter: NavigationAdapter = {
  navigate: (url) => navigationStore.getState().navigate(url),
  getCurrentRoute: () => navigationStore.getState().getCurrentRoute(),
  isActiveRoute: (url) => navigationStore.getState().isActiveRoute(url),
};

/** Register the store-backed adapter and wire popstate. */
export function initNavigationStore(initialRoute?: string): void {
  if (initialRoute) {
    navigationStore.setState({ currentRoute: initialRoute });
  }
  registerNavigationAdapter(navigationStoreAdapter);

  if (typeof window !== 'undefined') {
    window.addEventListener('popstate', () => {
      navigationStore.setState({ currentRoute: window.location.pathname });
    });
  }
}

