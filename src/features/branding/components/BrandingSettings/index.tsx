import { useStore } from 'zustand';
import { shellPreferencesStore } from '../../../shell/store/shellPreferencesStore';

/**
 * BrandingSettings renders preference toggles and writes to shellPreferencesStore.
 * DOM class application (high-contrast) is handled entirely by the store subscriber
 * registered in bootstrapShell — this component never touches classList directly.
 */
export function BrandingSettings() {
  const sidebarCollapsed = useStore(shellPreferencesStore, s => s.sidebarCollapsed);
  const highContrast = useStore(shellPreferencesStore, s => s.highContrast);
  const setSidebarCollapsed = useStore(shellPreferencesStore, s => s.setSidebarCollapsed);
  const setHighContrast = useStore(shellPreferencesStore, s => s.setHighContrast);

  return (
    <div className="branding-settings">
      <label className="branding-settings__field">
        <span>Collapse sidebar by default</span>
        <input
          type="checkbox"
          checked={sidebarCollapsed}
          onChange={e => setSidebarCollapsed(e.target.checked)}
        />
      </label>

      <label className="branding-settings__field">
        <span>High contrast mode</span>
        <input
          type="checkbox"
          checked={highContrast}
          onChange={e => setHighContrast(e.target.checked)}
        />
      </label>
    </div>
  );
}
