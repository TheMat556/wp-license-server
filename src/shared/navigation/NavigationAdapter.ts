export interface NavigationAdapter {
  navigate(url: string): void;
  getCurrentRoute(): string;
  isActiveRoute(url: string): boolean;
}
