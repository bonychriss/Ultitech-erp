import { useEffect, useRef, useState, type FormEvent } from 'react';
import { MdAttachFile } from 'react-icons/md';
import { api, type MailDetail } from '../api';

export type ComposeState = {
  open: boolean;
  title?: string;
  to?: string;
  cc?: string;
  bcc?: string;
  subject?: string;
  body?: string;
  inReplyTo?: string | null;
  draftId?: number | null;
};

type Props = {
  state: ComposeState;
  onClose: () => void;
  onSent: (message: string) => void;
};

function CheckIcon() {
  return (
    <svg className="compose-check" viewBox="0 0 16 16" width="14" height="14" aria-hidden>
      <path
        fill="#2e7d4f"
        d="M6.5 11.2 3.3 8l1.1-1.1 2.1 2.1 4.6-4.6L12.2 5.5 6.5 11.2z"
      />
    </svg>
  );
}

export function ComposePopup({ state, onClose, onSent }: Props) {
  const [to, setTo] = useState('');
  const [cc, setCc] = useState('');
  const [bcc, setBcc] = useState('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [files, setFiles] = useState<FileList | null>(null);
  const [minimized, setMinimized] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!state.open) return;
    setTo(state.to || '');
    setCc(state.cc || '');
    setBcc(state.bcc || '');
    setSubject(state.subject || '');
    setBody(state.body || '');
    setFiles(null);
    if (fileRef.current) fileRef.current.value = '';
    setMinimized(false);
    setError('');
  }, [state]);

  useEffect(() => {
    if (!state.open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [state.open, onClose]);

  if (!state.open) return null;

  async function submit(kind: 'send' | 'draft') {
    setBusy(true);
    setError('');
    try {
      if (kind === 'send' && !to.trim()) {
        setError('Add at least one recipient.');
        return;
      }
      const form = new FormData();
      form.set('to', to);
      form.set('cc', cc);
      form.set('bcc', bcc);
      form.set('subject', subject);
      form.set('body', body);
      if (state.inReplyTo) form.set('in_reply_to', state.inReplyTo);
      if (state.draftId) form.set('draft_id', String(state.draftId));
      if (files) {
        Array.from(files).forEach((f) => form.append('attachments[]', f));
      }
      const result = kind === 'send' ? await api.send(form) : await api.draft(form);
      onSent(result.message || (kind === 'send' ? 'Sent' : 'Draft saved'));
      if (kind === 'send') onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed');
    } finally {
      setBusy(false);
    }
  }

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    void submit('send');
  }

  return (
    <div
      className={`compose-overlay${minimized ? ' is-minimized' : ''}`}
      onClick={(e) => {
        if (e.target === e.currentTarget && !minimized) onClose();
      }}
    >
      <div
        className={`compose-modal${minimized ? ' minimized' : ''}`}
        role="dialog"
        aria-modal="true"
        aria-label={state.title || 'New Message'}
      >
        <div className="compose-modal-header">
          <span>{state.title || 'New Message'}</span>
          <div className="compose-modal-controls">
            <button type="button" onClick={() => setMinimized((v) => !v)} title="Minimize" aria-label="Minimize">
              <span className="compose-ico-min" />
            </button>
            <button type="button" onClick={onClose} title="Close" aria-label="Close">
              ×
            </button>
          </div>
        </div>

        {!minimized ? (
          <form className="compose-modal-body" onSubmit={onSubmit}>
            {error ? <div className="compose-error">{error}</div> : null}

            <div className={`compose-field${to.trim() ? ' has-value' : ''}`}>
              <input
                id="compose-to"
                placeholder=" "
                value={to}
                onChange={(e) => setTo(e.target.value)}
                autoFocus
                required
              />
              <label htmlFor="compose-to">Recipients</label>
              {to.trim() ? <CheckIcon /> : null}
            </div>

            <div className={`compose-field${cc.trim() ? ' has-value' : ''}`}>
              <input id="compose-cc" placeholder=" " value={cc} onChange={(e) => setCc(e.target.value)} />
              <label htmlFor="compose-cc">Cc</label>
            </div>

            <div className={`compose-field${bcc.trim() ? ' has-value' : ''}`}>
              <input id="compose-bcc" placeholder=" " value={bcc} onChange={(e) => setBcc(e.target.value)} />
              <label htmlFor="compose-bcc">Bcc</label>
            </div>

            <div className={`compose-field${subject.trim() ? ' has-value' : ''}`}>
              <input
                id="compose-subject"
                placeholder=" "
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
              />
              <label htmlFor="compose-subject">Subject</label>
            </div>

            <div className={`compose-message${body.trim() ? ' has-value' : ''}`}>
              <textarea
                id="compose-body"
                placeholder="Write your message..."
                value={body}
                onChange={(e) => setBody(e.target.value)}
              />
              {body.trim() ? <CheckIcon /> : null}
            </div>

            <div className="compose-attach">
              <div className="compose-attach-row">
                <div className="compose-attach-title">
                  <MdAttachFile size={18} aria-hidden />
                  <span>Attach PDF or files</span>
                </div>
                <div className="compose-attach-picker">
                  <button
                    type="button"
                    className="compose-attach-btn"
                    onClick={() => fileRef.current?.click()}
                  >
                    Choose Files
                  </button>
                  <span className="compose-attach-status">
                    {files && files.length > 0
                      ? `${files.length} file${files.length === 1 ? '' : 's'} chosen`
                      : 'No file chosen'}
                  </span>
                  <input
                    ref={fileRef}
                    type="file"
                    multiple
                    hidden
                    accept=".pdf,.png,.jpg,.jpeg,.gif,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
                    onChange={(e) => setFiles(e.target.files)}
                  />
                </div>
              </div>
              <p className="compose-attach-hint">
                PDF, images, Office docs, ZIP — up to 10 files, 15MB each
              </p>
              {files && files.length > 0 ? (
                <div className="compose-attach-chips">
                  {Array.from(files).map((f) => (
                    <span className="file-chip" key={`${f.name}-${f.size}`}>
                      {f.name}
                    </span>
                  ))}
                </div>
              ) : null}
            </div>

            <div className="compose-modal-actions">
              <button className="compose-send" type="submit" disabled={busy}>
                Send
              </button>
              <button
                className="compose-draft"
                type="button"
                disabled={busy}
                onClick={() => void submit('draft')}
              >
                Save draft
              </button>
            </div>
          </form>
        ) : null}
      </div>
    </div>
  );
}

