import { getNavigationAdapter } from '../shared/navigation/navigationAdapterRegistry';

/**
 * Navigate to `url` using the registered NavigationAdapter.
 * Falls back to `window.location.href` if the adapter is not yet registered
 * (e.g. during server-side rendering or tests without a registered adapter).
 */
export function spaNavigate(url: string): void {
  try {
    getNavigationAdapter().navigate(url);
  } catch {
    window.location.href = url;
  }
}

/** Return true if `url` is the currently active route. */
export function isActiveRoute(url: string): boolean {
  try {
    return getNavigationAdapter().isActiveRoute(url);
  } catch {
    return window.location.pathname === url;
  }
}

/** Return the current route as reported by the registered adapter. */
export function getCurrentRoute(): string {
  try {
    return getNavigationAdapter().getCurrentRoute();
  } catch {
    return window.location.pathname;
  }
}
