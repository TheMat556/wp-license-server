import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, test, vi } from 'vitest';
import { MirroredAdminBarAction } from './MirroredAdminBarAction';

describe('MirroredAdminBarAction', () => {
  test('renders sanitized html content', () => {
    render(
      <MirroredAdminBarAction
        html='<span class="avatar">👤</span>'
        onClick={vi.fn()}
        aria-label="User menu"
      />,
    );
    expect(screen.getByRole('button', { name: 'User menu' })).toBeInTheDocument();
    expect(screen.getByRole('button').querySelector('span.avatar')).toBeInTheDocument();
  });

  test('calls onClick when button is clicked', async () => {
    const onClick = vi.fn();
    render(
      <MirroredAdminBarAction html="<span>Click me</span>" onClick={onClick} aria-label="Action" />,
    );
    await userEvent.click(screen.getByRole('button', { name: 'Action' }));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  test('strips dangerous attributes from html', () => {
    render(
      <MirroredAdminBarAction
        html='<span onclick="alert(1)" onmouseover="steal()">safe text</span>'
        onClick={vi.fn()}
        aria-label="Safe action"
      />,
    );
    const span = screen.getByRole('button').querySelector('span');
    expect(span?.getAttribute('onclick')).toBeNull();
    expect(span?.getAttribute('onmouseover')).toBeNull();
    expect(span?.textContent).toBe('safe text');
  });

  test('strips script tags', () => {
    render(
      <MirroredAdminBarAction
        html='<span>ok</span><script>alert("xss")</script>'
        onClick={vi.fn()}
        aria-label="XSS test"
      />,
    );
    const button = screen.getByRole('button', { name: 'XSS test' });
    expect(button.innerHTML).not.toContain('<script');
  });
});
