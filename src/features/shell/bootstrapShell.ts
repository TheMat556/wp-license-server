import { navigationStore } from '../navigation/store/navigationStore';
import { brandingStore } from '../branding/store/brandingStore';
import { shellPreferencesStore } from './store/shellPreferencesStore';
import type { Branding } from '../branding/types';
import type { ShellPreferences } from './store/shellPreferencesStore';

export interface BootPayload {
  branding: Branding;
  preferences?: Partial<ShellPreferences>;
  initialRoute?: string;
}

const HIGH_CONTRAST_CLASS = 'wp-react-ui-high-contrast';

/**
 * Seeds initial application state from the server boot payload.
 * Must be called once before any feature renders.
 */
export function bootstrapShell(payload: BootPayload): void {
  // Seed branding store
  brandingStore.getState().setBranding(payload.branding);

  // Seed navigationStore as the single authoritative source for openInNewTabPatterns
  navigationStore.getState().setOpenInNewTabPatterns(
    payload.branding.openInNewTabPatterns ?? [],
  );

  if (payload.initialRoute) {
    navigationStore.getState().setCurrentRoute(payload.initialRoute);
  }

  // Hydrate persisted preferences
  if (payload.preferences) {
    shellPreferencesStore.getState().hydrate(payload.preferences);
  }

  // Apply high-contrast before first render to avoid flash
  if (shellPreferencesStore.getState().highContrast) {
    document.documentElement.classList.add(HIGH_CONTRAST_CLASS);
  }

  // Subscribe to future changes so toggling in settings takes effect immediately
  shellPreferencesStore.subscribe(
    state => state.highContrast,
    (highContrast) => {
      document.documentElement.classList.toggle(HIGH_CONTRAST_CLASS, highContrast);
    },
  );
}
