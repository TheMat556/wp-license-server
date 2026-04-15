import type { ShellPreferences } from '../store/shellPreferencesStore';

export const preferencesApi = {
  async save(prefs: ShellPreferences): Promise<void> {
    await fetch('/wp-json/wplicense/v1/preferences', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(prefs),
    });
  },
};
