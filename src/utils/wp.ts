/** Read the WP REST nonce injected by wp_localize_script / wp_add_inline_script. */
export function getWpNonce(): string {
  return (window as unknown as Record<string, Record<string, string>>)
    ?.wpApiSettings?.nonce ?? '';
}

/** Build an absolute WP REST API URL for the given path. */
export function getRestUrl(path: string): string {
  const root: string =
    (window as unknown as Record<string, Record<string, string>>)
      ?.wpApiSettings?.root ?? '/wp-json/';
  return root.replace(/\/$/, '') + '/' + path.replace(/^\//, '');
}

/** Build a WP admin page URL. */
export function getAdminUrl(page: string, params: Record<string, string> = {}): string {
  const base = (window as unknown as Record<string, Record<string, string>>)
    ?.wpApiSettings?.adminUrl ?? '/wp-admin/admin.php';
  const query = new URLSearchParams({ page, ...params });
  return `${base}?${query.toString()}`;
}

/** Return true if the current pathname starts with the given prefix. */
export function isCurrentPath(prefix: string): boolean {
  return window.location.pathname.startsWith(prefix);
}
