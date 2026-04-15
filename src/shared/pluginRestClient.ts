interface RequestOptions {
  signal?: AbortSignal;
}

class PluginRestClient {
  private readonly baseUrl: string;
  private readonly nonce: string;

  constructor(baseUrl: string, nonce: string) {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.nonce = nonce;
  }

  private buildUrl(path: string): string {
    return `${this.baseUrl}${path}`;
  }

  private buildHeaders(): Record<string, string> {
    return {
      'Content-Type': 'application/json',
      'X-WP-Nonce': this.nonce,
    };
  }

  async get<T>(path: string, options?: RequestOptions): Promise<T> {
    const response = await fetch(this.buildUrl(path), {
      method: 'GET',
      headers: this.buildHeaders(),
      signal: options?.signal,
    });

    if (!response.ok) {
      throw new Error(`GET ${path} failed: ${response.status} ${response.statusText}`);
    }

    return response.json() as Promise<T>;
  }

  async post<T>(path: string, body: unknown, options?: RequestOptions): Promise<T> {
    const response = await fetch(this.buildUrl(path), {
      method: 'POST',
      headers: this.buildHeaders(),
      body: JSON.stringify(body),
      signal: options?.signal,
    });

    if (!response.ok) {
      throw new Error(`POST ${path} failed: ${response.status} ${response.statusText}`);
    }

    return response.json() as Promise<T>;
  }
}

declare const wpApiSettings: { root: string; nonce: string };

export const pluginRestClient = new PluginRestClient(
  typeof wpApiSettings !== 'undefined' ? wpApiSettings.root : '/wp-json/',
  typeof wpApiSettings !== 'undefined' ? wpApiSettings.nonce : '',
);
