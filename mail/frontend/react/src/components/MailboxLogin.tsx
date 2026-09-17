import { useEffect, useState, type FormEvent } from 'react';
import { MdArrowBack, MdEmail, MdLockOutline, MdLogin, MdPerson } from 'react-icons/md';
import { api, type PoolMailbox } from '../api';

type Props = {
  onConnected: () => void;
  onToast: (message: string) => void;
};

export function MailboxLogin({ onConnected, onToast }: Props) {
  const [mailboxes, setMailboxes] = useState<PoolMailbox[]>([]);
  const [loading, setLoading] = useState(true);
  const [step, setStep] = useState<'pick' | 'login'>('pick');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [email, setEmail] = useState('');
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

  function openLogin(m: PoolMailbox) {
    setSelectedId(m.id);
    setEmail(m.email);
    setPassword('');
    setError('');
    setStep('login');
  }

  function backToPick() {
    setStep('pick');
    setPassword('');
    setError('');
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!email.trim()) {
      setError('Enter the account email.');
      return;
    }
    if (!password.trim()) {
      setError('Enter the account password.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      const selected = mailboxes.find((m) => m.id === selectedId);
      const emailNorm = email.trim().toLowerCase();
      const id =
        selected && selected.email.toLowerCase() === emailNorm ? selected.id : undefined;
      const res = await api.claimMailbox({
        id,
        email: emailNorm,
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
    return <div className="mailbox-login empty">Loading mailboxes...</div>;
  }

  if (mailboxes.length === 0) {
    return (
      <div className="mailbox-login">
        <div className="mailbox-login-panel">
          <h1>No mailbox yet</h1>
          <p className="muted">
            Ask your admin to add your company email in Mail settings, then come back here to sign
            in once.
          </p>
        </div>
      </div>
    );
  }

  if (step === 'login') {
    return (
      <div className="mailbox-login">
        <form className="mailbox-login-panel" onSubmit={(e) => void onSubmit(e)}>
          <button type="button" className="mailbox-back" onClick={backToPick}>
            <MdArrowBack size={18} aria-hidden />
            Back
          </button>
          <h1>Sign in to mailbox</h1>
          <p className="muted">Enter the account email and password. You only need to do this once.</p>

          {error ? <div className="settings-error">{error}</div> : null}

          <label className="mailbox-field">
            Account email
            <div className="field-line">
              <MdEmail size={18} aria-hidden />
              <input
                type="email"
                autoComplete="username"
                placeholder="sales@roadmasterspares.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>
          </label>

          <label className="mailbox-field">
            Account password
            <div className="field-line">
              <MdLockOutline size={18} aria-hidden />
              <input
                type="password"
                autoComplete="current-password"
                placeholder="Password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoFocus
              />
            </div>
          </label>

          <button className="wizard-btn-next mailbox-login-submit" type="submit" disabled={busy}>
            <MdLogin size={18} aria-hidden />
            {busy ? 'Connecting...' : 'Log in'}
          </button>
        </form>
      </div>
    );
  }

  return (
    <div className="mailbox-login">
      <div className="mailbox-login-panel mailbox-login-panel--wide">
        <h1>Choose your mailbox</h1>
        <p className="muted">Pick an account to continue.</p>

        {error ? <div className="settings-error">{error}</div> : null}

        <div className="mailbox-tile-grid" role="list">
          {mailboxes.map((m, i) => {
            const typeLabel = (m.account_type || '').trim();
            const label =
              typeLabel ||
              m.display_name ||
              m.email.split('@')[0];
            const tone = ['purple', 'blue', 'green'][i % 3];
            return (
              <button
                key={m.id}
                type="button"
                role="listitem"
                className={`mailbox-tile tone-${tone}`}
                onClick={() => openLogin(m)}
              >
                <span className="mailbox-tile-icon" aria-hidden>
                  <MdPerson size={22} />
                </span>
                <span className="mailbox-tile-text">
                  <strong>
                    {typeLabel
                      ? typeLabel.charAt(0).toUpperCase() + typeLabel.slice(1)
                      : label}
                  </strong>
                </span>
              </button>
            );
          })}
        </div>
      </div>
    </div>
  );
}
