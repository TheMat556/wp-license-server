export type { NavigationAdapter } from '../../shared/navigation/NavigationAdapter';
export { spaNavigate, isActiveRoute, getCurrentRoute } from '../../utils/spaNavigate';
export { navigationStore, initNavigationStore } from './store/navigationStore';

export function initNavigation(initialRoute?: string): void {
  initNavigationStore(initialRoute);
}
