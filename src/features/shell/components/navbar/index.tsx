import { lazy, Suspense } from 'react';
import { MirroredAdminBarAction } from './MirroredAdminBarAction';
import { useNavbarController } from './useNavbarController';

// Lazy-load panels — deferred until first open, excluded from initial bundle
const UserMenuPanel = lazy(() =>
  import('./UserMenuPanel').then(m => ({ default: m.UserMenuPanel })),
);
const HistoryPanel = lazy(() =>
  import('./HistoryPanel').then(m => ({ default: m.HistoryPanel })),
);

interface NavbarProps {
  siteName: string;
  userAvatarHtml?: string;
  historyEntries?: Array<{ label: string; url: string; visitedAt: string }>;
}

export function Navbar({ siteName, userAvatarHtml = '', historyEntries }: NavbarProps) {
  const { isUserMenuOpen, openUserMenu, closeUserMenu } = useNavbarController();

  return (
    <header className="navbar" role="banner">
      <div className="navbar__brand">
        <span className="navbar__site-name">{siteName}</span>
      </div>

      <nav className="navbar__actions" aria-label="Toolbar">
        {userAvatarHtml && (
          <MirroredAdminBarAction
            html={userAvatarHtml}
            onClick={isUserMenuOpen ? closeUserMenu : openUserMenu}
            aria-label="User menu"
          />
        )}
      </nav>

      {isUserMenuOpen && (
        <Suspense fallback={<div className="panel-loading" aria-busy="true" />}>
          <UserMenuPanel />
        </Suspense>
      )}

      {historyEntries && historyEntries.length > 0 && (
        <Suspense fallback={<div className="panel-loading" aria-busy="true" />}>
          <HistoryPanel entries={historyEntries} />
        </Suspense>
      )}
    </header>
  );
}
