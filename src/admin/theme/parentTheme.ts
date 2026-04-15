import { useLayoutEffect, useRef, useState } from 'react';

export const DEFAULT_PRIMARY_COLOR = '#4f46e5';
export const DEFAULT_FONT_FAMILY =
  "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif";

const THEME_STORAGE_KEY = 'wp-react-ui-theme';
const THEME_CHANGE_EVENT = 'wp-react-ui-theme-change';
const SHELL_EMBED_MESSAGE_SOURCE = 'wp-shell-embed';
const SHELL_EMBED_MESSAGE_VERSION = 1;

const APP_CONTAINER_ID = 'wp-license-server-admin-root';
const APP_ROOT_ID = 'wp-license-server-admin-react-root';

const THEME_CSS_VARS = [
  '--font-display',
  '--font-body',
  '--wp-react-ui-font-family',
  '--focus-ring',
  '--color-bg-app',
  '--color-bg-surface',
  '--color-bg-surface-muted',
  '--color-bg-overlay',
  '--color-border-subtle',
  '--color-border-strong',
  '--color-text-primary',
  '--color-text-secondary',
  '--color-text-muted',
  '--color-text-on-accent',
  '--color-accent-primary',
  '--color-accent-primary-hover',
  '--color-accent-soft',
  '--color-success',
  '--color-warning',
  '--color-danger',
  '--color-info',
  '--shell-chrome-bg',
  '--shell-chrome-raised',
  '--surface-inset',
  '--shadow-sm',
  '--shadow-md',
  '--shadow-lg',
] as const;

export interface ParentTheme {
  isDark: boolean;
  isHighContrast: boolean;
  primaryColor: string;
  fontFamily: string;
  cssVars: Record<string, string>;
}

function isHtmlElement(value: unknown): value is HTMLElement {
  return (
    !!value &&
    typeof value === 'object' &&
    'nodeType' in value &&
    value.nodeType === Node.ELEMENT_NODE &&
    'style' in value
  );
}

export function getOverlayContainer(): HTMLElement {
  return document.body;
}

export function postShellOverlayState(active: boolean) {
  if (window.parent === window) {
    return;
  }

  window.parent.postMessage(
    {
      source: SHELL_EMBED_MESSAGE_SOURCE,
      version: SHELL_EMBED_MESSAGE_VERSION,
      type: 'overlay-state',
      active,
    },
    window.location.origin,
  );
}

export function getPopupContainer(node?: HTMLElement | null): HTMLElement {
  return node?.ownerDocument?.body ?? document.body;
}

function getParentThemeTargets() {
  try {
    const parentDoc = window.parent?.document;
    if (!parentDoc || parentDoc === document) {
      return null;
    }

    const body = isHtmlElement(parentDoc.body) ? parentDoc.body : null;
    const shellRootElement = parentDoc.getElementById('react-shell-root');
    const shellRoot = isHtmlElement(shellRootElement) ? shellRootElement : null;

    return {
      parentDoc,
      body,
      shellRoot,
      source: shellRoot ?? body ?? parentDoc.documentElement,
    };
  } catch {
    return null;
  }
}

function collectThemeVars(source: HTMLElement): Record<string, string> {
  const styles = getComputedStyle(source);
  return THEME_CSS_VARS.reduce<Record<string, string>>((vars, name) => {
    const value = styles.getPropertyValue(name).trim();
    if (value) {
      vars[name] = value;
    }
    return vars;
  }, {});
}

function readStoredThemePreference(): 'light' | 'dark' | null {
  try {
    const stored = window.localStorage.getItem(THEME_STORAGE_KEY);
    return stored === 'light' || stored === 'dark' ? stored : null;
  } catch {
    return null;
  }
}

function readCurrentDocumentTheme(): ParentTheme {
  const body = isHtmlElement(document.body) ? document.body : null;
  const shellRootElement = document.getElementById(APP_ROOT_ID);
  const shellRoot = isHtmlElement(shellRootElement) ? shellRootElement : null;
  const source = shellRoot ?? body ?? document.documentElement;
  const explicitTheme =
    body?.getAttribute('data-theme') ?? document.documentElement.getAttribute('data-theme');
  const storedTheme = readStoredThemePreference();
  const prefersDark =
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-color-scheme: dark)').matches;
  const cssVars = collectThemeVars(source);

  return {
    isDark:
      explicitTheme === 'dark' ||
      body?.classList.contains('wp-react-dark') === true ||
      (!explicitTheme && storedTheme === 'dark') ||
      (!explicitTheme && storedTheme === null && prefersDark),
    isHighContrast:
      body?.classList.contains('wp-react-ui-high-contrast') === true ||
      document.documentElement.classList.contains('wp-react-ui-high-contrast'),
    primaryColor: cssVars['--color-accent-primary'] ?? DEFAULT_PRIMARY_COLOR,
    fontFamily:
      cssVars['--wp-react-ui-font-family'] ?? cssVars['--font-body'] ?? DEFAULT_FONT_FAMILY,
    cssVars,
  };
}

