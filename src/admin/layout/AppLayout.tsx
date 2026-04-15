import { FeatureErrorBoundary } from '../../shared/ui/FeatureErrorBoundary';

interface AppLayoutProps {
  children: React.ReactNode;
}

export function AppLayout({ children }: AppLayoutProps) {
  return <FeatureErrorBoundary>{children}</FeatureErrorBoundary>;
}
