import type { LicenseStatus } from '../../types';

interface LicenseStatusOverviewProps {
  tier: string | null;
  expiresAt: string | null;
  activationCount: number;
  maxActivations: number;
  graceDaysRemaining: number;
  status: LicenseStatus;
}

function formatExpiry(expiresAt: string | null): string {
  if (!expiresAt) return 'Never';
  return new Date(expiresAt).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

function statusLabel(status: LicenseStatus): string {
  const labels: Record<LicenseStatus, string> = {
    active: 'Active',
    expired: 'Expired',
    suspended: 'Suspended',
    cancelled: 'Cancelled',
    unknown: 'Unknown',
  };
  return labels[status];
}

export function LicenseStatusOverview({
  tier,
  expiresAt,
  activationCount,
  maxActivations,
  graceDaysRemaining,
  status,
}: LicenseStatusOverviewProps) {
  return (
    <div className="license-status-overview">
      <div className="license-status-overview__card">
        <span className="license-status-overview__label">Status</span>
        <span className={`license-status-overview__value license-status-overview__value--${status}`}>
          {statusLabel(status)}
        </span>
      </div>

      <div className="license-status-overview__card">
        <span className="license-status-overview__label">Tier</span>
        <span className="license-status-overview__value">{tier ?? '—'}</span>
      </div>

      <div className="license-status-overview__card">
        <span className="license-status-overview__label">Expires</span>
        <span className="license-status-overview__value">{formatExpiry(expiresAt)}</span>
      </div>

      <div className="license-status-overview__card">
        <span className="license-status-overview__label">Activations</span>
        <span className="license-status-overview__value">
          {activationCount} / {maxActivations}
        </span>
      </div>

      {graceDaysRemaining > 0 && (
        <div className="license-status-overview__card license-status-overview__card--warning">
          <span className="license-status-overview__label">Grace period</span>
          <span className="license-status-overview__value">
            {graceDaysRemaining} day{graceDaysRemaining !== 1 ? 's' : ''} remaining
          </span>
        </div>
      )}
    </div>
  );
}
