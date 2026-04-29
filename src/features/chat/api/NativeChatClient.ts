import { pluginRestClient } from '../../../shared/pluginRestClient';

export interface ChatClient {
  sendMessage(threadId: string, text: string): Promise<void>;
}

export class NativeChatClient implements ChatClient {
  async sendMessage(threadId: string, text: string): Promise<void> {
    await pluginRestClient.post('/license-server/v1/chat/send', {
      thread_id: parseInt(threadId, 10),
      message: text,
    });
  }
}