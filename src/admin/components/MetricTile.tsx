import type { CSSProperties } from 'react';
import { Typography, theme } from 'antd';

const { Text } = Typography;

interface MetricTileProps {
  label: string;
  value: string | number;
  meta: string;
  icon: React.ReactNode;
  accent?: 'primary' | 'success' | 'warning' | 'default';
}

export function MetricTile({ label, value, meta, icon, accent = 'default' }: MetricTileProps) {
  const { token } = theme.useToken();
  const accentColor =
    accent === 'primary'
      ? token.colorPrimary
      : accent === 'success'
        ? token.colorSuccess
        : accent === 'warning'
          ? token.colorWarning
          : token.colorTextSecondary;
  const style = { ['--metric-accent' as string]: accentColor } as CSSProperties;

  return (
    <div className="wp-react-ui-metric-tile" style={style}>
      <div className="wp-react-ui-metric-tile__header">
        <Text className="wp-react-ui-metric-tile__label">{label}</Text>
        <span className="wp-react-ui-metric-tile__icon">{icon}</span>
      </div>
      <div className="wp-react-ui-metric-tile__body">
        <div className="wp-react-ui-metric-tile__value">{value}</div>
      </div>
      <div className="wp-react-ui-metric-tile__footer">
        <Text type="secondary" style={{ fontSize: 13 }}>
          {meta}
        </Text>
      </div>
    </div>
  );
}
