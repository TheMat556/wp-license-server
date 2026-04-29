import { pluginRestClient } from '../../shared/pluginRestClient';
import type { ShellPreferences } from './store/shellPreferencesStore';

export const preferencesApi = {
  async save(prefs: ShellPreferences): Promise<void> {
    await pluginRestClient.post('/license-server/v1/preferences', prefs);
  },
};
