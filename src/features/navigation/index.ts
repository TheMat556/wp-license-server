export type { NavigationAdapter } from '../../shared/navigation/NavigationAdapter';
export { spaNavigate, isActiveRoute, getCurrentRoute } from '../../utils/spaNavigate';
export { navigationStore } from './store/navigationStore';

import { initNavigationStore } from './store/navigationStore';
export { initNavigationStore };

export function initNavigation(initialRoute?: string): void {
  initNavigationStore(initialRoute);
}
