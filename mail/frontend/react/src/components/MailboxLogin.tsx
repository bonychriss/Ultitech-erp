import { useEffect, useState, type FormEvent } from 'react';
import { MdEmail, MdLockOutline, MdLogin } from 'react-icons/md';
import { api, type PoolMailbox } from '../api';

type Props = {
  onConnected: () => void;
  onToast: (message: string) => void;
  isMailAdmin?: boolean;
  onOpenAdmin?: () => void;
};

export function MailboxLogin({ onConnected, onToast, isMailAdmin, onOpenAdmin }: Props) {
  const [mailboxes, setMailboxes] = useState<PoolMailbox[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      try {
        const data = await api.availableMailboxes();
        if (cancelled) return;
        setMailboxes(data.mailboxes);
        if (data.mailboxes.length === 1) {
          setSelectedId(data.mailboxes[0].id);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Could not load mailboxes');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!selectedId) {
      setError('Select a mailbox first.');
      return;
    }
    if (!password.trim()) {
      setError('Enter the mailbox password your admin sent you.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      const selected = mailboxes.find((m) => m.id === selectedId);
      const res = await api.claimMailbox({
        id: selectedId,
        email: selected?.email || '',
        password,
      });
      onToast(res.message || 'Mailbox connected');
      onConnected();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return <div className="mailbox-login empty">Loading available mailboxes...</div>;
  }

  if (mailboxes.length === 0) {
    return (
      <div className="mailbox-login">
        <div className="mailbox-login-card">
          <h1>No mailbox yet</h1>
          <p className="muted">
            Ask your admin to add your company email in Mail settings (Team mailboxes), or to share
            the password for an existing address such as sales@.... Then pick it here and log in once.
          </p>
          {isMailAdmin && onOpenAdmin ? (
            <button type="button" className="wizard-btn-next" onClick={onOpenAdmin}>
              Create team mailboxes
            </button>
          ) : null}
        </div>
      </div>
    );
  }

  return (
    <div className="mailbox-login">
      <form className="mailbox-login-card" onSubmit={(e) => void onSubmit(e)}>
        <h1>Choose your mailbox</h1>
        <p className="muted">
          Select the account your admin created for you, then enter the password they sent. You only
          need to do this once.
        </p>

        {error ? <div className="settings-error">{error}</div> : null}

        <div className="mailbox-pick-list" role="listbox" aria-label="Available mailboxes">
          {mailboxes.map((m) => {
            const active = selectedId === m.id;
            return (
              <button
                key={m.id}
                type="button"
                role="option"
                aria-selected={active}
                className={`mailbox-pick ${active ? 'on' : ''}`}
                onClick={() => {
                  setSelectedId(m.id);
                  setError('');
                }}
              >
                <span className="mailbox-pick-avatar" aria-hidden>
                  {(m.display_name || m.email).slice(0, 1).toUpperCase()}
                </span>
                <span className="mailbox-pick-text">
                  <strong>{m.display_name || m.email.split('@')[0]}</strong>
                  <span className="muted">{m.email}</span>
                </span>
                <MdEmail size={18} aria-hidden />
              </button>
            );
          })}
        </div>

        <label className="mailbox-pass-label">
          Mailbox password
          <div className="field-line">
            <MdLockOutline size={18} aria-hidden />
            <input
              type="password"
              autoComplete="current-password"
              placeholder="Password from your admin"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </div>
        </label>

        <button className="wizard-btn-next mailbox-login-submit" type="submit" disabled={busy}>
          <MdLogin size={18} aria-hidden />
          {busy ? 'Connecting...' : 'Log in to mailbox'}
        </button>

        {isMailAdmin && onOpenAdmin ? (
          <button type="button" className="mailbox-admin-link" onClick={onOpenAdmin}>
            Admin: manage team mailboxes
          </button>
        ) : null}
      </form>
    </div>
  );
}
