import { useEffect, useRef, useState, type FormEvent } from 'react';
import {
  MdAttachFile,
  MdDeleteOutline,
  MdFormatSize,
  MdImage,
  MdInsertDriveFile,
  MdInsertEmoticon,
  MdInsertLink,
  MdLockClock,
  MdMoreVert,
  MdOpenInNew,
  MdReply,
  MdArrowDropDown,
  MdEdit,
} from 'react-icons/md';
import { api, type MailDetail } from '../api';
import type { ComposeState } from './ComposePopup';

type Props = {
  message: MailDetail;
  userLabel: string;
  folder?: string;
  initialBody?: string;
  onClose: () => void;
  onPopOut: (state: ComposeState) => void;
  onSent: (message: string) => void;
};

export function InlineReply({
  message,
  userLabel,
  folder,
  initialBody = '',
  onClose,
  onPopOut,
  onSent,
}: Props) {
  const outgoing = folder === 'sent' || folder === 'drafts';
  const to = outgoing
    ? message.to_display || ''
    : message.from_name
      ? `${message.from_name} <${message.from_email}>`
      : message.from_email;
  const subject = message.subject.toLowerCase().startsWith('re:')
    ? message.subject
    : `Re: ${message.subject}`;
  const who = outgoing ? message.from_display || 'me' : message.from_display;
  const quoted = `\n\nOn ${message.date_full}, ${who} wrote:\n${(message.body_text || '')
    .split('\n')
    .map((l) => `> ${l}`)
    .join('\n')}`;

  const [body, setBody] = useState(initialBody);
  const [files, setFiles] = useState<FileList | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    setBody(initialBody);
    setFiles(null);
    setError('');
    const t = window.setTimeout(() => {
      const el = textareaRef.current;
      if (!el) return;
      el.focus();
      const len = el.value.length;
      el.setSelectionRange(len, len);
    }, 40);
    return () => window.clearTimeout(t);
  }, [message.id, initialBody]);

  async function send(e?: FormEvent) {
    e?.preventDefault();
    setBusy(true);
    setError('');
    try {
      const form = new FormData();
      form.set('to', to);
      form.set('subject', subject);
      form.set('body', body + quoted);
      if (message.message_id_header) form.set('in_reply_to', message.message_id_header);
      if (files) {
        Array.from(files).forEach((f) => form.append('attachments[]', f));
      }
      const result = await api.send(form);
      onSent(result.message || 'Sent');
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to send');
    } finally {
      setBusy(false);
    }
  }

  function popOut() {
    onPopOut({
      open: true,
      title: 'Reply',
      to,
      subject,
      body: body + quoted,
      inReplyTo: message.message_id_header,
    });
    onClose();
  }

  const initial = (userLabel || 'U').slice(0, 1).toUpperCase();

  return (
    <div className="inline-reply">
      <div className="inline-reply-avatar" aria-hidden>
        {initial}
      </div>
      <form className="inline-reply-card" onSubmit={(e) => void send(e)}>
        <div className="inline-reply-head">
          <div className="inline-reply-to">
            <button type="button" className="inline-reply-mode" title="Reply" aria-label="Reply mode">
              <MdReply size={18} />
              <MdArrowDropDown size={18} />
            </button>
            <span className="inline-reply-email">{message.from_email}</span>
          </div>
          <button
            type="button"
            className="inline-reply-pop"
            title="Pop out"
            aria-label="Pop out"
            onClick={popOut}
          >
            <MdOpenInNew size={16} />
          </button>
        </div>

        {error ? <div className="inline-reply-error">{error}</div> : null}

        <textarea
          ref={textareaRef}
          className="inline-reply-body"
          value={body}
          onChange={(e) => setBody(e.target.value)}
          placeholder=""
          rows={6}
        />

        <div className="inline-reply-trim" aria-hidden>
          ···
        </div>

        <div className="inline-reply-footer">
          <div className="inline-reply-send-group">
            <button className="inline-reply-send" type="submit" disabled={busy}>
              {busy ? 'Sending…' : 'Send'}
            </button>
            <button
              className="inline-reply-send-caret"
              type="button"
              disabled={busy}
              aria-label="More send options"
              title="More send options"
            >
              <MdArrowDropDown size={20} />
            </button>
          </div>

          <div className="inline-reply-tools">
            <button type="button" title="Formatting" aria-label="Formatting">
              <MdFormatSize size={20} />
            </button>
            <button
              type="button"
              title="Attach files"
              aria-label="Attach files"
              onClick={() => fileRef.current?.click()}
            >
              <MdAttachFile size={20} />
            </button>
            <button type="button" title="Insert link" aria-label="Insert link">
              <MdInsertLink size={20} />
            </button>
            <button type="button" title="Insert emoji" aria-label="Insert emoji">
              <MdInsertEmoticon size={20} />
            </button>
            <button type="button" title="Insert drive file" aria-label="Insert drive file">
              <MdInsertDriveFile size={20} />
            </button>
            <button type="button" title="Insert photo" aria-label="Insert photo">
              <MdImage size={20} />
            </button>
            <button type="button" title="Confidential mode" aria-label="Confidential mode">
              <MdLockClock size={20} />
            </button>
            <button type="button" title="Insert signature" aria-label="Insert signature">
              <MdEdit size={20} />
            </button>
            <button type="button" title="More options" aria-label="More options">
              <MdMoreVert size={20} />
            </button>
          </div>

          <button
            type="button"
            className="inline-reply-discard"
            title="Discard"
            aria-label="Discard"
            onClick={onClose}
          >
            <MdDeleteOutline size={20} />
          </button>
        </div>

        <input
          ref={fileRef}
          type="file"
          multiple
          hidden
          onChange={(e) => setFiles(e.target.files)}
        />
        {files && files.length > 0 ? (
          <div className="inline-reply-files">
            {Array.from(files).map((f) => (
              <span key={f.name + f.size}>{f.name}</span>
            ))}
          </div>
        ) : null}
      </form>
    </div>
  );
}
