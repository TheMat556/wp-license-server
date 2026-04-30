import type { CSSProperties } from 'react';
import { Typography } from 'antd';

const { Title, Text } = Typography;

interface SurfacePanelProps {
  title: React.ReactNode;
  description?: React.ReactNode;
  icon?: React.ReactNode;
  extra?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
  bodyClassName?: string;
  style?: CSSProperties;
}

export function SurfacePanel({
  title,
  description,
  icon,
  extra,
  children,
  className,
  bodyClassName,
  style,
}: SurfacePanelProps) {
  return (
    <section
      className={['wp-react-ui-surface-panel', className].filter(Boolean).join(' ')}
      style={style}
    >
      <div className="wp-react-ui-surface-panel__header">
        <div className="wp-react-ui-surface-panel__lead">
          {icon ? <span className="wp-react-ui-surface-panel__icon">{icon}</span> : null}
          <div className="wp-react-ui-surface-panel__copy">
            <div className="wp-react-ui-surface-panel__title">
              <Title level={5} style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>
                {title}
              </Title>
            </div>
            {description ? (
              <div className="wp-react-ui-surface-panel__description">
                <Text type="secondary" style={{ fontSize: 13 }}>
                  {description}
                </Text>
              </div>
            ) : null}
          </div>
        </div>
        {extra ? <div className="wp-react-ui-surface-panel__extra">{extra}</div> : null}
      </div>
      <div
        className={['wp-react-ui-surface-panel__body', bodyClassName].filter(Boolean).join(' ')}
      >
        {children}
      </div>
    </section>
  );
}
