import { useEffect, useRef } from 'react';
import { useStore } from 'zustand';
import { shellUiStore } from '../../store/shellUiStore';

export function useNavbarController() {
  const isCommandPaletteOpen = useStore(shellUiStore, s => s.isCommandPaletteOpen);
  const openCommandPalette = useStore(shellUiStore, s => s.openCommandPalette);
  const closeCommandPalette = useStore(shellUiStore, s => s.closeCommandPalette);

  // Ref-based DOM access for external WP admin-bar element (outside React tree).
  // Queried once on mount — never inside render or useCallback.
  const adminBarRef = useRef<Element | null>(null);
  useEffect(() => {
    adminBarRef.current = document.querySelector('#wp-admin-bar-my-account');
  }, []);

  return {
    isUserMenuOpen: isCommandPaletteOpen,
    openUserMenu: openCommandPalette,
    closeUserMenu: closeCommandPalette,
    adminBarRef,
  };
}
