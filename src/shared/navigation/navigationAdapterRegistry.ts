import type { NavigationAdapter } from './NavigationAdapter';

let adapter: NavigationAdapter | null = null;

export function registerNavigationAdapter(impl: NavigationAdapter): void {
  adapter = impl;
}

export function getNavigationAdapter(): NavigationAdapter {
  if (!adapter) throw new Error('NavigationAdapter not registered');
  return adapter;
}

/** Reset for testing only */
export function _resetNavigationAdapter(): void {
  adapter = null;
}
