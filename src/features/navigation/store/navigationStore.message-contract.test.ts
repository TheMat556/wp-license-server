import { afterEach, describe, expect, test } from 'vitest';
import { navigationStore } from './navigationStore';
import { EMBED_MESSAGE_VERSION } from '../../../types/embedMessages';

const TRUSTED = 'https://example.com';

function makeEvent(origin: string, data: unknown): MessageEvent {
  return new MessageEvent('message', { origin, data });
}

afterEach(() => {
  navigationStore.setState({
    currentRoute: '/',
    openInNewTabPatterns: [],
    trustedOrigin: null,
    overlayOpen: false,
  });
});

describe('navigationStore message contract', () => {
  test('accepts valid page-ready from trusted source with embed marker', () => {
    navigationStore.setState({ trustedOrigin: TRUSTED });

    const event = makeEvent(TRUSTED, {
      type: 'page-ready',
      version: EMBED_MESSAGE_VERSION,
      url: `${TRUSTED}/dashboard?wp_shell_embed=1`,
    });

    navigationStore.getState().handleEmbedMessage(event);

    expect(navigationStore.getState().currentRoute).toBe('/dashboard?wp_shell_embed=1');
  });

  test('rejects page-ready from untrusted source (wrong origin)', () => {
    navigationStore.setState({ trustedOrigin: TRUSTED, currentRoute: '/before' });

    const event = makeEvent('https://evil.com', {
      type: 'page-ready',
      version: EMBED_MESSAGE_VERSION,
      url: `${TRUSTED}/page?wp_shell_embed=1`,
    });

    navigationStore.getState().handleEmbedMessage(event);

    expect(navigationStore.getState().currentRoute).toBe('/before');
  });

  test('rejects page-ready without embed marker in URL', () => {
    navigationStore.setState({ trustedOrigin: TRUSTED, currentRoute: '/before' });

    const event = makeEvent(TRUSTED, {
      type: 'page-ready',
      version: EMBED_MESSAGE_VERSION,
      url: `${TRUSTED}/page`, // no ?wp_shell_embed
    });

    navigationStore.getState().handleEmbedMessage(event);

    expect(navigationStore.getState().currentRoute).toBe('/before');
  });

  test('rejects page-ready with cross-origin URL', () => {
    navigationStore.setState({ trustedOrigin: TRUSTED, currentRoute: '/before' });

    const event = makeEvent(TRUSTED, {
      type: 'page-ready',
      version: EMBED_MESSAGE_VERSION,
      url: 'https://evil.com/page?wp_shell_embed=1', // URL origin !== trustedOrigin
    });

    navigationStore.getState().handleEmbedMessage(event);

    expect(navigationStore.getState().currentRoute).toBe('/before');
  });

  test('session-expired from trusted source dispatches shell:auth-required CustomEvent', () => {
    navigationStore.setState({ trustedOrigin: TRUSTED });

    let fired = false;
    const handler = () => { fired = true; };
    window.addEventListener('shell:auth-required', handler, { once: true });

    const event = makeEvent(TRUSTED, {
      type: 'session-expired',
      version: EMBED_MESSAGE_VERSION,
    });

    navigationStore.getState().handleEmbedMessage(event);

    expect(fired).toBe(true);
    window.removeEventListener('shell:auth-required', handler);
  });

  test('overlay-state from trusted source updates overlayOpen state', () => {
    navigationStore.setState({ trustedOrigin: TRUSTED, overlayOpen: false });

    const event = makeEvent(TRUSTED, {
      type: 'overlay-state',
      version: EMBED_MESSAGE_VERSION,
      isOpen: true,
    });

    navigationStore.getState().handleEmbedMessage(event);

    expect(navigationStore.getState().overlayOpen).toBe(true);
  });

  test('unknown message type does not throw and leaves state unchanged', () => {
    navigationStore.setState({ trustedOrigin: TRUSTED, currentRoute: '/before' });

    const event = makeEvent(TRUSTED, {
      type: 'totally-unknown',
      version: EMBED_MESSAGE_VERSION,
    });

    expect(() => navigationStore.getState().handleEmbedMessage(event)).not.toThrow();
    expect(navigationStore.getState().currentRoute).toBe('/before');
  });

  test('ignores messages when trustedOrigin is not yet set', () => {
    navigationStore.setState({ trustedOrigin: null, currentRoute: '/before' });

    const event = makeEvent(TRUSTED, {
      type: 'overlay-state',
      version: EMBED_MESSAGE_VERSION,
      isOpen: true,
    });

    navigationStore.getState().handleEmbedMessage(event);

    expect(navigationStore.getState().overlayOpen).toBe(false);
  });
});