export function composeFromMessage(
  message: MailDetail,
  mode: 'reply' | 'forward',
  opts?: { folder?: string },
): ComposeState {
  const outgoing = opts?.folder === 'sent' || opts?.folder === 'drafts';
  if (mode === 'reply') {
    const to = outgoing
      ? message.to_display || ''
      : message.from_name
        ? `${message.from_name} <${message.from_email}>`
        : message.from_email;
    const who = outgoing ? message.from_display || 'me' : message.from_display;
    return {
      open: true,
      title: 'Reply',
      to,
      subject: message.subject.toLowerCase().startsWith('re:')
        ? message.subject
        : `Re: ${message.subject}`,
      body: `\n\nOn ${message.date_full}, ${who} wrote:\n${(message.body_text || '')
        .split('\n')
        .map((l) => `> ${l}`)
        .join('\n')}`,
      inReplyTo: message.message_id_header,
    };
  }
  return {
    open: true,
    title: 'Forward',
    subject: message.subject.toLowerCase().startsWith('fwd:')
      ? message.subject
      : `Fwd: ${message.subject}`,
    body: `\n\n---------- Forwarded message ----------\nFrom: ${message.from_display} <${message.from_email}>\nDate: ${message.date_full}\nSubject: ${message.subject}\n\n${message.body_text || ''}`,
  };
}
