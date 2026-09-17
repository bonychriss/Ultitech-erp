import { useEffect, useState, type FormEvent } from 'react';
import {
  MdAdd,
  MdClose,
  MdDelete,
  MdDns,
  MdEdit,
  MdEmail,
  MdLockOutline,
  MdPersonOutline,
  MdSecurity,
  MdTag,
  MdVisibility,
  MdVisibilityOff,
} from 'react-icons/md';
import {
  api,
  type AccountDetail,
  type AccountInput,
} from '../api';

/** Empty mailbox form; From name defaults to the company name in capitals. */
function blankPreset(email = '', displayName = ''): AccountInput {
  const trimmed = email.trim();
  const domain = trimmed.includes('@') ? trimmed.split('@')[1] : '';
  const companyName = companyFromName(displayName) || companyNameFromEmail(trimmed);
  return {
    email: trimmed,
    display_name: companyName,
    imap_host: domain ? `mail.${domain}` : '',
    imap_port: 993,
    imap_encryption: 'ssl',
    imap_username: trimmed,
    imap_password: '',
    smtp_host: domain ? `mail.${domain}` : '',
    smtp_port: 465,
    smtp_encryption: 'ssl',
    smtp_username: trimmed,
    smtp_password: '',
  };
}

/** Known company From names (shown on the receiver side). */
function companyNameFromEmail(email: string): string {
  const domain = email.includes('@') ? email.split('@')[1].toLowerCase() : '';
  if (domain.includes('roadmasterspares.com') || domain.includes('roadmaster')) {
    return 'ROADMASTER SPARES LIMITED';
  }
  if (domain.includes('ultimate.co.tz') || domain.includes('ultimate')) {
    return 'ULTIMATE';
  }
  if (typeof window !== 'undefined') {
    const host = window.location.hostname.toLowerCase();
    if (host.includes('roadmasterspares.com')) return 'ROADMASTER SPARES LIMITED';
    if (host.includes('ultimate.co.tz')) return 'ULTIMATE';
  }
  if (!domain) return '';
  const base = domain.split('.')[0] || '';
  return base.replace(/[-_]+/g, ' ').trim().toUpperCase();
}

/** Prefer an explicit company-style name; ignore usernames like "admin". */
function companyFromName(name: string): string {
  const trimmed = name.trim();
  if (!trimmed) return '';
  if (/^(admin|user|test|root|demo)$/i.test(trimmed)) return '';
  // Already looks like a company / display name
  if (trimmed === trimmed.toUpperCase() || /\s/.test(trimmed)) {
    return trimmed.toUpperCase();
  }
  return trimmed.toUpperCase();
}

function titleFromLocalPart(local: string): string {
  return (
    local
      .split(/[._-]/)
      .filter(Boolean)
      .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
      .join(' ') || ''
  );
}

function presetFromEmail(email: string, displayName = ''): AccountInput {
  const trimmed = email.trim();
  const preferred =
    companyFromName(displayName) ||
    companyNameFromEmail(trimmed) ||
    titleFromLocalPart(trimmed.includes('@') ? trimmed.split('@')[0] : '').toUpperCase();
  return blankPreset(trimmed, preferred);
}

function fromDetail(a: AccountDetail): AccountInput {
  const fallback = companyNameFromEmail(a.email);
  const existing = (a.display_name || '').trim();
  const display =
    companyFromName(existing) ||
    fallback ||
    existing.toUpperCase();
  return {
    email: a.email,
    display_name: display,
    imap_host: a.imap_host,
    imap_port: a.imap_port,
    imap_encryption: a.imap_encryption || 'ssl',
    imap_username: a.imap_username,
    imap_password: '',
    smtp_host: a.smtp_host,
    smtp_port: a.smtp_port,
    smtp_encryption: a.smtp_encryption || 'tls',
    smtp_username: a.smtp_username,
    smtp_password: '',
  };
}

