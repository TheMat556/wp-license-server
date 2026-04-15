import { useState } from 'react';
import { useStore } from 'zustand';
import { licenseStore } from '../../store/licenseStore';
import { useMaskedLicenseKeyField } from '../../hooks/useMaskedLicenseKeyField';
import { useLicenseActions } from '../../hooks/useLicenseActions';
import { LicenseStatusOverview } from '../LicenseStatusOverview';
import { LicenseActionsHeader } from '../LicenseActionsHeader';

export function LicenseSettings() {
  const license = useStore(licenseStore, s => s.license);
  const [showActivateForm, setShowActivateForm] = useState(false);

  const { activate, deactivate, refresh, isLoading, error } = useLicenseActions();
  const keyField = useMaskedLicenseKeyField(license?.key?.slice(0, 8) ?? null);

  const status = license?.status ?? 'unknown';

  const handleActivate = async () => {
    if (!keyField.draftKey) {
      setShowActivateForm(true);
      return;
    }
    const ok = await activate(keyField.draftKey);
    if (ok) {
      setShowActivateForm(false);
      keyField.setDraftKey('');
    }
  };

  const handleDeactivate = async () => {
    await deactivate();
  };

  const handleRefresh = async () => {
    await refresh();
  };

  return (
    <div className="license-settings">
      <LicenseActionsHeader
        status={status}
        onActivate={handleActivate}
        onDeactivate={handleDeactivate}
        onRefresh={handleRefresh}
        isLoading={isLoading}
      />

      {error && (
        <p role="alert" className="license-settings__error">{error}</p>
      )}

      {license && (
        <LicenseStatusOverview
          tier={license.key}
          expiresAt={license.expiresAt}
          activationCount={1}
          maxActivations={5}
          graceDaysRemaining={0}
          status={status}
        />
      )}

      {(showActivateForm || !license) && (
        <div className="license-settings__activate-form">
          <label className="license-settings__label" htmlFor="license-key-input">
            License key
          </label>
          <div className="license-settings__key-row">
            <input
              id="license-key-input"
              type={keyField.isRevealed ? 'text' : 'password'}
              className="license-settings__key-input"
              value={keyField.isRevealed ? keyField.draftKey : keyField.displayValue}
              onChange={e => keyField.setDraftKey(e.target.value)}
              placeholder="XXXX-XXXX-XXXX-XXXX"
              disabled={isLoading}
            />
            <button
              type="button"
              className="license-settings__reveal-btn"
              onClick={keyField.toggleReveal}
              aria-label={keyField.isRevealed ? 'Hide key' : 'Show key'}
            >
              {keyField.isRevealed ? 'Hide' : 'Show'}
            </button>
          </div>
          <button
            className="license-settings__submit"
            onClick={handleActivate}
            disabled={isLoading || !keyField.hasUnsavedKey}
          >
            {isLoading ? 'Activating…' : 'Activate'}
          </button>
        </div>
      )}
    </div>
  );
}
