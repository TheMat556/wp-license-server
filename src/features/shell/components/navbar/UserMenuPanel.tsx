import { useStore } from 'zustand';
import { shellPreferencesStore } from '../../store/shellPreferencesStore';
import { shellUiStore } from '../../store/shellUiStore';

export function UserMenuPanel() {
  const highContrast = useStore(shellPreferencesStore, s => s.highContrast);
  const setHighContrast = useStore(shellPreferencesStore, s => s.setHighContrast);
  const close = useStore(shellUiStore, s => s.closeCommandPalette);

  return (
    <div className="user-menu-panel" role="dialog" aria-label="User menu">
      <button className="user-menu-panel__close" onClick={close} aria-label="Close user menu">
        ✕
      </button>
      <ul className="user-menu-panel__items">
        <li className="user-menu-panel__item">
          <label>
            <input
              type="checkbox"
              checked={highContrast}
              onChange={e => setHighContrast(e.target.checked)}
            />
            High contrast
          </label>
        </li>
        <li className="user-menu-panel__item">
          <a href="/wp-admin/profile.php">Edit profile</a>
        </li>
        <li className="user-menu-panel__item">
          <a href="/wp-login.php?action=logout">Log out</a>
        </li>
      </ul>
    </div>
  );
}