function applyEmailDefaults(email: string, prev: AccountInput): AccountInput {
  const trimmed = email.trim();
  const domain = trimmed.includes('@') ? trimmed.split('@')[1] : '';
  const next = { ...prev, email: trimmed };

  if (!prev.imap_username || prev.imap_username === prev.email) {
    next.imap_username = trimmed;
  }
  if (!prev.smtp_username || prev.smtp_username === prev.email) {
    next.smtp_username = trimmed;
  }
  if (domain && (!prev.imap_host || prev.imap_host.startsWith('mail.'))) {
    next.imap_host = `mail.${domain}`;
  }
  if (domain && (!prev.smtp_host || prev.smtp_host.startsWith('mail.'))) {
    next.smtp_host = `mail.${domain}`;
  }
  return next;
}

type Mode = 'list' | 'create' | 'edit';
type SetupStep = 1 | 2 | 3;

const STEP_LABELS: Record<SetupStep, string> = {
  1: 'Account identity',
  2: 'Incoming mail',
  3: 'Outgoing mail',
};

type Props = {
  onToast: (message: string) => void;
  onAccountsChanged: () => void;
  preferredEmail?: string;
  /** Registered app username — used as default mailbox display name. */
  preferredDisplayName?: string;
  focusAccountId?: number | null;
  /** Only true after IMAP auth failure — forces one password re-entry. */
  requirePassword?: boolean;
  onPasswordSaved?: () => void;
};

