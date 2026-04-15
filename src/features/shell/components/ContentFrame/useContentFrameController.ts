import { useEffect } from 'react';
import { useStore } from 'zustand';
import { sessionStore } from '../../store/sessionStore';

export function useContentFrameController() {
  const isExpired = useStore(sessionStore, s => s.isExpired);
  const markActive = useStore(sessionStore, s => s.markActive);

  useEffect(() => {
    const handler = () => {
      sessionStore.getState().markExpired();
    };
    window.addEventListener('shell:auth-required', handler as EventListener);
    return () => window.removeEventListener('shell:auth-required', handler as EventListener);
  }, []);

  return { isExpired, markActive };
}
