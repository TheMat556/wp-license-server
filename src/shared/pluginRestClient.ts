interface RequestOptions {
  signal?: AbortSignal;
}

class PluginRestError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly body: string,
  ) {
    super(message);
    this.name = 'PluginRestError';
  }
}

class PluginRestClient {
  private readonly baseUrl: string;
  private readonly nonce: string;

  constructor(baseUrl: string, nonce: string) {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.nonce = nonce;
  }

  private checkConfig(): void {
    if (!this.nonce) {
      console.warn(
        '[WP License Server] wpApiSettings not found. REST API calls will fail without authentication. Ensure the WordPress REST API is properly initialized.',
      );
    }
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

  private async handleResponse<T>(response: Response, method: string, path: string): Promise<T> {
    if (!response.ok) {
      const body = await response.text().catch(() => '');
      throw new PluginRestError(
        `${method} ${path} failed: ${response.status} ${response.statusText}`,
        response.status,
        body,
      );
    }

    try {
      return (await response.json()) as T;
    } catch {
      const text = await response.text().catch(() => '');
      throw new PluginRestError(
        `${method} ${path}: response is not valid JSON`,
        response.status,
        text,
      );
    }
  }

  async get<T>(path: string, options?: RequestOptions): Promise<T> {
    this.checkConfig();
    const response = await fetch(this.buildUrl(path), {
      method: 'GET',
      headers: this.buildHeaders(),
      signal: options?.signal,
    });

    return this.handleResponse<T>(response, 'GET', path);
  }

  async post<T>(path: string, body: unknown, options?: RequestOptions): Promise<T> {
    this.checkConfig();
    const response = await fetch(this.buildUrl(path), {
      method: 'POST',
      headers: this.buildHeaders(),
      body: JSON.stringify(body),
      signal: options?.signal,
    });

    return this.handleResponse<T>(response, 'POST', path);
  }
}

declare const wpApiSettings: { root: string; nonce: string };

const apiRoot =
  typeof wpApiSettings !== 'undefined' ? wpApiSettings.root : '/wp-json/';
const apiNonce =
  typeof wpApiSettings !== 'undefined' ? wpApiSettings.nonce : '';

export const pluginRestClient = new PluginRestClient(apiRoot, apiNonce);