function readParentTheme(): ParentTheme {
  const targets = getParentThemeTargets();
  if (!targets) {
    return readCurrentDocumentTheme();
  }

  const themeTarget = targets.shellRoot ?? targets.body ?? targets.parentDoc.documentElement;
  const isDark =
    targets.body?.getAttribute('data-theme') === 'dark' ||
    targets.shellRoot?.getAttribute('data-theme') === 'dark' ||
    themeTarget.getAttribute('data-theme') === 'dark' ||
    targets.body?.classList.contains('wp-react-dark') === true;
  const isHighContrast =
    targets.body?.classList.contains('wp-react-ui-high-contrast') === true ||
    targets.shellRoot?.classList.contains('wp-react-ui-high-contrast') === true ||
    themeTarget.classList.contains('wp-react-ui-high-contrast');
  const cssVars = collectThemeVars(targets.source);

  return {
    isDark,
    isHighContrast,
    primaryColor: cssVars['--color-accent-primary'] ?? DEFAULT_PRIMARY_COLOR,
    fontFamily:
      cssVars['--wp-react-ui-font-family'] ?? cssVars['--font-body'] ?? DEFAULT_FONT_FAMILY,
    cssVars,
  };
}

function applyThemeToIframe(themeState: ParentTheme) {
  const body = document.body;
  const appContainerElement = document.getElementById(APP_CONTAINER_ID);
  const appContainer = isHtmlElement(appContainerElement) ? appContainerElement : null;
  const shellRootElement = document.getElementById(APP_ROOT_ID);
  const shellRoot = isHtmlElement(shellRootElement) ? shellRootElement : null;
  const targets = [document.documentElement, body, appContainer, shellRoot].filter(
    (target): target is HTMLElement => isHtmlElement(target),
  );

  for (const target of targets) {
    for (const [name, value] of Object.entries(themeState.cssVars)) {
      target.style.setProperty(name, value);
    }

    target.setAttribute('data-theme', themeState.isDark ? 'dark' : 'light');
    target.classList.toggle('wp-react-ui-high-contrast', themeState.isHighContrast);
  }

  if (body) {
    body.classList.toggle('wp-react-dark', themeState.isDark);
  }
}

export function useParentTheme(): ParentTheme {
  const [state, setState] = useState<ParentTheme>(readParentTheme);
  const observerRef = useRef<MutationObserver | null>(null);

  useLayoutEffect(() => {
    const scheduleRefresh = () => {
      window.requestAnimationFrame(() => {
        const next = readParentTheme();
        applyThemeToIframe(next);
        setState(next);
      });
    };

    const refresh = () => {
      const next = readParentTheme();
      applyThemeToIframe(next);
      setState(next);
    };

    const handleThemeEvent = () => scheduleRefresh();
    const handleMessage = (event: MessageEvent) => {
      const data = event.data;
      if (data && typeof data === 'object' && 'type' in data && data.type === THEME_CHANGE_EVENT) {
        scheduleRefresh();
      }
    };

    refresh();

    window.addEventListener('storage', refresh);
    window.addEventListener(THEME_CHANGE_EVENT, handleThemeEvent as EventListener);
    window.addEventListener('message', handleMessage);

    const mediaQuery =
      typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;
    const handleMediaChange = () => refresh();
    mediaQuery?.addEventListener?.('change', handleMediaChange);

    const targets = getParentThemeTargets();
    if (!targets) {
      return () => {
        window.removeEventListener('storage', refresh);
        window.removeEventListener(THEME_CHANGE_EVENT, handleThemeEvent as EventListener);
        window.removeEventListener('message', handleMessage);
        mediaQuery?.removeEventListener?.('change', handleMediaChange);
      };
    }

    try {
      observerRef.current = new MutationObserver(refresh);

      for (const target of [targets.parentDoc.documentElement, targets.body, targets.shellRoot]) {
        if (isHtmlElement(target)) {
          observerRef.current.observe(target, {
            attributes: true,
            attributeFilter: ['class', 'data-theme', 'style'],
          });
        }
      }

      targets.parentDoc.defaultView?.addEventListener(
        THEME_CHANGE_EVENT,
        handleThemeEvent as EventListener,
      );
    } catch {
      // Cross-origin or parent window access unavailable.
    }

    return () => {
      observerRef.current?.disconnect();
      window.removeEventListener('storage', refresh);
      window.removeEventListener(THEME_CHANGE_EVENT, handleThemeEvent as EventListener);
      window.removeEventListener('message', handleMessage);
      mediaQuery?.removeEventListener?.('change', handleMediaChange);
      try {
        targets.parentDoc.defaultView?.removeEventListener(
          THEME_CHANGE_EVENT,
          handleThemeEvent as EventListener,
        );
      } catch {
        // No-op.
      }
    };
  }, []);

  return state;
}
