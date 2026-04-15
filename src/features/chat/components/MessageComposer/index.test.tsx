import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, test, vi } from 'vitest';
import { MessageComposer } from './index';

describe('MessageComposer', () => {
  test('clears draft after successful send', async () => {
    const onSend = vi.fn().mockResolvedValue(undefined);
    render(<MessageComposer onSend={onSend} />);
    await userEvent.type(screen.getByRole('textbox'), 'hello');
    await userEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByRole('textbox')).toHaveValue(''));
  });

  test('preserves draft after failed send', async () => {
    const onSend = vi.fn().mockRejectedValue(new Error('network error'));
    render(<MessageComposer onSend={onSend} />);
    await userEvent.type(screen.getByRole('textbox'), 'hello world');
    await userEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByRole('textbox')).toHaveValue('hello world'));
  });

  test('shows error message after failed send', async () => {
    const onSend = vi.fn().mockRejectedValue(new Error('network error'));
    render(<MessageComposer onSend={onSend} />);
    await userEvent.type(screen.getByRole('textbox'), 'hello');
    await userEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(/failed to send/i));
  });

  test('disables send button while sending', async () => {
    let resolve!: () => void;
    const onSend = vi.fn(() => new Promise<void>(r => (resolve = r)));
    render(<MessageComposer onSend={onSend} />);
    await userEvent.type(screen.getByRole('textbox'), 'hello');
    await userEvent.click(screen.getByRole('button', { name: /send/i }));
    expect(screen.getByRole('button', { name: /send/i })).toBeDisabled();
    resolve();
  });

  test('clears error when user starts typing again', async () => {
    const onSend = vi.fn().mockRejectedValue(new Error('network error'));
    render(<MessageComposer onSend={onSend} />);
    await userEvent.type(screen.getByRole('textbox'), 'hello');
    await userEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    await userEvent.type(screen.getByRole('textbox'), 'x');
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  test('does not send empty or whitespace-only text', async () => {
    const onSend = vi.fn().mockResolvedValue(undefined);
    render(<MessageComposer onSend={onSend} />);
    await userEvent.type(screen.getByRole('textbox'), '   ');
    await userEvent.click(screen.getByRole('button', { name: /send/i }));
    expect(onSend).not.toHaveBeenCalled();
  });

  test('send button is disabled when input is empty', () => {
    const onSend = vi.fn().mockResolvedValue(undefined);
    render(<MessageComposer onSend={onSend} />);
    expect(screen.getByRole('button', { name: /send/i })).toBeDisabled();
  });
});
