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

/** cPanel-style defaults for Ultimate General Trading mail */
const ULTIMATE_PRESET: AccountInput = {
  email: 'sales@ultimate.co.tz',
  display_name: 'Ultimate Sales',
  imap_host: 'mail.ultimate.co.tz',
  imap_port: 993,
  imap_encryption: 'ssl',
  imap_username: 'sales@ultimate.co.tz',
  imap_password: '',
  smtp_host: 'mail.ultimate.co.tz',
  smtp_port: 465,
  smtp_encryption: 'ssl',
  smtp_username: 'sales@ultimate.co.tz',
  smtp_password: '',
};

function presetFromEmail(email: string): AccountInput {
  const trimmed = email.trim() || ULTIMATE_PRESET.email;
  const domain = trimmed.includes('@') ? trimmed.split('@')[1] : 'ultimate.co.tz';
  const local = trimmed.includes('@') ? trimmed.split('@')[0] : 'sales';
  const display =
    local === 'sales'
      ? 'Ultimate Sales'
      : local
          .split(/[._-]/)
          .filter(Boolean)
          .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
          .join(' ') || 'Mailbox';

  return {
    email: trimmed,
    display_name: display,
    imap_host: `mail.${domain}`,
    imap_port: 993,
    imap_encryption: 'ssl',
    imap_username: trimmed,
    imap_password: '',
    smtp_host: `mail.${domain}`,
    smtp_port: 465,
    smtp_encryption: 'ssl',
    smtp_username: trimmed,
    smtp_password: '',
  };
}

function fromDetail(a: AccountDetail): AccountInput {
  return {
    email: a.email,
    display_name: a.display_name || '',
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
  focusAccountId?: number | null;
};

export function EmailSettings({
  onToast,
  onAccountsChanged,
  preferredEmail,
  focusAccountId,
}: Props) {
  const [accounts, setAccounts] = useState<AccountDetail[]>([]);
  const [loading, setLoading] = useState(true);
  const [mode, setMode] = useState<Mode>('list');
  const [step, setStep] = useState<SetupStep>(1);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<AccountInput>(() =>
    preferredEmail ? presetFromEmail(preferredEmail) : { ...ULTIMATE_PRESET },
  );
  const [samePassword, setSamePassword] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [showImapPassword, setShowImapPassword] = useState(false);
  const [showSmtpPassword, setShowSmtpPassword] = useState(false);

  function companyPreset() {
    return preferredEmail ? presetFromEmail(preferredEmail) : { ...ULTIMATE_PRESET };
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
      } else if (focusAccountId) {
        const target =
          data.accounts.find((a) => a.id === focusAccountId) || data.accounts[0];
        if (target) {
          setMode('edit');
          setStep(1);
          setEditingId(target.id);
          setForm(fromDetail(target));
          setSamePassword(true);
          setError('');
        }
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
  }, [focusAccountId]);

  function openCreate() {
    setMode('create');
    setStep(1);
    setEditingId(null);
    setForm(companyPreset());
    setSamePassword(true);
    setError('');
  }

  function openEdit(a: AccountDetail) {
    setMode('edit');
    setStep(1);
    setEditingId(a.id);
    setForm(fromDetail(a));
    setSamePassword(true);
    setError('');
  }

  function backToList() {
    setMode('list');
    setEditingId(null);
    setStep(1);
    setError('');
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
      return null;
    }
    if (s === 2) {
      if (!form.imap_host.trim()) return 'Enter the IMAP host.';
      if (!form.imap_port) return 'Enter the IMAP port.';
      if (!form.imap_username.trim()) return 'Enter the IMAP username.';
      if (mode === 'create' && !form.imap_password) {
        return 'Enter the IMAP mailbox password.';
      }
      return null;
    }
    if (!form.smtp_host.trim()) return 'Enter the SMTP host.';
    if (!form.smtp_port) return 'Enter the SMTP port.';
    if (!form.smtp_username.trim()) return 'Enter the SMTP username.';
    if (mode === 'create' && !samePassword && !form.smtp_password) {
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
        const filled = presetFromEmail(prev.email);
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
                        placeholder="Company email (e.g. sales@ultimate.co.tz)"
                        value={form.email}
                        onChange={(e) => setField('email', e.target.value)}
                      />
                    </div>
                    <p className="field-hint-inline">
                      Domain mailbox used for send and receive (e.g. sales@ultimate.co.tz).
                    </p>
                    <div className="field-line">
                      <MdPersonOutline size={18} aria-hidden />
                      <input
                        aria-label="Display name"
                        placeholder="Display name"
                        value={form.display_name}
                        onChange={(e) => setField('display_name', e.target.value)}
                      />
                    </div>
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
                        placeholder="IMAP host (e.g. mail.ultimate.co.tz)"
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
                        required={mode === 'create'}
                        autoComplete="new-password"
                        aria-label="IMAP password"
                        placeholder={
                          mode === 'edit'
                            ? 'Leave blank to keep current password'
                            : 'Mailbox password from cPanel'
                        }
                        value={form.imap_password || ''}
                        onChange={(e) => setField('imap_password', e.target.value)}
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
                        placeholder="SMTP host (e.g. mail.ultimate.co.tz)"
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
                  Save &amp; Continue
                </button>
              ) : (
                <button className="wizard-btn-next" type="submit" disabled={busy}>
                  {busy
                    ? 'Connecting…'
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
          <p>Register sales@ultimate.co.tz with full IMAP/SMTP settings to send and receive.</p>
          <button type="button" className="settings-primary" onClick={() => openCreate()}>
            Register sales@ultimate.co.tz
          </button>
        </div>
      ) : (
        <div className="account-cards">
          {accounts.map((a) => (
            <div key={a.id} className="account-card">
              <div>
                <strong>{a.display_name || a.email}</strong>
                <div className="muted">{a.email}</div>
                <div className="muted">
                  IMAP {a.imap_host}:{a.imap_port} · SMTP {a.smtp_host}:{a.smtp_port}
                </div>
              </div>
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
          ))}
        </div>
      )}
    </div>
  );
}
