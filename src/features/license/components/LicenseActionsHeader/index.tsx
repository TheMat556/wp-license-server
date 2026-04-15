import type { LicenseStatus } from '../../types';

interface LicenseActionsHeaderProps {
  status: LicenseStatus;
  onActivate: () => void;
  onDeactivate: () => void;
  onRefresh: () => void;
  isLoading: boolean;
}

const ACTION_LABEL: Record<LicenseStatus, string | null> = {
  active: 'Deactivate',
  expired: 'Re-activate',
  suspended: null,
  cancelled: null,
  unknown: 'Activate',
  disabled: null,
  grace: 'Deactivate',
};

export function LicenseActionsHeader({
  status,
  onActivate,
  onDeactivate,
  onRefresh,
  isLoading,
}: LicenseActionsHeaderProps) {
  const primaryLabel = ACTION_LABEL[status];
  const isPrimaryDeactivate = status === 'active';

  return (
    <div className="license-actions-header">
      <div className="license-actions-header__copy">
        <h2 className="license-actions-header__title">License</h2>
        <p className="license-actions-header__description">
          Manage your license key and view activation details.
        </p>
      </div>

      <div className="license-actions-header__actions">
        <button
          className="license-actions-header__refresh"
          onClick={onRefresh}
          disabled={isLoading}
          aria-label="Refresh license status"
        >
          Refresh
        </button>

        {primaryLabel && (
          <button
            className={`license-actions-header__primary${isPrimaryDeactivate ? ' license-actions-header__primary--destructive' : ''}`}
            onClick={isPrimaryDeactivate ? onDeactivate : onActivate}
            disabled={isLoading}
          >
            {isLoading ? 'Loading…' : primaryLabel}
          </button>
        )}
      </div>
    </div>
  );
}
