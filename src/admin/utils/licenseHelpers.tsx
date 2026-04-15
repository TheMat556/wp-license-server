import { App } from 'antd';
import {
  CheckCircleOutlined,
  CloseCircleOutlined,
  PauseCircleOutlined,
  SyncOutlined,
} from '@ant-design/icons';

export function statusColor(status: string): string {
  switch (status) {
    case 'active':
      return 'success';
    case 'expired':
      return 'warning';
    case 'suspended':
      return 'processing';
    case 'cancelled':
      return 'error';
    default:
      return 'default';
  }
}

export function statusIcon(status: string) {
  switch (status) {
    case 'active':
      return <CheckCircleOutlined />;
    case 'expired':
      return <CloseCircleOutlined />;
    case 'suspended':
      return <PauseCircleOutlined />;
    case 'cancelled':
      return <CloseCircleOutlined />;
    default:
      return <SyncOutlined />;
  }
}

export function formatDate(iso: string): string {
  return iso ? new Date(iso).toLocaleDateString() : '—';
}

export function showSuccessNotification(
  notification: ReturnType<typeof App.useApp>['notification'],
  config: { message: string; description?: string; duration?: number },
) {
  notification.success({
    placement: 'bottomRight',
    duration: config.duration ?? 4.5,
    style: { zIndex: 100300 },
    ...config,
  });
}

export function showErrorNotification(
  notification: ReturnType<typeof App.useApp>['notification'],
  config: { message: string; description?: string; duration?: number },
) {
  notification.error({
    placement: 'bottomRight',
    duration: config.duration ?? 5,
    style: { zIndex: 100300 },
    ...config,
  });
}
