export interface ChatClient {
  sendMessage(threadId: string, text: string): Promise<void>;
}

export class NativeChatClient implements ChatClient {
  async sendMessage(threadId: string, text: string): Promise<void> {
    const response = await fetch(`/wp-json/wplicense/v1/chat/${threadId}/messages`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ text }),
    });

    if (!response.ok) {
      throw new Error(`Send failed: ${response.status} ${response.statusText}`);
    }
  }
}
