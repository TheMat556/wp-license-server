import { describe, expect, test } from 'vitest';
import { isEmbedMessage, EMBED_MESSAGE_VERSION } from './embedMessages';

describe('isEmbedMessage', () => {
  // ── Accept: valid messages ────────────────────────────────────────────────

  test.each([
    ['page-ready',      { type: 'page-ready',      version: EMBED_MESSAGE_VERSION, url: 'https://example.com/?wp_shell_embed=1' }],
    ['title-change',    { type: 'title-change',    version: EMBED_MESSAGE_VERSION, title: 'My Page' }],
    ['breakout',        { type: 'breakout',        version: EMBED_MESSAGE_VERSION, url: 'https://example.com/wp-admin/page' }],
    ['session-expired', { type: 'session-expired', version: EMBED_MESSAGE_VERSION }],
    ['overlay-state',   { type: 'overlay-state',   version: EMBED_MESSAGE_VERSION, isOpen: true }],
  ])('accepts valid %s message', (_, msg) => {
    expect(isEmbedMessage(msg)).toBe(true);
  });

  // ── Reject: invalid primitive inputs ─────────────────────────────────────

  test('rejects null',         () => expect(isEmbedMessage(null)).toBe(false));
  test('rejects undefined',    () => expect(isEmbedMessage(undefined)).toBe(false));
  test('rejects empty object', () => expect(isEmbedMessage({})).toBe(false));
  test('rejects string',       () => expect(isEmbedMessage('hello')).toBe(false));
  test('rejects number',       () => expect(isEmbedMessage(42)).toBe(false));
  test('rejects array',        () => expect(isEmbedMessage([])).toBe(false));

  test('rejects unknown type', () => {
    expect(isEmbedMessage({ type: 'unknown', version: EMBED_MESSAGE_VERSION })).toBe(false);
  });

  test('rejects wrong version', () => {
    expect(isEmbedMessage({ type: 'page-ready', version: 999, url: 'https://example.com/' })).toBe(false);
  });

  test('rejects missing version field', () => {
    expect(isEmbedMessage({ type: 'session-expired' })).toBe(false);
  });

  // ── Reject: missing required fields per type ──────────────────────────────

  test('rejects page-ready without url', () => {
    expect(isEmbedMessage({ type: 'page-ready', version: EMBED_MESSAGE_VERSION })).toBe(false);
  });

  test('rejects title-change without title', () => {
    expect(isEmbedMessage({ type: 'title-change', version: EMBED_MESSAGE_VERSION })).toBe(false);
  });

  test('rejects overlay-state without isOpen', () => {
    expect(isEmbedMessage({ type: 'overlay-state', version: EMBED_MESSAGE_VERSION })).toBe(false);
  });

  test('rejects overlay-state with non-boolean isOpen', () => {
    expect(isEmbedMessage({ type: 'overlay-state', version: EMBED_MESSAGE_VERSION, isOpen: 'yes' })).toBe(false);
  });

  test('rejects breakout without url', () => {
    expect(isEmbedMessage({ type: 'breakout', version: EMBED_MESSAGE_VERSION })).toBe(false);
  });
});
