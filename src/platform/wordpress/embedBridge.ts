import { EMBED_MESSAGE_VERSION } from '../../types/embedMessages';

function getTargetOrigin(): string {
  return window.location.origin;
}

function send(message: Record<string, unknown>): void {
  window.parent.postMessage(message, getTargetOrigin());
}

export const embedBridge = {
  sendPageReady(url: string): void {
    send({ type: 'page-ready', version: EMBED_MESSAGE_VERSION, url });
  },

  sendTitleChange(title: string): void {
    send({ type: 'title-change', version: EMBED_MESSAGE_VERSION, title });
  },

  sendBreakout(url: string): void {
    send({ type: 'breakout', version: EMBED_MESSAGE_VERSION, url });
  },

  patchLinks(): void {
    document.querySelectorAll<HTMLAnchorElement>('a[href]').forEach(link => {
      link.addEventListener('click', e => {
        e.preventDefault();
        send({ type: 'breakout', version: EMBED_MESSAGE_VERSION, url: link.href });
      });
    });
  },
};
