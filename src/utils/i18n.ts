/**
 * i18n helper for WP License Server.
 *
 * Uses WordPress's built-in wp.i18n.__() when available (wp-i18n script enqueued),
 * falls back to returning the original string.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/
 */

declare global {
  interface Window {
    wp?: {
      i18n?: {
        __: (text: string, domain?: string) => string;
        _x: (text: string, context: string, domain?: string) => string;
        _n: (single: string, plural: string, number: number, domain?: string) => string;
        sprintf: (format: string, ...args: (string | number)[]) => string;
      };
    };
  }
}

/**
 * Retrieve the translation of a text string.
 *
 * @param text    Text to translate.
 * @param domain  Text domain. Default 'wp-license-server'.
 */
export function __(text: string, domain = 'wp-license-server'): string {
  if (typeof window !== 'undefined' && window.wp?.i18n?.__) {
    return window.wp.i18n.__(text, domain);
  }
  return text;
}

/**
 * Retrieve the translation of a text string with context.
 *
 * @param text    Text to translate.
 * @param context Context information for translators.
 * @param domain  Text domain. Default 'wp-license-server'.
 */
export function _x(text: string, context: string, domain = 'wp-license-server'): string {
  if (typeof window !== 'undefined' && window.wp?.i18n?._x) {
    return window.wp.i18n._x(text, context, domain);
  }
  return text;
}

/**
 * Retrieve the translation of a plural string.
 *
 * @param single The text to be used if the number is singular.
 * @param plural The text to be used if the number is plural.
 * @param number The number to compare against.
 * @param domain Text domain. Default 'wp-license-server'.
 */
export function _n(single: string, plural: string, number: number, domain = 'wp-license-server'): string {
  if (typeof window !== 'undefined' && window.wp?.i18n?._n) {
    return window.wp.i18n._n(single, plural, number, domain);
  }
  return number === 1 ? single : plural;
}

/**
 * Replace placeholders in a translated string.
 *
 * @param format  The translated string with %s, %d, %1$s, etc. placeholders.
 * @param args    Values to substitute into the placeholders.
 */
export function sprintf(format: string, ...args: (string | number)[]): string {
  if (typeof window !== 'undefined' && window.wp?.i18n?.sprintf) {
    return window.wp.i18n.sprintf(format, ...args);
  }
  // Minimal fallback: replace %s and %d sequentially, %1$s and %2$s by index
  let result = format;
  args.forEach((value, index) => {
    result = result.replace(`%${index + 1}$s`, String(value));
    result = result.replace(`%${index + 1}$d`, String(value));
  });
  // Also handle positional %s/%d (no explicit index)
  let posIndex = 0;
  result = result.replace(/%([sd])/g, () => {
    const val = args[posIndex++];
    return val !== undefined ? String(val) : '';
  });
  return result;
}
