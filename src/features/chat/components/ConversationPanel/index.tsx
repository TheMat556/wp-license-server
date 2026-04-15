import { useCallback, useState } from 'react';
import type { ChatClient } from '../../api/NativeChatClient';
import { MessageComposer } from '../MessageComposer';

interface Message {
  id: string;
  text: string;
  sentAt: string;
}

interface ConversationPanelProps {
  threadId: string;
  chatApi: ChatClient;
  isConnected: boolean;
  initialMessages?: Message[];
}

export function ConversationPanel({
  threadId,
  chatApi,
  isConnected,
  initialMessages = [],
}: ConversationPanelProps) {
  const [messages, setMessages] = useState<Message[]>(initialMessages);

  const handleSend = useCallback(
    async (text: string): Promise<void> => {
      await chatApi.sendMessage(threadId, text);
      setMessages(prev => [
        ...prev,
        { id: crypto.randomUUID(), text, sentAt: new Date().toISOString() },
      ]);
    },
    [chatApi, threadId],
  );

  return (
    <div className="conversation-panel">
      <ul className="conversation-panel__messages">
        {messages.map(msg => (
          <li key={msg.id} className="conversation-panel__message">
            {msg.text}
          </li>
        ))}
      </ul>
      <MessageComposer onSend={handleSend} disabled={!isConnected} />
    </div>
  );
}
