import { afterEach, describe, expect, test } from 'vitest';
import { navigationStore } from './navigationStore';

afterEach(() => {
  navigationStore.setState({
    currentRoute: '/',
    openInNewTabPatterns: [],
  });
});

describe('navigationStore', () => {
  describe('setOpenInNewTabPatterns', () => {
    test('updates patterns in store', () => {
      navigationStore.getState().setOpenInNewTabPatterns(['docs.example.com']);
      expect(navigationStore.getState().openInNewTabPatterns).toEqual(['docs.example.com']);
    });

    test('replaces existing patterns', () => {
      navigationStore.setState({ openInNewTabPatterns: ['old.com'] });
      navigationStore.getState().setOpenInNewTabPatterns(['new.com']);
      expect(navigationStore.getState().openInNewTabPatterns).toEqual(['new.com']);
    });
  });

  describe('shouldOpenInNewTab', () => {
    test('uses patterns from store state, not stale bootstrap', () => {
      navigationStore.setState({ openInNewTabPatterns: ['docs.example.com'] });
      expect(
        navigationStore.getState().shouldOpenInNewTab('https://docs.example.com/guide'),
      ).toBe(true);
      expect(
        navigationStore.getState().shouldOpenInNewTab('https://app.example.com'),
      ).toBe(false);
    });

    test('returns false when patterns list is empty', () => {
      navigationStore.setState({ openInNewTabPatterns: [] });
      expect(
        navigationStore.getState().shouldOpenInNewTab('https://anything.com'),
      ).toBe(false);
    });

    test('matches any pattern in the list', () => {
      navigationStore.setState({ openInNewTabPatterns: ['docs.acme.com', 'support.acme.com'] });
      expect(navigationStore.getState().shouldOpenInNewTab('https://docs.acme.com/intro')).toBe(true);
      expect(navigationStore.getState().shouldOpenInNewTab('https://support.acme.com/tickets')).toBe(true);
      expect(navigationStore.getState().shouldOpenInNewTab('https://app.acme.com')).toBe(false);
    });

    test('reflects pattern changes without restart', () => {
      navigationStore.setState({ openInNewTabPatterns: [] });
      expect(navigationStore.getState().shouldOpenInNewTab('https://new.com')).toBe(false);

      navigationStore.getState().setOpenInNewTabPatterns(['new.com']);
      expect(navigationStore.getState().shouldOpenInNewTab('https://new.com')).toBe(true);
    });
  });

  describe('getCurrentRoute / setCurrentRoute', () => {
    test('returns current route', () => {
      navigationStore.setState({ currentRoute: '/dashboard' });
      expect(navigationStore.getState().getCurrentRoute()).toBe('/dashboard');
    });
  });

  describe('isActiveRoute', () => {
    test('exact match', () => {
      navigationStore.setState({ currentRoute: '/settings' });
      expect(navigationStore.getState().isActiveRoute('/settings')).toBe(true);
    });

    test('prefix match', () => {
      navigationStore.setState({ currentRoute: '/settings/billing' });
      expect(navigationStore.getState().isActiveRoute('/settings')).toBe(true);
    });

    test('no match', () => {
      navigationStore.setState({ currentRoute: '/dashboard' });
      expect(navigationStore.getState().isActiveRoute('/settings')).toBe(false);
    });
  });
});
