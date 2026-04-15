import type { AdminConfig } from './types';

export const config: AdminConfig = (
  window as unknown as { WpLicenseServerAdmin: AdminConfig }
).WpLicenseServerAdmin;

export async function apiFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  const res = await fetch(`${config.restBase}${path}`, {
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': config.nonce,
      ...((options.headers as Record<string, string>) ?? {}),
    },
    ...options,
  });

  const body = (await res.json()) as { message?: string } & T;

  if (!res.ok) {
    const msg = (body as { message?: string }).message ?? `Request failed (${res.status})`;
    throw new Error(msg);
  }

  return body;
}
