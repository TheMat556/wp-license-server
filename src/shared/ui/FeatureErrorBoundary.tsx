import { ErrorBoundary, type FallbackProps } from 'react-error-boundary';
import type { ReactNode } from 'react';
import { __ } from '../../utils/i18n';

function Fallback({ error, resetErrorBoundary }: FallbackProps) {
  const message = error instanceof Error ? error.message : String(error);
  return (
    <div role="alert" style={{ padding: '16px', color: '#ff4d4f' }}>
      <p><strong>{__('Something went wrong in this section.', 'wp-license-server')}</strong></p>
      <pre style={{ fontSize: '12px' }}>{message}</pre>
      <button onClick={resetErrorBoundary}>{__('Try again', 'wp-license-server')}</button>
    </div>
  );
}

export function FeatureErrorBoundary({ children }: { children: ReactNode }) {
  return (
    <ErrorBoundary FallbackComponent={Fallback}>
      {children}
    </ErrorBoundary>
  );
}
