import { useEffect, useState, type FormEvent } from 'react';
import { MdArrowBack, MdEmail, MdLockOutline, MdLogin, MdPerson } from 'react-icons/md';
import { api, type PoolMailbox } from '../api';
import { LoginHeroVideo } from './LoginHeroVideo';

type Props = {
  onConnected: () => void;
  onToast: (message: string) => void;
};

/** Ultitech / local module picker after leaving Mail. */
function selectModuleUrl(): string {
  if (typeof window === 'undefined') return '/select-module.php';
  const host = window.location.hostname.toLowerCase();
  const path = window.location.pathname.replace(/\\/g, '/');

  if (host.includes('ultitech.io')) {
    if (path.includes('/roadmaster')) return '/roadmaster/select-module';
    if (path.includes('/ultimate')) return '/ultimate/select-module';
  }
  if (host.includes('roadmasterspares.com')) {
    return 'https://ultitech.io/roadmaster/select-module';
  }
  if (host.includes('ultimate.co.tz')) {
    return 'https://ultitech.io/ultimate/select-module';
  }
  if (path.includes('/public_html/')) {
    return '/public_html/select-module.php';
  }
  return '/select-module.php';
}

export function MailboxLogin({ onConnected, onToast }: Props) {
  const [mailboxes, setMailboxes] = useState<PoolMailbox[]>([]);
  const [loading, setLoading] = useState(true);
  const [step, setStep] = useState<'pick' | 'login'>('pick');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
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

  async function openRemembered(m: PoolMailbox) {
    setBusy(true);
    setError('');
    try {
      const res = await api.openMailbox({ id: m.id, email: m.email });
      onToast(res.message || 'Mailbox opened');
      onConnected();
    } catch (err) {
      // Fall back to password form if remember is stale.
      setSelectedId(m.id);
      setEmail(m.email);
      setPassword('');
      setRemember(true);
      setStep('login');
      setError(err instanceof Error ? err.message : 'Enter the mailbox password.');
    } finally {
      setBusy(false);
    }
  }

  function openLogin(m: PoolMailbox) {
    if (m.remembered) {
      void openRemembered(m);
      return;
    }
    setSelectedId(m.id);
    setEmail(m.email);
    setPassword('');
    setRemember(true);
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
        remember,
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
            Add a company mailbox to start sending and receiving mail.
          </p>
        </div>
      </div>
    );
  }

  if (step === 'login') {
    return (
      <div className="mailbox-login mailbox-login--hero">
        <LoginHeroVideo />
        <form
          className="mailbox-login-panel mailbox-login-panel--glass"
          onSubmit={(e) => void onSubmit(e)}
        >
          <button type="button" className="mailbox-back" onClick={backToPick}>
            <MdArrowBack size={18} aria-hidden />
            Back
          </button>
          <h1>Sign in to mailbox</h1>
          <p className="muted">
            Enter the account email and password. With Remember me on, you only need to do this
            once while signed into Ultitech.
          </p>

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

          <label className="remember mailbox-remember">
            <input
              type="checkbox"
              checked={remember}
              onChange={(e) => setRemember(e.target.checked)}
            />
            Remember me
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
    <div className="mailbox-login mailbox-login--hero">
      <LoginHeroVideo />
      <div className="mailbox-login-panel mailbox-login-panel--wide mailbox-login-panel--on-video">
        <a className="mailbox-back" href={selectModuleUrl()}>
          <MdArrowBack size={18} aria-hidden />
          Modules
        </a>
        <h1>Choose your mailbox</h1>
        <p className="muted">
          {busy ? 'Opening mailbox…' : 'Pick an account to continue.'}
        </p>

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
                disabled={busy}
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
