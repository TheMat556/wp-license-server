import { pluginRestClient } from '../../../shared/pluginRestClient';

export interface ChatBootstrapResponse {
  threadId: number;
  messages: ChatMessage[];
  lastMessageId: number;
}

export interface ChatMessage {
  id: number;
  text: string;
  sentAt: string;
}

export interface ChatPollResponse {
  messages: ChatMessage[];
  lastMessageId: number;
}

export async function fetchChatBootstrap(signal?: AbortSignal): Promise<ChatBootstrapResponse> {
  return pluginRestClient.get('/license-server/v1/chat/bootstrap', { signal });
}

export async function pollChatUpdates(
  threadId: number,
  afterMessageId: number,
  signal?: AbortSignal,
): Promise<ChatPollResponse> {
  return pluginRestClient.get(
    `/license-server/v1/chat/poll?thread_id=${threadId}&after=${afterMessageId}`,
    { signal },
  );
}
