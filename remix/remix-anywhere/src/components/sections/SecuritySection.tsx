import { useState } from 'react';
import {
  Check,
  Copy,
  KeyRound,
  Monitor,
  Plus,
  RefreshCw,
  ShieldCheck,
  Smartphone,
  Eye,
  EyeOff,
} from 'lucide-react';
import { ApiTokenRecord, SecuritySettings } from '../../types/erpProfile';

interface SecuritySectionProps {
  security: SecuritySettings;
  tableDensity: 'compact' | 'comfortable';
  onUpdateSecurity: (patch: Partial<SecuritySettings>, auditAction?: string, auditTarget?: string) => void;
  onPasswordUpdated: () => void;
}

export default function SecuritySection({
  security,
  tableDensity,
  onUpdateSecurity,
  onPasswordUpdated,
}: SecuritySectionProps) {
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [passwordStatus, setPasswordStatus] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const [showBackupCodes, setShowBackupCodes] = useState(false);
  const [copiedBackup, setCopiedBackup] = useState(false);

  const [isCreatingToken, setIsCreatingToken] = useState(false);
  const [tokenName, setTokenName] = useState('');
  const [tokenScope, setTokenScope] = useState<ApiTokenRecord['scope']>('Read-Only Ledger');
  const [newlyGeneratedSecret, setNewlyGeneratedSecret] = useState<string | null>(null);
  const [copiedSecret, setCopiedSecret] = useState(false);

  const rules = [
    { label: 'At least 12 characters', met: newPassword.length >= 12 },
    { label: 'Contains uppercase and lowercase letters', met: /[A-Z]/.test(newPassword) && /[a-z]/.test(newPassword) },
    { label: 'Contains at least one number', met: /\d/.test(newPassword) },
    { label: 'Contains a symbol (!@#$%^&*)', met: /[^A-Za-z0-9]/.test(newPassword) },
  ];

  const allRulesMet = rules.every((r) => r.met);
  const passwordsMatch = newPassword.length > 0 && newPassword === confirmPassword;

  const handlePasswordSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!currentPassword) {
      setPasswordStatus({ type: 'error', text: 'Enter your current password to authorize this change.' });
      return;
    }
    if (!allRulesMet) {
      setPasswordStatus({ type: 'error', text: 'New password does not meet all complexity requirements.' });
      return;
    }
    if (!passwordsMatch) {
      setPasswordStatus({ type: 'error', text: 'Confirmation password does not match.' });
      return;
    }
    setCurrentPassword('');
    setNewPassword('');
    setConfirmPassword('');
    setPasswordStatus({ type: 'success', text: 'Account password rotated successfully.' });
    onPasswordUpdated();
  };

  const handleRegenerateBackupCodes = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const nextCodes = Array.from({ length: 6 }, () => {
      const seg = (len: number) =>
        Array.from({ length: len }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
      return `NW${seg(2)}-${seg(4)}-${seg(2)}`;
    });
    onUpdateSecurity({ backupCodes: nextCodes }, 'Regenerated MFA recovery backup codes', 'MFA Recovery');
    setShowBackupCodes(true);
  };

  const handleCopyBackupCodes = () => {
    navigator.clipboard?.writeText(security.backupCodes.join('\n')).catch(() => {});
    setCopiedBackup(true);
    setTimeout(() => setCopiedBackup(false), 2000);
  };

  const handleRevokeSession = (id: string, device: string) => {
    onUpdateSecurity(
      { sessions: security.sessions.filter((s) => s.id !== id) },
      `Revoked active session (${device})`,
      'Session Management'
    );
  };

  const handleRevokeAllOtherSessions = () => {
    const othersCount = security.sessions.filter((s) => !s.isCurrent).length;
    if (othersCount === 0) return;
    onUpdateSecurity(
      { sessions: security.sessions.filter((s) => s.isCurrent) },
      `Terminated ${othersCount} remote ERP session(s)`,
      'Session Management'
    );
  };

  const handleCreateToken = (e: React.FormEvent) => {
    e.preventDefault();
    if (!tokenName.trim()) return;
    const randHex = Math.random().toString(16).slice(2, 6);
    const fullSecret = `mer_live_${randHex}_${Math.random().toString(36).slice(2, 14)}${Math.random().toString(36).slice(2, 10)}`;
    const today = new Date();
    const nextYear = new Date(today);
    nextYear.setFullYear(today.getFullYear() + 1);

    const newRecord: ApiTokenRecord = {
      id: `tok-${Date.now()}`,
      name: tokenName.trim(),
      prefix: `mer_live_${randHex}`,
      scope: tokenScope,
      createdAt: today.toISOString().slice(0, 10),
      expiresAt: nextYear.toISOString().slice(0, 10),
      lastUsed: 'Never used',
    };

    onUpdateSecurity(
      { apiTokens: [newRecord, ...security.apiTokens] },
      `Issued scoped API token (${newRecord.prefix})`,
      newRecord.name
    );
    setNewlyGeneratedSecret(fullSecret);
    setTokenName('');
    setIsCreatingToken(false);
  };

  const handleRevokeToken = (token: ApiTokenRecord) => {
    onUpdateSecurity(
      { apiTokens: security.apiTokens.filter((t) => t.id !== token.id) },
      `Revoked API token (${token.prefix})`,
      token.name
    );
  };

  const rowHeightClass = tableDensity === 'compact' ? 'py-2.5' : 'py-3.5';

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
          Security &amp; Access Control
        </h1>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Protect ledger access with multi-factor authentication, session governance, and scoped integration tokens.
        </p>
      </div>

      {/* Password Rotation */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
          <div className="lg:col-span-4">
            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
              Password &amp; Single Sign-On
            </h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
              Corporate SSO is active for web sign-in. Local fallback passwords require 90-day rotation for CLI and emergency access.
            </p>
            <div className="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">
              <ShieldCheck className="w-4 h-4" />
              <span>Okta SAML 2.0 SSO Enforced</span>
            </div>
          </div>

          <form onSubmit={handlePasswordSubmit} className="lg:col-span-8 space-y-4">
            <div>
              <label
                htmlFor="cur-pw"
                className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
              >
                Current Password
              </label>
              <input
                id="cur-pw"
                type="password"
                autoComplete="current-password"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                className="w-full max-w-md px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
              />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
              <div>
                <label
                  htmlFor="new-pw"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  New Password
                </label>
                <input
                  id="new-pw"
                  type="password"
                  autoComplete="new-password"
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                />
              </div>

              <div>
                <label
                  htmlFor="conf-pw"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Confirm New Password
                </label>
                <input
                  id="conf-pw"
                  type="password"
                  autoComplete="new-password"
                  value={confirmPassword}
                  onChange={(e) => setConfirmPassword(e.target.value)}
                  className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                />
              </div>
            </div>

            <ul className="grid grid-cols-1 sm:grid-cols-2 gap-1.5 pt-1 text-xs">
              {rules.map((r) => (
                <li
                  key={r.label}
                  className={`flex items-center gap-1.5 ${
                    r.met ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400'
                  }`}
                >
                  <Check className={`w-3.5 h-3.5 ${r.met ? 'opacity-100' : 'opacity-30'}`} />
                  <span>{r.label}</span>
                </li>
              ))}
            </ul>

            {passwordStatus && (
              <p
                className={`text-sm font-medium ${
                  passwordStatus.type === 'success'
                    ? 'text-emerald-700 dark:text-emerald-400'
                    : 'text-red-600 dark:text-red-400'
                }`}
              >
                {passwordStatus.text}
              </p>
            )}

            <div className="pt-1">
              <button
                type="submit"
                className="px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 hover:bg-slate-800 dark:hover:bg-slate-200 transition-colors cursor-pointer"
              >
                Update Password
              </button>
            </div>
          </form>
        </div>
      </section>

      {/* Multi-Factor Authentication */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
          <div className="lg:col-span-4">
            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
              Multi-Factor Authentication
            </h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
              Required for approving purchase orders above $50,000 or exporting vendor banking records.
            </p>
          </div>

          <div className="lg:col-span-8 divide-y divide-slate-200 dark:divide-slate-800">
            <div className="pb-4 flex items-center justify-between gap-4">
              <div>
                <div className="text-sm font-medium text-slate-900 dark:text-slate-100">
                  Authenticator Application (TOTP)
                </div>
                <div className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                  6-digit rotating verification codes via corporate authenticator.
                </div>
              </div>
              <button
                type="button"
                role="switch"
                aria-checked={security.mfaAuthenticator}
                onClick={() =>
                  onUpdateSecurity(
                    { mfaAuthenticator: !security.mfaAuthenticator },
                    `${!security.mfaAuthenticator ? 'Enabled' : 'Disabled'} TOTP Authenticator`,
                    'MFA Settings'
                  )
                }
                className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-150 ${
                  security.mfaAuthenticator ? 'bg-blue-700 dark:bg-blue-600' : 'bg-slate-300 dark:bg-slate-700'
                }`}
              >
                <span
                  className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-150 ${
                    security.mfaAuthenticator ? 'translate-x-5' : 'translate-x-0'
                  }`}
                />
              </button>
            </div>

            <div className="py-4 flex items-center justify-between gap-4">
              <div>
                <div className="text-sm font-medium text-slate-900 dark:text-slate-100">
                  Hardware Security Key (FIDO2 / WebAuthn)
                </div>
                <div className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                  YubiKey 5C NFC enrolled for passwordless high-value approvals.
                </div>
              </div>
              <button
                type="button"
                role="switch"
                aria-checked={security.mfaHardwareKey}
                onClick={() =>
                  onUpdateSecurity(
                    { mfaHardwareKey: !security.mfaHardwareKey },
                    `${!security.mfaHardwareKey ? 'Enabled' : 'Disabled'} Hardware Security Key`,
                    'MFA Settings'
                  )
                }
                className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-150 ${
                  security.mfaHardwareKey ? 'bg-blue-700 dark:bg-blue-600' : 'bg-slate-300 dark:bg-slate-700'
                }`}
              >
                <span
                  className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-150 ${
                    security.mfaHardwareKey ? 'translate-x-5' : 'translate-x-0'
                  }`}
                />
              </button>
            </div>

            <div className="py-4 flex items-center justify-between gap-4">
              <div>
                <div className="text-sm font-medium text-slate-900 dark:text-slate-100">
                  SMS Recovery Fallback
                </div>
                <div className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                  Not recommended by security policy unless traveling without hardware token.
                </div>
              </div>
              <button
                type="button"
                role="switch"
                aria-checked={security.mfaSmsFallback}
                onClick={() =>
                  onUpdateSecurity(
                    { mfaSmsFallback: !security.mfaSmsFallback },
                    `${!security.mfaSmsFallback ? 'Enabled' : 'Disabled'} SMS MFA Fallback`,
                    'MFA Settings'
                  )
                }
                className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-150 ${
                  security.mfaSmsFallback ? 'bg-blue-700 dark:bg-blue-600' : 'bg-slate-300 dark:bg-slate-700'
                }`}
              >
                <span
                  className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-150 ${
                    security.mfaSmsFallback ? 'translate-x-5' : 'translate-x-0'
                  }`}
                />
              </button>
            </div>

            {/* Backup Codes Block */}
            <div className="pt-5">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <div className="text-sm font-medium text-slate-900 dark:text-slate-100">
                    Single-Use Recovery Codes
                  </div>
                  <div className="text-xs text-slate-500 dark:text-slate-400">
                    Store offline in an encrypted password vault.
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setShowBackupCodes(!showBackupCodes)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer"
                  >
                    {showBackupCodes ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
                    <span>{showBackupCodes ? 'Hide Codes' : 'Reveal Codes'}</span>
                  </button>
                  <button
                    type="button"
                    onClick={handleRegenerateBackupCodes}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer"
                  >
                    <RefreshCw className="w-3.5 h-3.5" />
                    <span>Regenerate</span>
                  </button>
                </div>
              </div>

              {showBackupCodes && (
                <div className="mt-4 p-4 rounded-lg bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800">
                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 font-mono text-xs tabular-nums text-slate-800 dark:text-slate-200">
                    {security.backupCodes.map((code) => (
                      <div key={code} className="py-1">
                        {code}
                      </div>
                    ))}
                  </div>
                  <div className="mt-3 pt-3 border-t border-slate-200 dark:border-slate-800 flex justify-end">
                    <button
                      type="button"
                      onClick={handleCopyBackupCodes}
                      className="inline-flex items-center gap-1.5 text-xs font-medium text-blue-700 dark:text-blue-400 hover:underline cursor-pointer"
                    >
                      {copiedBackup ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                      <span>{copiedBackup ? 'Copied to clipboard' : 'Copy all codes'}</span>
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      </section>

      {/* Active Sessions Table */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden">
        <div className="p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 dark:border-slate-800">
          <div>
            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
              Active ERP Sessions
            </h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
              Devices currently authenticated with your Meridian ERP account.
            </p>
          </div>
          {security.sessions.some((s) => !s.isCurrent) && (
            <button
              type="button"
              onClick={handleRevokeAllOtherSessions}
              className="px-4 py-2 text-xs font-medium rounded-lg border border-red-200 dark:border-red-900/60 text-red-700 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors whitespace-nowrap shrink-0 cursor-pointer"
            >
              Sign Out Other Sessions
            </button>
          )}
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="border-b border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/50 text-xs font-medium text-slate-500 dark:text-slate-400">
                <th className="py-2.5 pl-6 pr-4">Device &amp; Client</th>
                <th className="py-2.5 px-4">Location &amp; IP</th>
                <th className="py-2.5 px-4">Last Activity</th>
                <th className="py-2.5 pl-4 pr-6 text-right">Session</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200 dark:divide-slate-800 text-sm">
              {security.sessions.map((session) => (
                <tr key={session.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors">
                  <td className={`${rowHeightClass} pl-6 pr-4`}>
                    <div className="flex items-center gap-3">
                      {session.deviceType === 'mobile' ? (
                        <Smartphone className="w-4 h-4 text-slate-400 shrink-0" />
                      ) : (
                        <Monitor className="w-4 h-4 text-slate-400 shrink-0" />
                      )}
                      <div>
                        <div className="font-medium text-slate-900 dark:text-slate-100">
                          {session.device}
                        </div>
                        <div className="text-xs text-slate-500 dark:text-slate-400">
                          {session.browser}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td className={`${rowHeightClass} px-4`}>
                    <div className="text-slate-700 dark:text-slate-300">{session.location}</div>
                    <div className="text-xs font-mono tabular-nums text-slate-500 dark:text-slate-400">
                      {session.ipAddress}
                    </div>
                  </td>
                  <td className={`${rowHeightClass} px-4 font-mono text-xs tabular-nums text-slate-600 dark:text-slate-400 whitespace-nowrap`}>
                    {session.lastActive}
                  </td>
                  <td className={`${rowHeightClass} pl-4 pr-6 text-right whitespace-nowrap`}>
                    {session.isCurrent ? (
                      <span className="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                        Current Session
                      </span>
                    ) : (
                      <button
                        type="button"
                        onClick={() => handleRevokeSession(session.id, session.device)}
                        className="text-xs font-medium text-red-700 dark:text-red-400 hover:underline cursor-pointer"
                      >
                        Revoke
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {/* Personal API Tokens */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden">
        <div className="p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 dark:border-slate-800">
          <div>
            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
              Personal API Tokens
            </h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
              Scoped bearer credentials for automated procurement scripts and BI ledger extracts.
            </p>
          </div>
          <button
            type="button"
            onClick={() => setIsCreatingToken(!isCreatingToken)}
            className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors whitespace-nowrap shrink-0 cursor-pointer"
          >
            <Plus className="w-4 h-4" />
            <span>Issue New Token</span>
          </button>
        </div>

        {newlyGeneratedSecret && (
          <div className="p-4 mx-6 mt-5 rounded-lg border border-emerald-200 dark:border-emerald-900/60 bg-emerald-50/70 dark:bg-emerald-950/30 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div className="min-w-0">
              <div className="text-xs font-medium text-emerald-900 dark:text-emerald-200">
                Token created — copy this secret now, as it will not be shown again:
              </div>
              <div className="mt-1 font-mono text-xs text-emerald-950 dark:text-emerald-100 break-all">
                {newlyGeneratedSecret}
              </div>
            </div>
            <div className="flex items-center gap-3 shrink-0">
              <button
                type="button"
                onClick={() => {
                  navigator.clipboard?.writeText(newlyGeneratedSecret).catch(() => {});
                  setCopiedSecret(true);
                  setTimeout(() => setCopiedSecret(false), 2000);
                }}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md bg-emerald-700 text-white hover:bg-emerald-800 cursor-pointer"
              >
                {copiedSecret ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                <span>{copiedSecret ? 'Copied' : 'Copy Secret'}</span>
              </button>
              <button
                type="button"
                onClick={() => setNewlyGeneratedSecret(null)}
                className="text-xs font-medium text-emerald-800 dark:text-emerald-300 hover:underline cursor-pointer"
              >
                Dismiss
              </button>
            </div>
          </div>
        )}

        {isCreatingToken && (
          <form
            onSubmit={handleCreateToken}
            className="p-6 bg-slate-50/70 dark:bg-slate-950/50 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row items-end gap-4"
          >
            <div className="flex-1 w-full">
              <label
                htmlFor="tok-name"
                className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1.5"
              >
                Token Label
              </label>
              <input
                id="tok-name"
                type="text"
                required
                placeholder="e.g., Monthly Vendor Spend Sync"
                value={tokenName}
                onChange={(e) => setTokenName(e.target.value)}
                className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
              />
            </div>
            <div className="w-full sm:w-52">
              <label
                htmlFor="tok-scope"
                className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1.5"
              >
                Permission Scope
              </label>
              <select
                id="tok-scope"
                value={tokenScope}
                onChange={(e) => setTokenScope(e.target.value as ApiTokenRecord['scope'])}
                className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
              >
                <option value="Read-Only Ledger">Read-Only Ledger</option>
                <option value="Procurement & POs">Procurement &amp; POs</option>
                <option value="Inventory Sync">Inventory Sync</option>
              </select>
            </div>
            <div className="flex items-center gap-2 shrink-0">
              <button
                type="button"
                onClick={() => setIsCreatingToken(false)}
                className="px-3.5 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 cursor-pointer"
              >
                Cancel
              </button>
              <button
                type="submit"
                className="px-4 py-2 text-sm font-medium rounded-lg bg-blue-700 text-white hover:bg-blue-800 cursor-pointer whitespace-nowrap"
              >
                Generate Token
              </button>
            </div>
          </form>
        )}

        {security.apiTokens.length === 0 ? (
          <div className="p-8 text-center">
            <KeyRound className="w-6 h-6 text-slate-400 mx-auto" />
            <p className="mt-2 text-sm font-medium text-slate-900 dark:text-slate-100">
              No active personal API tokens
            </p>
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
              Issue a scoped token above when connecting external scripts or BI pipelines.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="border-b border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/50 text-xs font-medium text-slate-500 dark:text-slate-400">
                  <th className="py-2.5 pl-6 pr-4">Token Name</th>
                  <th className="py-2.5 px-4">Prefix &amp; Scope</th>
                  <th className="py-2.5 px-4">Expires</th>
                  <th className="py-2.5 px-4">Last Used</th>
                  <th className="py-2.5 pl-4 pr-6 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 dark:divide-slate-800 text-sm">
                {security.apiTokens.map((token) => (
                  <tr key={token.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors">
                    <td className={`${rowHeightClass} pl-6 pr-4 font-medium text-slate-900 dark:text-slate-100`}>
                      {token.name}
                    </td>
                    <td className={`${rowHeightClass} px-4`}>
                      <span className="font-mono text-xs tabular-nums text-slate-700 dark:text-slate-300">
                        {token.prefix}...
                      </span>
                      <span className="text-slate-400 mx-1.5" aria-hidden="true">·</span>
                      <span className="text-xs text-slate-600 dark:text-slate-400">{token.scope}</span>
                    </td>
                    <td className={`${rowHeightClass} px-4 font-mono text-xs tabular-nums text-slate-600 dark:text-slate-400 whitespace-nowrap`}>
                      {token.expiresAt}
                    </td>
                    <td className={`${rowHeightClass} px-4 font-mono text-xs tabular-nums text-slate-600 dark:text-slate-400 whitespace-nowrap`}>
                      {token.lastUsed}
                    </td>
                    <td className={`${rowHeightClass} pl-4 pr-6 text-right whitespace-nowrap`}>
                      <button
                        type="button"
                        onClick={() => handleRevokeToken(token)}
                        className="text-xs font-medium text-red-700 dark:text-red-400 hover:underline cursor-pointer"
                      >
                        Revoke
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
