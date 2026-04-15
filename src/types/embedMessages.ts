export const EMBED_MESSAGE_VERSION = 1 as const;

interface PageReadyMessage {
  type: 'page-ready';
  version: typeof EMBED_MESSAGE_VERSION;
  url: string;
}

interface TitleChangeMessage {
  type: 'title-change';
  version: typeof EMBED_MESSAGE_VERSION;
  title: string;
}

interface BreakoutMessage {
  type: 'breakout';
  version: typeof EMBED_MESSAGE_VERSION;
  url: string;
}

interface SessionExpiredMessage {
  type: 'session-expired';
  version: typeof EMBED_MESSAGE_VERSION;
}

interface OverlayStateMessage {
  type: 'overlay-state';
  version: typeof EMBED_MESSAGE_VERSION;
  isOpen: boolean;
}

export type EmbedMessage =
  | PageReadyMessage
  | TitleChangeMessage
  | BreakoutMessage
  | SessionExpiredMessage
  | OverlayStateMessage;

export function isEmbedMessage(value: unknown): value is EmbedMessage {
  if (!value || typeof value !== 'object') return false;
  const msg = value as Record<string, unknown>;
  if (msg['version'] !== EMBED_MESSAGE_VERSION) return false;

  switch (msg['type']) {
    case 'page-ready':
      return typeof msg['url'] === 'string';
    case 'title-change':
      return typeof msg['title'] === 'string';
    case 'breakout':
      return typeof msg['url'] === 'string';
    case 'session-expired':
      return true;
    case 'overlay-state':
      return typeof msg['isOpen'] === 'boolean';
    default:
      return false;
  }
}
