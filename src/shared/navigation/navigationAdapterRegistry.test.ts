import { describe, expect, test, beforeEach, vi } from 'vitest';
import {
  getNavigationAdapter,
  registerNavigationAdapter,
  _resetNavigationAdapter,
} from './navigationAdapterRegistry';
import type { NavigationAdapter } from './NavigationAdapter';

describe('navigationAdapterRegistry', () => {
  beforeEach(() => {
    _resetNavigationAdapter();
  });

  test('throws when adapter not registered', () => {
    expect(() => getNavigationAdapter()).toThrow('NavigationAdapter not registered');
  });

  test('returns registered adapter', () => {
    const mock: NavigationAdapter = {
      navigate: vi.fn(),
      getCurrentRoute: vi.fn(),
      isActiveRoute: vi.fn(),
    };
    registerNavigationAdapter(mock);
    expect(getNavigationAdapter()).toBe(mock);
  });

  test('overwrites previous registration', () => {
    const first: NavigationAdapter = {
      navigate: vi.fn(),
      getCurrentRoute: vi.fn(),
      isActiveRoute: vi.fn(),
    };
    const second: NavigationAdapter = {
      navigate: vi.fn(),
      getCurrentRoute: vi.fn(),
      isActiveRoute: vi.fn(),
    };
    registerNavigationAdapter(first);
    registerNavigationAdapter(second);
    expect(getNavigationAdapter()).toBe(second);
  });
});
