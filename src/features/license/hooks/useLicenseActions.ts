import { useStore } from 'zustand';
import { licenseStore } from '../store/licenseStore';

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
      const res = await fetch('/wp-json/wplicense/v1/license/activate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key }),
      });
      if (!res.ok) {
        setError(`Activation failed (${res.status})`);
        return false;
      }
      const data = await res.json();
      setLicense(data.license);
      return true;
    } catch {
      setError('Network error during activation');
      return false;
    } finally {
      setLoading(false);
    }
  };

  const deactivate = async (): Promise<boolean> => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch('/wp-json/wplicense/v1/license/deactivate', { method: 'POST' });
      if (!res.ok) {
        setError(`Deactivation failed (${res.status})`);
        return false;
      }
      licenseStore.setState({ license: null });
      return true;
    } catch {
      setError('Network error during deactivation');
      return false;
    } finally {
      setLoading(false);
    }
  };

  const refresh = async (): Promise<boolean> => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch('/wp-json/wplicense/v1/license/status');
      if (!res.ok) {
        setError(`Status refresh failed (${res.status})`);
        return false;
      }
      const data = await res.json();
      setLicense(data.license);
      return true;
    } catch {
      setError('Network error during refresh');
      return false;
    } finally {
      setLoading(false);
    }
  };

  return { activate, deactivate, refresh, isLoading, error };
}