export function EmailSettings({
  onToast,
  onAccountsChanged,
  preferredEmail,
  preferredDisplayName,
  focusAccountId,
  requirePassword = false,
  onPasswordSaved,
}: Props) {
  const [accounts, setAccounts] = useState<AccountDetail[]>([]);
  const [loading, setLoading] = useState(true);
  const [mode, setMode] = useState<Mode>('list');
  const [step, setStep] = useState<SetupStep>(1);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<AccountInput>(() =>
    blankPreset(preferredEmail || '', preferredDisplayName || ''),
  );
  const [samePassword, setSamePassword] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [showImapPassword, setShowImapPassword] = useState(false);
  const [showSmtpPassword, setShowSmtpPassword] = useState(false);
  /** When sync failed auth, force re-entry — blank must not keep the bad password. */
  const [passwordRequired, setPasswordRequired] = useState(requirePassword);
  const [hint, setHint] = useState('');

  function companyPreset() {
    return blankPreset(preferredEmail || '', preferredDisplayName || '');
  }

  async function load() {
    setLoading(true);
    try {
      const data = await api.accounts();
      setAccounts(data.accounts);
      if (data.accounts.length === 0) {
        setMode('create');
        setStep(1);
        setForm(companyPreset());
        setSamePassword(true);
        setPasswordRequired(true);
        setHint('');
        setError('');
      } else if (focusAccountId) {
        const target =
          data.accounts.find((a) => a.id === focusAccountId) || data.accounts[0];
        if (target) {
          setMode('edit');
          setStep(requirePassword ? 2 : 1);
          setEditingId(target.id);
          setForm(fromDetail(target));
          setSamePassword(true);
          setPasswordRequired(requirePassword);
          setError('');
          setHint(
            requirePassword
              ? 'Type the mailbox password once, then Continue → Save changes. After it saves, you will not need to enter it again.'
              : '',
          );
        }
      } else {
        setMode('list');
        setPasswordRequired(false);
        setHint('');
        setError('');
      }
    } catch (err) {
      onToast(err instanceof Error ? err.message : 'Failed to load accounts');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusAccountId, requirePassword]);

  function openCreate() {
    setMode('create');
    setStep(1);
    setEditingId(null);
    setForm(companyPreset());
    setSamePassword(true);
    setPasswordRequired(true);
    setError('');
    setHint('');
  }

  function openEdit(a: AccountDetail) {
    setMode('edit');
    setStep(1);
    setEditingId(a.id);
    setForm(fromDetail(a));
    setSamePassword(true);
    setPasswordRequired(false);
    setError('');
    setHint('');
  }

  function backToList() {
    setMode('list');
    setEditingId(null);
    setStep(1);
    setPasswordRequired(false);
    setError('');
    setHint('');
  }

  function setField<K extends keyof AccountInput>(key: K, value: AccountInput[K]) {
    setForm((prev) => {
      let next = { ...prev, [key]: value };
      if (key === 'email' && typeof value === 'string') {
        next = applyEmailDefaults(value, prev);
      }
      if (key === 'imap_password' && samePassword && typeof value === 'string') {
        next.smtp_password = value;
      }
      return next;
    });
  }

  function validateStep(s: SetupStep): string | null {
    if (s === 1) {
      if (!form.email.trim()) return 'Enter your company email.';
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
        return 'Enter a valid company email address.';
      }
      if (!form.display_name.trim()) {
        return 'Enter the From name that should appear on the receiver’s side.';
      }
      return null;
    }
    if (s === 2) {
      if (!form.imap_host.trim()) return 'Enter the IMAP host.';
      if (!form.imap_port) return 'Enter the IMAP port.';
      if (!form.imap_username.trim()) return 'Enter the IMAP username.';
      if ((mode === 'create' || passwordRequired) && !form.imap_password) {
        return 'Enter the mailbox password from StackCP (do not leave blank).';
      }
      return null;
    }
    if (!form.smtp_host.trim()) return 'Enter the SMTP host.';
    if (!form.smtp_port) return 'Enter the SMTP port.';
    if (!form.smtp_username.trim()) return 'Enter the SMTP username.';
    if ((mode === 'create' || passwordRequired) && !form.imap_password) {
      return 'Go back to Incoming mail and enter the mailbox password.';
    }
    if ((mode === 'create' || passwordRequired) && !samePassword && !form.smtp_password) {
      return 'Enter the SMTP password, or use the same password as IMAP.';
    }
    return null;
  }

  function goNext() {
    const msg = validateStep(step);
    if (msg) {
      setError(msg);
      return;
    }
    setError('');
    if (step === 1) {
      setForm((prev) => {
        const filled = presetFromEmail(prev.email, preferredDisplayName || prev.display_name);
        return {
          ...filled,
          display_name: prev.display_name.trim() || filled.display_name,
          imap_password: prev.imap_password,
          smtp_password: prev.smtp_password,
        };
      });
    }
    if (step < 3) setStep((s) => (s + 1) as SetupStep);
  }

  function goPrevious() {
    setError('');
    if (step > 1) setStep((s) => (s - 1) as SetupStep);
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    const msg = validateStep(3);
    if (msg) {
      setError(msg);
      return;
    }
    setBusy(true);
    setError('');
    try {
      const payload: AccountInput = {
        ...form,
        smtp_password: samePassword ? form.imap_password : form.smtp_password,
      };
      if (!payload.imap_password) delete payload.imap_password;
      if (!payload.smtp_password) delete payload.smtp_password;

      const result =
        mode === 'edit' && editingId
          ? await api.updateAccount(editingId, payload)
          : await api.createAccount(payload);

      onToast(result.message || 'Mailbox registered');
      setPasswordRequired(false);
      setHint('');
      onPasswordSaved?.();
      await load();
      onAccountsChanged();
      setMode('list');
      setEditingId(null);
      setStep(1);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Save failed');
    } finally {
      setBusy(false);
    }
  }

  async function removeAccount(a: AccountDetail) {
    if (!window.confirm(`Remove mailbox ${a.email}?`)) return;
    try {
      const res = await api.deleteAccount(a.id);
      onToast(res.message || 'Removed');
      await load();
      onAccountsChanged();
    } catch (err) {
      onToast(err instanceof Error ? err.message : 'Delete failed');
    }
  }

  if (mode === 'create' || mode === 'edit') {
    return (
      <div className="wizard">
        <header className="wizard-top">
          <div className="wizard-mark" aria-hidden>
            <span />
            <span />
            <span />
          </div>
          <nav className="wizard-stepper" aria-label={`Step ${step} of 3`}>
            {([1, 2, 3] as SetupStep[]).map((n, i) => (
              <div key={n} className="wizard-step-wrap">
                {i > 0 ? <div className={`wizard-line ${n <= step ? 'on' : ''}`} /> : null}
                <div
                  className={`wizard-step ${n === step ? 'active' : ''} ${n < step ? 'done' : ''}`}
                >
                  <span className="wizard-num">{n}</span>
                  <span className="wizard-label">{STEP_LABELS[n]}</span>
                </div>
              </div>
            ))}
          </nav>
        </header>

        <form
          className="wizard-body"
          onSubmit={(e) => {
            if (step < 3) {
              e.preventDefault();
              goNext();
              return;
            }
            void onSubmit(e);
          }}
        >
          <div className="wizard-title-row">
            <h1>{STEP_LABELS[step]}</h1>
            {accounts.length > 0 ? (
              <button type="button" className="wizard-cancel" onClick={backToList}>
                <MdClose size={16} aria-hidden />
                Cancel
              </button>
            ) : null}
          </div>

          {error ? <div className="settings-error">{error}</div> : null}
          {hint && !error ? <div className="settings-hint">{hint}</div> : null}

          {step === 1 ? (
            <>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    Mailbox<span className="req">*</span>
                  </h2>
                  <p>The address people see when you send mail.</p>
                </div>
                <div className="wizard-fields">
                  <div className="wizard-auth-fields">
                    <div className="field-line">
                      <MdEmail size={18} aria-hidden />
                      <input
                        type="email"
                        required
                        autoFocus
                        aria-label="Company email"
                        placeholder="Company email"
                        value={form.email}
                        onChange={(e) => {
                          const nextEmail = e.target.value;
                          setForm((prev) => {
                            const applied = applyEmailDefaults(nextEmail, prev);
                            const keepName =
                              prev.display_name.trim() !== '' &&
                              prev.display_name.trim() !== companyNameFromEmail(prev.email);
                            return {
                              ...applied,
                              display_name: keepName
                                ? prev.display_name
                                : companyNameFromEmail(nextEmail) || prev.display_name,
                            };
                          });
                        }}
                      />
                    </div>
                    <p className="field-hint-inline">
                      Domain mailbox used for send and receive.
                    </p>
                  </div>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    From name<span className="req">*</span>
                  </h2>
                  <p>
                    This is the name that appears on the receiver’s side (e.g. in their inbox From
                    column).
                  </p>
                </div>
                <div className="wizard-fields">
                  <div className="wizard-auth-fields">
                    <div className="field-line">
                      <MdPersonOutline size={18} aria-hidden />
                      <input
                        required
                        aria-label="From name"
                        placeholder="Company name in CAPITALS"
                        value={form.display_name}
                        onChange={(e) => setField('display_name', e.target.value.toUpperCase())}
                      />
                    </div>
                    <p className="field-hint-inline">
                      Defaults to your company name in capital letters. Change it only if you want
                      a different sender name.
                    </p>
                  </div>
                </div>
              </div>
            </>
          ) : null}

          {step === 2 ? (
            <>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    Server<span className="req">*</span>
                  </h2>
                  <p>IMAP host used to sync Inbox, Sent, and Drafts.</p>
                </div>
                <div className="wizard-fields">
                  <div className="wizard-auth-fields">
                    <div className="field-line">
                      <MdDns size={18} aria-hidden />
                      <input
                        required
                        autoFocus
                        aria-label="IMAP host"
                        placeholder="IMAP host (e.g. mail.yourdomain.com)"
                        value={form.imap_host}
                        onChange={(e) => setField('imap_host', e.target.value)}
                      />
                    </div>
                    <div className="field-line-row">
                      <div className="field-line">
                        <MdTag size={18} aria-hidden />
                        <input
                          type="number"
                          required
                          aria-label="IMAP port"
                          placeholder="IMAP port"
                          value={form.imap_port}
                          onChange={(e) => setField('imap_port', Number(e.target.value) || 993)}
                        />
                      </div>
                      <div className="field-line">
                        <MdSecurity size={18} aria-hidden />
                        <select
                          aria-label="Encryption"
                          value={form.imap_encryption}
                          onChange={(e) => setField('imap_encryption', e.target.value)}
                        >
                          <option value="ssl">SSL/TLS (recommended)</option>
                          <option value="tls">STARTTLS</option>
                          <option value="none">None</option>
                        </select>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    Login<span className="req">*</span>
                  </h2>
                  <p>cPanel mailbox username and password.</p>
                </div>
                <div className="wizard-fields">
                  <div className="wizard-auth-fields">
                    <div className="field-line">
                      <MdEmail size={18} aria-hidden />
                      <input
                        required
                        aria-label="IMAP username"
                        placeholder="IMAP username"
                        value={form.imap_username}
                        onChange={(e) => setField('imap_username', e.target.value)}
                      />
                    </div>
                    <div className="field-line">
                      <MdLockOutline size={18} aria-hidden />
                      <input
                        type={showImapPassword ? 'text' : 'password'}
                        required={mode === 'create' || passwordRequired}
                        autoComplete="new-password"
                        autoFocus={passwordRequired}
                        aria-label="IMAP password"
                        placeholder={
                          passwordRequired || mode === 'create'
                            ? 'Paste mailbox password from StackCP'
                            : 'Leave blank to keep current password'
                        }
                        value={form.imap_password || ''}
                        onChange={(e) => {
                          setField('imap_password', e.target.value);
                          if (e.target.value.trim()) {
                            setHint('');
                            setError('');
                          }
                        }}
                      />
                      <button
                        type="button"
                        className="field-eye"
                        aria-label={showImapPassword ? 'Hide password' : 'Show password'}
                        onClick={() => setShowImapPassword((v) => !v)}
                      >
                        {showImapPassword ? (
                          <MdVisibilityOff size={18} />
                        ) : (
                          <MdVisibility size={18} />
                        )}
                      </button>
                    </div>
                    {passwordRequired ? (
                      <p className="field-hint-inline">
                        Required — the password currently saved in Mail is wrong. Leaving blank will
                        not update it.
                      </p>
                    ) : null}
                  </div>
                </div>
              </div>
            </>
          ) : null}

          {step === 3 ? (
            <>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    Server<span className="req">*</span>
                  </h2>
                  <p>SMTP host used when you send, reply, or forward.</p>
                </div>
                <div className="wizard-fields">
                  <div className="wizard-auth-fields">
                    <div className="field-line">
                      <MdDns size={18} aria-hidden />
                      <input
                        required
                        autoFocus
                        aria-label="SMTP host"
                        placeholder="SMTP host (e.g. mail.yourdomain.com)"
                        value={form.smtp_host}
                        onChange={(e) => setField('smtp_host', e.target.value)}
                      />
                    </div>
                    <div className="field-line-row">
                      <div className="field-line">
                        <MdTag size={18} aria-hidden />
                        <input
                          type="number"
                          required
                          aria-label="SMTP port"
                          placeholder="SMTP port"
                          value={form.smtp_port}
                          onChange={(e) => setField('smtp_port', Number(e.target.value) || 465)}
                        />
                      </div>
                      <div className="field-line">
                        <MdSecurity size={18} aria-hidden />
                        <select
                          aria-label="Encryption"
                          value={form.smtp_encryption}
                          onChange={(e) => setField('smtp_encryption', e.target.value)}
                        >
                          <option value="ssl">SSL/TLS — port 465</option>
                          <option value="tls">STARTTLS — port 587</option>
                          <option value="none">None</option>
                        </select>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    Login<span className="req">*</span>
                  </h2>
                  <p>Outgoing credentials for this mailbox.</p>
                </div>
                <div className="wizard-fields">
                  <div className="wizard-auth-fields">
                    <div className="field-line">
                      <MdEmail size={18} aria-hidden />
                      <input
                        required
                        aria-label="SMTP username"
                        placeholder="SMTP username"
                        value={form.smtp_username}
                        onChange={(e) => setField('smtp_username', e.target.value)}
                      />
                    </div>
                    <label className="remember">
                      <input
                        type="checkbox"
                        checked={samePassword}
                        onChange={(e) => {
                          const on = e.target.checked;
                          setSamePassword(on);
                          if (on) {
                            setForm((prev) => ({
                              ...prev,
                              smtp_password: prev.imap_password || '',
                            }));
                          }
                        }}
                      />
                      Use same password as IMAP
                    </label>
                    {samePassword ? (
                      <p className="field-hint-inline">
                        SMTP will use the IMAP password from the previous step.
                      </p>
                    ) : (
                      <div className="field-line">
                        <MdLockOutline size={18} aria-hidden />
                        <input
                          type={showSmtpPassword ? 'text' : 'password'}
                          required={mode === 'create'}
                          autoComplete="new-password"
                          aria-label="SMTP password"
                          placeholder={
                            mode === 'edit'
                              ? 'Leave blank to keep current password'
                              : 'SMTP password'
                          }
                          value={form.smtp_password || ''}
                          onChange={(e) => setField('smtp_password', e.target.value)}
                        />
                        <button
                          type="button"
                          className="field-eye"
                          aria-label={showSmtpPassword ? 'Hide password' : 'Show password'}
                          onClick={() => setShowSmtpPassword((v) => !v)}
                        >
                          {showSmtpPassword ? (
                            <MdVisibilityOff size={18} />
                          ) : (
                            <MdVisibility size={18} />
                          )}
                        </button>
                      </div>
                    )}
                  </div>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Quick check</h2>
                  <p>Confirm settings before connecting.</p>
                </div>
                <div className="wizard-fields">
                  <div className="setup-summary">
                    <ul>
                      <li>
                        Identity: {form.display_name || '—'} &lt;{form.email || '—'}&gt;
                      </li>
                      <li>
                        Incoming: {form.imap_host || '—'} : {form.imap_port} (
                        {form.imap_encryption})
                      </li>
                      <li>
                        Outgoing: {form.smtp_host || '—'} : {form.smtp_port} (
                        {form.smtp_encryption})
                      </li>
                      <li>Login as {form.imap_username || form.email || '—'}</li>
                    </ul>
                  </div>
                </div>
              </div>
            </>
          ) : null}

          <div className="wizard-footer">
            <p className="wizard-note">
              <span className="req">*</span>This field is mandatory
            </p>
            <div className="wizard-footer-actions">
              <button
                type="button"
                className="wizard-btn-prev"
                onClick={goPrevious}
                disabled={step === 1}
              >
                Previous
              </button>
              {step < 3 ? (
                <button className="wizard-btn-next" type="submit">
                  Continue
                </button>
              ) : (
                <button className="wizard-btn-next" type="submit" disabled={busy}>
                  {busy
                    ? 'Testing & saving…'
                    : mode === 'create'
                      ? 'Register & connect'
                      : 'Save changes'}
                </button>
              )}
            </div>
          </div>
        </form>
      </div>
    );
  }

  return (
    <div className="settings-panel">
      <div className="settings-head">
        <h1>Email accounts</h1>
        <button type="button" className="settings-primary" onClick={() => openCreate()}>
          <MdAdd size={18} aria-hidden />
          Register mailbox
        </button>
      </div>

      {loading ? (
        <div className="empty">Loading…</div>
      ) : accounts.length === 0 ? (
        <div className="empty">
          <h2>No mailbox yet</h2>
          <p>Register your company mailbox with IMAP/SMTP settings to send and receive.</p>
          <button type="button" className="settings-primary" onClick={() => openCreate()}>
            Register mailbox
          </button>
        </div>
      ) : (
        <div className="account-cards">
          {accounts.map((a) => {
            const sameHost = a.imap_host === a.smtp_host;
            const servers = sameHost
              ? `${a.imap_host} · IMAP ${a.imap_port} · SMTP ${a.smtp_port}`
              : `IMAP ${a.imap_host}:${a.imap_port} · SMTP ${a.smtp_host}:${a.smtp_port}`;
            return (
              <div key={a.id} className="account-card">
                <strong className="account-card-name">{a.display_name || a.email}</strong>
                <span className="account-card-email muted">{a.email}</span>
                <span className="account-card-servers muted" title={servers}>
                  {servers}
                </span>
                <div className="account-actions">
                  <button type="button" className="tool" onClick={() => openEdit(a)}>
                    <MdEdit size={18} aria-hidden />
                    Edit
                  </button>
                  <button type="button" className="tool" onClick={() => void removeAccount(a)}>
                    <MdDelete size={18} aria-hidden />
                    Remove
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
