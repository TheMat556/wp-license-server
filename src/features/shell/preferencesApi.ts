import { pluginRestClient } from '../../shared/pluginRestClient';
import type { ShellPreferences } from './store/shellPreferencesStore';

export const preferencesApi = {
  async save(prefs: ShellPreferences): Promise<void> {
    await pluginRestClient.post('/wplicense/v1/preferences', prefs);
  },
};
