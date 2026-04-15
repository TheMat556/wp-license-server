import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { createElement } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ChatPage } from './index';
import { licenseStore } from '../../../license/store/licenseStore';
import * as chatConvApi from '../../services/chatConversationApi';
import type { License } from '../../../license/types';

vi.mock('../../services/chatConversationApi', () => ({
  fetchChatBootstrap: vi.fn().mockResolvedValue({
    threadId: 1,
    messages: [],
    lastMessageId: 0,
  }),
  pollChatUpdates: vi.fn().mockResolvedValue({ messages: [], lastMessageId: 0 }),
}));

vi.mock('../../api/NativeChatClient', () => ({
  NativeChatClient: class {
    sendMessage = vi.fn();
  },
}));

const baseLicense: License = {
  id: 1,
  key: 'TEST-KEY-0001',
  status: 'active',
  features: ['chat'],
  expiresAt: null,
  domain: 'example.com',
  graceDaysRemaining: 0,
};

function setLicense(overrides: Partial<License>) {
  licenseStore.setState({ license: { ...baseLicense, ...overrides } });
}

function makeWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return createElement(QueryClientProvider, { client: queryClient }, children);
  };
}

afterEach(() => {
  cleanup(); // unmount all rendered components before touching shared store
  licenseStore.setState({ license: null });
  vi.mocked(chatConvApi.fetchChatBootstrap).mockClear();
  vi.mocked(chatConvApi.pollChatUpdates).mockClear();
});

// ── License gating ────────────────────────────────────────────────────────

describe('ChatPage license gating', () => {
  test('shows disabled state when license is DISABLED', () => {
    setLicense({ status: 'disabled' });
    render(<ChatPage />, { wrapper: makeWrapper() });

    expect(screen.getByRole('status')).toHaveTextContent(/license required/i);
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(vi.mocked(chatConvApi.fetchChatBootstrap)).not.toHaveBeenCalled();
  });

  test('shows expired state when license is EXPIRED', () => {
    setLicense({ status: 'expired', graceDaysRemaining: 0 });
    render(<ChatPage />, { wrapper: makeWrapper() });

    expect(screen.getByRole('status')).toHaveTextContent(/expired/i);
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  });

  test('shows grace warning banner when license is in GRACE period', async () => {
    setLicense({ status: 'grace', graceDaysRemaining: 3 });
    render(<ChatPage />, { wrapper: makeWrapper() });

    // Banner visible immediately — before bootstrap resolves
    expect(screen.getByRole('alert')).toHaveTextContent(/3 days/i);

    // Chat is still usable — textbox appears after bootstrap settles
    await waitFor(() => {
      expect(screen.getByRole('textbox')).toBeInTheDocument();
    });
  });

  test('active license renders chat without any gate', async () => {
    setLicense({ status: 'active' });
    render(<ChatPage />, { wrapper: makeWrapper() });

    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getByRole('textbox')).toBeInTheDocument();
    });
  });
});

// ── Polling cleanup ───────────────────────────────────────────────────────

describe('ChatPage polling cleanup', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  test('stops polling on unmount — no requests fire after cleanup', async () => {
    setLicense({ status: 'active' });

    const { unmount } = render(<ChatPage pollIntervalSeconds={1} />, {
      wrapper: makeWrapper(),
    });

    // Flush the bootstrap promise (microtask) so polling becomes enabled
    await act(async () => {
      await Promise.resolve();
    });

    const pollSpy = vi.mocked(chatConvApi.pollChatUpdates);
    pollSpy.mockClear(); // clear any calls that fired during bootstrap phase

    unmount();

    // Advance timers well past the poll interval — nothing should fire
    await vi.advanceTimersByTimeAsync(30_000);

    expect(pollSpy).not.toHaveBeenCalled();
  });
});
