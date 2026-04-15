import { useCallback, useEffect, useState } from 'react';
import { useStore } from 'zustand';
import {
  fetchChatBootstrap,
  pollChatUpdates,
  type ChatBootstrapResponse,
  type ChatMessage,
} from '../../services/chatConversationApi';
import { useChatPolling } from '../../hooks/useChatPolling';
import { NativeChatClient } from '../../api/NativeChatClient';
import { ConversationPanel } from '../ConversationPanel';
import { licenseStore } from '../../../license/store/licenseStore';

interface ChatPageProps {
  isConnected?: boolean;
  pollIntervalSeconds?: number;
}

const chatApi = new NativeChatClient();
const POLL_INTERVAL_SECONDS = 5;

/** License gate — renders appropriate UI for non-active statuses. */
export function ChatPage(props: ChatPageProps) {
  const license = useStore(licenseStore, s => s.license);
  const status = license?.status ?? 'unknown';

  if (status === 'disabled') {
    return (
      <div className="chat-page chat-page--gated">
        <p role="status">License required to use chat.</p>
      </div>
    );
  }

  if (status === 'expired') {
    return (
      <div className="chat-page chat-page--gated">
        <p role="status">Your license has expired. Please renew to continue using chat.</p>
      </div>
    );
  }

  return (
    <ChatPageContent
      {...props}
      graceDaysRemaining={status === 'grace' ? (license?.graceDaysRemaining ?? 0) : undefined}
    />
  );
}

interface ChatPageContentProps extends ChatPageProps {
  graceDaysRemaining?: number;
}

/** Inner component that owns bootstrap/polling state. */
function ChatPageContent({
  isConnected = true,
  pollIntervalSeconds = POLL_INTERVAL_SECONDS,
  graceDaysRemaining,
}: ChatPageContentProps) {
  const [bootstrap, setBootstrap] = useState<ChatBootstrapResponse | null>(null);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [lastMessageId, setLastMessageId] = useState(0);
  const [bootstrapError, setBootstrapError] = useState<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();

    fetchChatBootstrap(controller.signal)
      .then(data => {
        if (!controller.signal.aborted) {
          setBootstrap(data);
          setMessages(data.messages);
          setLastMessageId(data.lastMessageId);
        }
      })
      .catch(err => {
        if (err?.name === 'AbortError') return;
        setBootstrapError('Failed to load chat. Please refresh.');
      });

    return () => controller.abort();
  }, []);

  const onPoll = useCallback(
    async (signal: AbortSignal) => {
      if (!bootstrap) return;
      const data = await pollChatUpdates(bootstrap.threadId, lastMessageId, signal);
      if (data.messages.length > 0) {
        setMessages(prev => [...prev, ...data.messages]);
        setLastMessageId(data.lastMessageId);
      }
    },
    [bootstrap, lastMessageId],
  );

  useChatPolling(pollIntervalSeconds, onPoll, isConnected && bootstrap !== null);

  return (
    <div className="chat-page">
      {/* Grace banner is shown immediately — before bootstrap resolves */}
      {graceDaysRemaining !== undefined && (
        <p role="alert" className="chat-page__grace-banner">
          Your license expires in {graceDaysRemaining} days. Please renew soon.
        </p>
      )}

      {bootstrapError && <p className="chat-page__error">{bootstrapError}</p>}

      {!bootstrap && !bootstrapError && <div className="chat-page__loading">Loading…</div>}

      {bootstrap && (
        <ConversationPanel
          threadId={String(bootstrap.threadId)}
          chatApi={chatApi}
          isConnected={isConnected}
          initialMessages={messages.map(m => ({ ...m, id: String(m.id) }))}
        />
      )}
    </div>
  );
}
