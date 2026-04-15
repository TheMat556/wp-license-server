import { afterEach, describe, expect, test, vi } from 'vitest';
import { EMBED_MESSAGE_VERSION } from '../../types/embedMessages';
import { embedBridge } from './embedBridge';

// Mock window.parent before each test with a fresh vi.fn() for postMessage
function mockParent() {
  const postMessage = vi.fn();
  Object.defineProperty(window, 'parent', {
    value: { postMessage },
    writable: true,
    configurable: true,
  });
  return postMessage;
}

afterEach(() => {
  // Restore window.parent to self (jsdom default)
  Object.defineProperty(window, 'parent', {
    get: () => window,
    configurable: true,
  });
  document.body.innerHTML = '';
});

describe('embedBridge.sendPageReady', () => {
  test('sends page-ready with correct structure', () => {
    const postMessage = mockParent();

    embedBridge.sendPageReady('https://example.com/page');

    expect(postMessage).toHaveBeenCalledWith(
      expect.objectContaining({
        type: 'page-ready',
        url: 'https://example.com/page',
        version: EMBED_MESSAGE_VERSION,
      }),
      window.location.origin,
    );
  });
});

describe('embedBridge.sendTitleChange', () => {
  test('targetOrigin is never wildcard', () => {
    const postMessage = mockParent();

    embedBridge.sendTitleChange('My Page');

    const [, targetOrigin] = postMessage.mock.calls[0] as [unknown, string];
    expect(targetOrigin).not.toBe('*');
    expect(targetOrigin).toBe(window.location.origin);
  });

  test('sends title-change with correct structure', () => {
    const postMessage = mockParent();

    embedBridge.sendTitleChange('Dashboard');

    expect(postMessage).toHaveBeenCalledWith(
      expect.objectContaining({
        type: 'title-change',
        title: 'Dashboard',
        version: EMBED_MESSAGE_VERSION,
      }),
      window.location.origin,
    );
  });
});

describe('embedBridge.patchLinks', () => {
  test('patches in-page links to send breakout postMessage on click', () => {
    const postMessage = mockParent();
    document.body.innerHTML = '<a href="/wp-admin/other-page">Link</a>';

    embedBridge.patchLinks();

    const link = document.querySelector('a')!;
    link.click();

    expect(postMessage).toHaveBeenCalledWith(
      expect.objectContaining({ type: 'breakout' }),
      window.location.origin,
    );
  });

  test('breakout message includes the link href', () => {
    const postMessage = mockParent();
    document.body.innerHTML = '<a href="https://example.com/page">Link</a>';

    embedBridge.patchLinks();
    document.querySelector('a')!.click();

    const [payload] = postMessage.mock.calls[0] as [Record<string, unknown>];
    expect(payload.url).toContain('example.com/page');
    expect(payload.type).toBe('breakout');
  });
});

describe('embedBridge.sendBreakout', () => {
  test('sends breakout with correct targetOrigin', () => {
    const postMessage = mockParent();

    embedBridge.sendBreakout('https://example.com/other');

    const [, targetOrigin] = postMessage.mock.calls[0] as [unknown, string];
    expect(targetOrigin).not.toBe('*');
    expect(targetOrigin).toBe(window.location.origin);
  });
});
