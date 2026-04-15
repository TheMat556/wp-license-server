import { describe, expect, test, afterEach } from 'vitest';
import { shellPreferencesStore, selectSyncablePreferences } from './shellPreferencesStore';

afterEach(() => {
  shellPreferencesStore.setState({ sidebarCollapsed: false, highContrast: false });
});

describe('shellPreferencesStore', () => {
  test('initial state has sensible defaults', () => {
    expect(shellPreferencesStore.getState().sidebarCollapsed).toBe(false);
    expect(shellPreferencesStore.getState().highContrast).toBe(false);
  });

  test('setSidebarCollapsed updates the value', () => {
    shellPreferencesStore.getState().setSidebarCollapsed(true);
    expect(shellPreferencesStore.getState().sidebarCollapsed).toBe(true);
  });

  test('setHighContrast updates the value', () => {
    shellPreferencesStore.getState().setHighContrast(true);
    expect(shellPreferencesStore.getState().highContrast).toBe(true);
  });

  test('hydrate applies partial preference payload', () => {
    shellPreferencesStore.getState().hydrate({ highContrast: true });
    expect(shellPreferencesStore.getState().highContrast).toBe(true);
    expect(shellPreferencesStore.getState().sidebarCollapsed).toBe(false); // unchanged
  });

  test('selectSyncablePreferences returns only persisted fields', () => {
    shellPreferencesStore.setState({ sidebarCollapsed: true, highContrast: true });
    const synced = selectSyncablePreferences(shellPreferencesStore.getState());
    expect(synced).toEqual({ sidebarCollapsed: true, highContrast: true });
    // Actions are not included
    expect('setSidebarCollapsed' in synced).toBe(false);
  });
});
