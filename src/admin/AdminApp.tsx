import { StyleProvider } from '@ant-design/cssinjs';
import { App, ConfigProvider, theme } from 'antd';
import { useMemo } from 'react';
import { AppLayout } from './layout/AppLayout';
import { AppRouter } from './router/AppRouter';
import {
  DEFAULT_FONT_FAMILY,
  DEFAULT_PRIMARY_COLOR,
  getOverlayContainer,
  getPopupContainer,
  useParentTheme,
} from './theme/parentTheme';

export default function AdminApp() {
  const parentTheme = useParentTheme();
  const isDark = parentTheme.isDark;
  const darkTokenOverrides = useMemo(
    () =>
      isDark
        ? {
            colorBgContainer: parentTheme.cssVars['--color-bg-surface'] ?? '#131c2b',
            colorBgElevated: parentTheme.cssVars['--color-bg-surface-muted'] ?? '#1a2435',
            colorBgLayout: parentTheme.cssVars['--color-bg-app'] ?? '#0f1723',
            colorFillAlter:
              parentTheme.cssVars['--surface-inset'] ??
              parentTheme.cssVars['--color-bg-surface-muted'] ??
              '#1a2435',
            colorFillSecondary:
              parentTheme.cssVars['--shell-chrome-bg'] ??
              parentTheme.cssVars['--color-bg-surface-muted'] ??
              '#1e2a3b',
            colorBorderSecondary:
              parentTheme.cssVars['--color-border-subtle'] ?? 'rgba(255,255,255,0.09)',
            colorBorder:
              parentTheme.cssVars['--color-border-strong'] ?? 'rgba(255,255,255,0.12)',
            colorText: parentTheme.cssVars['--color-text-primary'] ?? '#f8fafc',
            colorTextSecondary: parentTheme.cssVars['--color-text-secondary'] ?? '#cbd5e1',
            colorTextPlaceholder: parentTheme.cssVars['--color-text-muted'] ?? '#94a3b8',
          }
        : {},
    [isDark, parentTheme.cssVars],
  );

  return (
    <StyleProvider hashPriority="high">
      <ConfigProvider
        getPopupContainer={node => getPopupContainer(node)}
        theme={{
          algorithm: isDark ? theme.darkAlgorithm : theme.defaultAlgorithm,
          token: {
            colorPrimary: parentTheme.primaryColor ?? DEFAULT_PRIMARY_COLOR,
            borderRadius: 6,
            fontFamily: parentTheme.fontFamily ?? DEFAULT_FONT_FAMILY,
            fontSize: 13,
            zIndexPopupBase: 100260,
            ...darkTokenOverrides,
          },
          components: {
            Table: { fontSize: 13 },
            Form: { labelFontSize: 13 },
            ...(isDark && {
              Button: {
                defaultBg: 'rgba(255,255,255,0.06)',
                defaultBorderColor: 'rgba(255,255,255,0.18)',
                defaultColor: '#e2e8f0',
                defaultHoverBg: 'rgba(255,255,255,0.10)',
                defaultHoverBorderColor: 'rgba(255,255,255,0.28)',
                defaultHoverColor: '#f8fafc',
                defaultActiveBg: 'rgba(255,255,255,0.13)',
                defaultActiveBorderColor: 'rgba(255,255,255,0.32)',
              },
            }),
          },
        }}
      >
        <App
          notification={{
            placement: 'bottomRight',
            duration: 4.5,
            getContainer: getOverlayContainer,
            maxCount: 3,
          }}
        >
          <AppLayout>
            <AppRouter />
          </AppLayout>
        </App>
      </ConfigProvider>
    </StyleProvider>
  );
}
