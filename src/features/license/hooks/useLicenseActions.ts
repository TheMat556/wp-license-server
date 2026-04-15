import { useStore } from 'zustand';
import { licenseStore } from '../store/licenseStore';
import { pluginRestClient } from '../../../shared/pluginRestClient';

interface ActivateResponse {
  license: import('../types').License;
}

interface StatusResponse {
  license: import('../types').License;
}

export function useLicenseActions() {
  const isLoading = useStore(licenseStore, s => s.isLoading);
  const error = useStore(licenseStore, s => s.error);
  const setLoading = useStore(licenseStore, s => s.setLoading);
  const setError = useStore(licenseStore, s => s.setError);
  const setLicense = useStore(licenseStore, s => s.setLicense);

  const activate = async (key: string): Promise<boolean> => {
    setLoading(true);
    setError(null);
    try {
      const data = await pluginRestClient.post<ActivateResponse>('/wplicense/v1/license/activate', { key });
      setLicense(data.license);
      return true;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Activation failed');
      return false;
    } finally {
      setLoading(false);
    }
  };

  const deactivate = async (): Promise<boolean> => {
    setLoading(true);
    setError(null);
    try {
      await pluginRestClient.post('/wplicense/v1/license/deactivate', {});
      licenseStore.setState({ license: null });
      return true;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Deactivation failed');
      return false;
    } finally {
      setLoading(false);
    }
  };

  const refresh = async (): Promise<boolean> => {
    setLoading(true);
    setError(null);
    try {
      const data = await pluginRestClient.get<StatusResponse>('/wplicense/v1/license/status');
      setLicense(data.license);
      return true;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Status refresh failed');
      return false;
    } finally {
      setLoading(false);
    }
  };

  return { activate, deactivate, refresh, isLoading, error };
}
