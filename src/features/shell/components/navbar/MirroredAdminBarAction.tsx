import DOMPurify from 'dompurify';
import { useMemo } from 'react';

interface MirroredAdminBarActionProps {
  html: string;
  onClick: () => void;
  'aria-label': string;
}

function sanitizeAdminBarHtml(html: string): string {
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: ['span', 'img', 'svg', 'path', 'abbr'],
    ALLOWED_ATTR: ['class', 'src', 'alt', 'width', 'height', 'viewBox', 'd', 'fill', 'aria-hidden'],
  });
}

export function MirroredAdminBarAction({
  html,
  onClick,
  'aria-label': ariaLabel,
}: MirroredAdminBarActionProps) {
  const sanitized = useMemo(() => sanitizeAdminBarHtml(html), [html]);

  return (
    <button
      type="button"
      className="navbar__admin-bar-action"
      onClick={onClick}
      aria-label={ariaLabel}
    >
      <span dangerouslySetInnerHTML={{ __html: sanitized }} />
    </button>
  );
}
