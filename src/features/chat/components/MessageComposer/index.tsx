import { useState } from 'react';

interface MessageComposerProps {
  onSend: (text: string) => Promise<void>;
  disabled?: boolean;
  placeholder?: string;
}

export function MessageComposer({
  onSend,
  disabled = false,
  placeholder = 'Type a message…',
}: MessageComposerProps) {
  const [value, setValue] = useState('');
  const [isSending, setIsSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);

  const handleSend = async () => {
    const text = value.trim();
    if (!text || isSending) return;

    setIsSending(true);
    setSendError(null);

    try {
      await onSend(text);
      setValue('');
    } catch {
      setSendError('Failed to send. Please try again.');
    } finally {
      setIsSending(false);
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  };

  const isDisabled = disabled || isSending;

  return (
    <div className="message-composer">
      <textarea
        className="message-composer__input"
        value={value}
        onChange={e => {
          setValue(e.target.value);
          if (sendError) setSendError(null);
        }}
        onKeyDown={handleKeyDown}
        placeholder={placeholder}
        disabled={isDisabled}
        rows={3}
      />
      {sendError && (
        <p role="alert" className="message-composer__error">
          {sendError}
        </p>
      )}
      <button
        className="message-composer__send"
        onClick={handleSend}
        disabled={isDisabled || !value.trim()}
        aria-label={isSending ? 'Sending…' : 'Send'}
      >
        {isSending ? 'Sending…' : 'Send'}
      </button>
    </div>
  );
}
