import './admin.css';
import { Component, type ErrorInfo, type ReactNode, useLayoutEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { HashRouter } from 'react-router-dom';
import AdminApp from './AdminApp';

const queryClient = new QueryClient();

const appContainer = document.getElementById('wp-license-server-admin-root');
const fallback = document.getElementById('wp-license-server-admin-fallback');
const root = document.getElementById('wp-license-server-admin-react-root');

function restoreFallback() {
  document.body.classList.remove('wp-license-server-admin-js');
  if (appContainer) {
    delete appContainer.dataset.mounted;
    appContainer.style.display = 'none';
  }
  if (fallback) {
    fallback.style.display = 'block';
  }
}

function AppMountSignal() {
  useLayoutEffect(() => {
    if (appContainer) {
      appContainer.dataset.mounted = 'true';
      appContainer.style.display = 'block';
    }
    if (fallback) {
      fallback.style.display = 'none';
    }
    window.dispatchEvent(new CustomEvent('wp-license-server-admin-mounted'));
  }, []);

  return <AdminApp />;
}

class AdminErrorBoundary extends Component<{ children: ReactNode }, { hasError: boolean }> {
  public override state = { hasError: false };

  public static getDerivedStateFromError(): { hasError: boolean } {
    return { hasError: true };
  }

  public override componentDidCatch(_error: Error, _errorInfo: ErrorInfo) {
    restoreFallback();
  }

  public override render() {
    if (this.state.hasError) {
      return null;
    }

    return this.props.children;
  }
}

if (root && appContainer) {
  try {
    root.classList.add('mounted');
    appContainer.style.display = 'block';
    if (fallback) {
      fallback.style.display = 'none';
    }
    createRoot(root).render(
      <QueryClientProvider client={queryClient}>
        <AdminErrorBoundary>
          <HashRouter>
            <AppMountSignal />
          </HashRouter>
        </AdminErrorBoundary>
      </QueryClientProvider>,
    );
  } catch (error) {
    restoreFallback();
    throw error;
  }
}
