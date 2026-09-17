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
function blankPreset(email = '', displayName = '', accountType = ''): AccountInput {
  const trimmed = email.trim();
  const domain = trimmed.includes('@') ? trimmed.split('@')[1] : '';
  const inferredType =
    accountType ||
    (trimmed.includes('@') ? trimmed.split('@')[0].toLowerCase().replace(/[^a-z0-9._-]/g, '') : '');
  const companyName = companyFromName(displayName) || companyNameFromEmail(trimmed);
  return {
    email: trimmed,
    display_name: companyName,
    account_type: inferredType,
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

const ACCOUNT_TYPE_OPTIONS = [
  'sales',
  'procurement',
  'manager',
  'accounts',
  'admin',
  'hr',
  'support',
  'info',
] as const;

function defaultMailDomain(preferredEmail = '', currentEmail = ''): string {
  for (const candidate of [currentEmail, preferredEmail]) {
    if (candidate.includes('@')) {
      return candidate.split('@')[1].toLowerCase();
    }
  }
  if (typeof window !== 'undefined') {
    const host = window.location.hostname.toLowerCase();
    if (host.includes('roadmasterspares.com')) return 'roadmasterspares.com';
    if (host.includes('ultimate.co.tz')) return 'ultimate.co.tz';
  }
  return '';
}

function emailForAccountType(type: string, preferredEmail: string, currentEmail: string): string {
  const cleaned = type.trim().toLowerCase().replace(/\s+/g, '');
  if (!cleaned || cleaned === 'other') return currentEmail;
  const domain = defaultMailDomain(preferredEmail, currentEmail);
  if (!domain) return currentEmail;
  return `${cleaned}@${domain}`;
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
  const type =
    (a.account_type || '').trim().toLowerCase() ||
    (a.email.includes('@') ? a.email.split('@')[0].toLowerCase() : '');
  return {
    email: a.email,
    display_name: display,
    account_type: type,
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
  isMailAdmin?: boolean;
  onBackToClaim?: () => void;
};

export function EmailSettings({
  onToast,
  onAccountsChanged,
  preferredEmail,
  preferredDisplayName,
  focusAccountId,
  requirePassword = false,
  onPasswordSaved,
  isMailAdmin = false,
  onBackToClaim,
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
  const [customAccountType, setCustomAccountType] = useState(false);

  function companyPreset() {
    return blankPreset(preferredEmail || '', preferredDisplayName || '');
  }

  function applyAccountType(nextType: string, useCustom = false) {
    const cleaned = nextType.trim().toLowerCase();
    setCustomAccountType(useCustom || (!!cleaned && !(ACCOUNT_TYPE_OPTIONS as readonly string[]).includes(cleaned)));
    setForm((prev) => {
      const nextEmail = emailForAccountType(cleaned, preferredEmail || '', prev.email);
      const applied = applyEmailDefaults(nextEmail || prev.email, {
        ...prev,
        account_type: cleaned,
      });
      const keepName =
        prev.display_name.trim() !== '' &&
        prev.display_name.trim() !== companyNameFromEmail(prev.email);
      return {
        ...applied,
        account_type: cleaned,
        display_name: keepName
          ? prev.display_name
          : companyNameFromEmail(applied.email) || prev.display_name,
      };
    });
  }

  async function load() {
    setLoading(true);
    try {
      const data = await api.accounts();
      setAccounts(data.accounts);
      if (isMailAdmin || teamPoolMode) {
        try {
          const poolData = await api.poolAccounts();
          setPool(poolData.mailboxes);
        } catch {
          setPool([]);
        }
      }
      if (data.accounts.length === 0 && !teamPoolMode && !focusAccountId) {
        // Staff without a mailbox use MailboxLogin; admin stays on list/pool.
        if (!isMailAdmin) {
          setMode('create');
          setStep(1);
          setForm(companyPreset());
          setSamePassword(true);
          setPasswordRequired(true);
          setHint('');
          setError('');
        } else {
          setMode('list');
        }
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
  }, [focusAccountId, requirePassword, teamPoolMode]);

  async function createTeamMailbox(e: FormEvent) {
    e.preventDefault();
    const email = poolEmail.trim().toLowerCase();
    if (!email || !poolPassword) {
      setError('Enter the mailbox email and password.');
      return;
    }
    setPoolBusy(true);
    setError('');
    try {
      const res = await api.createPoolAccount({
        email,
        password: poolPassword,
        display_name: companyNameFromEmail(email),
      });
      onToast(res.message || 'Team mailbox created');
      setPoolEmail('');
      setPoolPassword('');
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not create mailbox');
    } finally {
      setPoolBusy(false);
    }
  }

  async function removePoolMailbox(m: PoolMailbox) {
    if (!window.confirm(`Remove team mailbox ${m.email}?`)) return;
    try {
      const res = await api.deletePoolAccount(m.id);
      onToast(res.message || 'Removed');
      await load();
    } catch (err) {
      onToast(err instanceof Error ? err.message : 'Delete failed');
    }
  }

  function openCreate() {
    setMode('create');
    setStep(1);
    setEditingId(null);
    setForm(companyPreset());
    setCustomAccountType(false);
    setSamePassword(true);
    setPasswordRequired(true);
    setError('');
    setHint('');
  }

  function openEdit(a: AccountDetail) {
    const next = fromDetail(a);
    setMode('edit');
    setStep(1);
    setEditingId(a.id);
    setForm(next);
    setCustomAccountType(
      !!next.account_type &&
        !(ACCOUNT_TYPE_OPTIONS as readonly string[]).includes(next.account_type.toLowerCase()),
    );
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
      if (!form.account_type?.trim()) return 'Choose or enter an account type (e.g. sales, procurement).';
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
                    Account type<span className="req">*</span>
                  </h2>
                  <p>What this mailbox is for — sales, procurement, manager, and so on.</p>
                </div>
                <div className="wizard-fields">
                  <div className="wizard-auth-fields">
                    <div className="field-line">
                      <MdTag size={18} aria-hidden />
                      <select
                        required
                        aria-label="Account type"
                        value={
                          customAccountType ||
                          !(ACCOUNT_TYPE_OPTIONS as readonly string[]).includes(
                            (form.account_type || '').toLowerCase(),
                          )
                            ? 'other'
                            : (form.account_type || '').toLowerCase()
                        }
                        onChange={(e) => {
                          const value = e.target.value;
                          if (value === 'other') {
                            setCustomAccountType(true);
                            setField('account_type', '');
                            return;
                          }
                          applyAccountType(value, false);
                        }}
                      >
                        <option value="" disabled>
                          Select account type
                        </option>
                        {ACCOUNT_TYPE_OPTIONS.map((opt) => (
                          <option key={opt} value={opt}>
                            {opt.charAt(0).toUpperCase() + opt.slice(1)}
                          </option>
                        ))}
                        <option value="other">Other...</option>
                      </select>
                    </div>
                    {customAccountType ||
                    (!(ACCOUNT_TYPE_OPTIONS as readonly string[]).includes(
                      (form.account_type || '').toLowerCase(),
                    ) &&
                      (form.account_type || '') !== '') ? (
                      <div className="field-line">
                        <MdTag size={18} aria-hidden />
                        <input
                          required
                          autoFocus
                          aria-label="Custom account type"
                          placeholder="e.g. logistics, warehouse"
                          value={form.account_type || ''}
                          onChange={(e) => applyAccountType(e.target.value, true)}
                        />
                      </div>
                    ) : null}
                    <p className="field-hint-inline">
                      Choosing a type suggests the matching mailbox address when possible.
                    </p>
                  </div>
                </div>
              </div>
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
                            const local = nextEmail.includes('@')
                              ? nextEmail.split('@')[0].toLowerCase()
                              : '';
                            const keepType =
                              !!prev.account_type &&
                              !(ACCOUNT_TYPE_OPTIONS as readonly string[]).includes(local);
                            return {
                              ...applied,
                              account_type: keepType ? prev.account_type : local || prev.account_type,
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
        <h1>{teamPoolMode ? 'Team mailboxes' : 'Email accounts'}</h1>
        <div className="settings-head-actions">
          {onBackToClaim ? (
            <button type="button" className="tool" onClick={onBackToClaim}>
              Back to mailbox login
            </button>
          ) : null}
          {!teamPoolMode ? (
            <button type="button" className="settings-primary" onClick={() => openCreate()}>
              <MdAdd size={18} aria-hidden />
              Register mailbox
            </button>
          ) : null}
        </div>
      </div>

      {error && mode === 'list' ? <div className="settings-error">{error}</div> : null}

      {isMailAdmin || teamPoolMode ? (
        <section className="team-pool">
          <h2>Available for staff login</h2>
          <p className="muted">
            Create a mailbox in StackCP first, then add it here. Staff open Mail, pick the address,
            and log in once with the password you send them.
          </p>
          <form className="team-pool-form" onSubmit={(e) => void createTeamMailbox(e)}>
            <div className="field-line">
              <MdEmail size={18} aria-hidden />
              <input
                type="email"
                required
                placeholder="procurement@roadmasterspares.com"
                value={poolEmail}
                onChange={(e) => setPoolEmail(e.target.value)}
              />
            </div>
            <div className="field-line">
              <MdLockOutline size={18} aria-hidden />
              <input
                type="password"
                required
                placeholder="Mailbox password"
                value={poolPassword}
                onChange={(e) => setPoolPassword(e.target.value)}
                autoComplete="new-password"
              />
            </div>
            <button type="submit" className="settings-primary" disabled={poolBusy}>
              {poolBusy ? 'Verifying…' : 'Add team mailbox'}
            </button>
          </form>
          {pool.length === 0 ? (
            <p className="muted">No unclaimed team mailboxes yet.</p>
          ) : (
            <div className="account-cards">
              {pool.map((m) => (
                <div key={m.id} className="account-card">
                  <strong className="account-card-name">{m.display_name || m.email}</strong>
                  <span className="account-card-email muted">{m.email}</span>
                  <span className="account-card-servers muted">Ready for staff login</span>
                  <div className="account-actions">
                    <button type="button" className="tool" onClick={() => void removePoolMailbox(m)}>
                      <MdDelete size={18} aria-hidden />
                      Remove
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </section>
      ) : null}

      {loading ? (
        <div className="empty">Loading…</div>
      ) : accounts.length === 0 ? (
        teamPoolMode ? null : (
          <div className="empty">
            <h2>No personal mailbox yet</h2>
            <p>Register your own mailbox, or log into a team mailbox from the login screen.</p>
            <button type="button" className="settings-primary" onClick={() => openCreate()}>
              Register mailbox
            </button>
          </div>
        )
      ) : (
        <>
          <h2 className="settings-subhead">Your connected mailbox</h2>
          <div className="account-cards">
            {accounts.map((a) => {
              const sameHost = a.imap_host === a.smtp_host;
              const servers = sameHost
                ? `${a.imap_host} · IMAP ${a.imap_port} · SMTP ${a.smtp_port}`
                : `IMAP ${a.imap_host}:${a.imap_port} · SMTP ${a.smtp_host}:${a.smtp_port}`;
              return (
                <div key={a.id} className="account-card">
                  <strong className="account-card-name">
                    {a.account_type
                      ? a.account_type.charAt(0).toUpperCase() + a.account_type.slice(1)
                      : a.display_name || a.email}
                  </strong>
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
        </>
      )}
    </div>
  );
}
