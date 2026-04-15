import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const messageSchema = z.object({
  text: z.string().min(1, 'Message is required'),
});

type MessageFormValues = z.infer<typeof messageSchema>;

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
  const [isSending, setIsSending] = useState(false);
  const {
    register,
    handleSubmit,
    reset,
    setError,
    clearErrors,
    watch,
    formState: { errors },
  } = useForm<MessageFormValues>({
    resolver: zodResolver(messageSchema),
    defaultValues: { text: '' },
  });

  const textValue = watch('text');

  const onSubmit = async (values: MessageFormValues) => {
    const text = values.text.trim();
    if (!text) return;
    setIsSending(true);
    try {
      await onSend(text);
      reset();
    } catch {
      setError('text', { type: 'manual', message: 'Failed to send. Please try again.' });
    } finally {
      setIsSending(false);
    }
  };

  const { onChange: rhfOnChange, ...restTextProps } = register('text');

  const isDisabled = disabled || isSending;
  const sendError = errors.text?.message;

  return (
    <div className="message-composer">
      <textarea
        className="message-composer__input"
        {...restTextProps}
        onChange={e => {
          void rhfOnChange(e);
          if (errors.text) clearErrors('text');
        }}
        onKeyDown={e => {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            void handleSubmit(onSubmit)();
          }
        }}
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
        onClick={() => void handleSubmit(onSubmit)()}
        disabled={isDisabled || !(textValue ?? '').trim()}
        aria-label={isSending ? 'Sending…' : 'Send'}
      >
        {isSending ? 'Sending…' : 'Send'}
      </button>
    </div>
  );
}
