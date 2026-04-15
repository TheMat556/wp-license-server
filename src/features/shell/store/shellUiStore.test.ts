import { describe, expect, test, afterEach } from 'vitest';
import { shellUiStore } from './shellUiStore';

afterEach(() => {
  shellUiStore.setState({ isCommandPaletteOpen: false, activeSidebarPanel: null });
});

describe('shellUiStore', () => {
  test('openCommandPalette sets isCommandPaletteOpen to true', () => {
    shellUiStore.getState().openCommandPalette();
    expect(shellUiStore.getState().isCommandPaletteOpen).toBe(true);
  });

  test('closeCommandPalette sets isCommandPaletteOpen back to false', () => {
    shellUiStore.setState({ isCommandPaletteOpen: true });
    shellUiStore.getState().closeCommandPalette();
    expect(shellUiStore.getState().isCommandPaletteOpen).toBe(false);
  });

  test('setActiveSidebarPanel updates the active panel', () => {
    shellUiStore.getState().setActiveSidebarPanel('plugins');
    expect(shellUiStore.getState().activeSidebarPanel).toBe('plugins');
  });

  test('setActiveSidebarPanel accepts null to close all panels', () => {
    shellUiStore.setState({ activeSidebarPanel: 'plugins' });
    shellUiStore.getState().setActiveSidebarPanel(null);
    expect(shellUiStore.getState().activeSidebarPanel).toBeNull();
  });

  test('UI state changes do not affect shellPreferencesStore', async () => {
    const { shellPreferencesStore } = await import('./shellPreferencesStore');
    const before = shellPreferencesStore.getState().highContrast;
    shellUiStore.getState().openCommandPalette();
    expect(shellPreferencesStore.getState().highContrast).toBe(before);
  });
});
