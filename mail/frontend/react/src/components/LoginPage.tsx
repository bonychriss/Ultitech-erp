import { useState, type FormEvent } from 'react';
import {
  MdLockOutline,
  MdPersonOutline,
  MdVisibility,
  MdVisibilityOff,
} from 'react-icons/md';
import { api, type Bootstrap } from '../api';

type Props = {
  bootstrap: Bootstrap;
  onLoggedIn: (data: Bootstrap) => void;
};

/** Login hero must follow live host path (/mail vs /staff/mail), not Vite build base. */
function authHeroSrc(): string {
  const injected = typeof window !== 'undefined' ? window.__MAIL_WEB_BASE__ : undefined;
  if (injected) {
    return `${injected.replace(/\/$/, '')}/app/auth-hero.png`;
  }
  const viteBase = (import.meta.env.BASE_URL || '/').replace(/\/?$/, '');
  if (viteBase.endsWith('/app')) {
    return `${viteBase}/auth-hero.png`;
  }
  return `${viteBase}/app/auth-hero.png`;
}

function detectCompany(): 'roadmaster' | 'ultimate' | null {
  if (typeof window === 'undefined') return null;
  const host = window.location.hostname.toLowerCase();
  if (host.includes('roadmasterspares.com')) return 'roadmaster';
  if (host.includes('ultimate.co.tz')) return 'ultimate';
  return null;
}

function ultitechSsoUrl(company: 'roadmaster' | 'ultimate'): string {
  const launch = `/mail-sso-launch.php?company=${encodeURIComponent(company)}`;
  return `https://ultitech.io/${company}/login?next=${encodeURIComponent(launch)}`;
}

export function LoginPage({ bootstrap: _bootstrap, onLoggedIn }: Props) {
  const company = detectCompany();
  const ssoOnly = company !== null;
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [rememberMe, setRememberMe] = useState(true);
  const [showPassword, setShowPassword] = useState(false);
  const [showStaffLogin, setShowStaffLogin] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function ensureCsrf() {
    await api.bootstrap();
  }

  async function onLogin(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      await ensureCsrf();
      const data = await api.login(username, password, rememberMe);
      onLoggedIn(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="login-page">
      <section className="login-art-pane">
        <div className="login-art">
          <img src={authHeroSrc()} alt="" />
        </div>
      </section>

      <section className="login-form-pane">
        <div className="login-form-inner">
          <h1>Mail</h1>

          {error ? <div className="error">{error}</div> : null}

          {ssoOnly ? (
            <>
              <p className="login-sso-lead">
                Sign in through Ultitech first. Mail opens automatically and stays signed in —
                you only need to register a mailbox once (IMAP/SMTP), not a new Mail app account
                each visit.
              </p>
              <a className="auth-submit auth-submit-link" href={ultitechSsoUrl(company)}>
                Continue with Ultitech
              </a>
              <p className="login-sso-hint">
                Already in Ultitech? Open <strong>Mail</strong> from the module picker instead of
                this page.
              </p>

              {!showStaffLogin ? (
                <button
                  type="button"
                  className="auth-switch"
                  onClick={() => setShowStaffLogin(true)}
                >
                  Admin password login
                </button>
              ) : (
                <form className="auth-form" onSubmit={(e) => void onLogin(e)}>
                  <div className="field-line">
                    <MdPersonOutline size={18} aria-hidden />
                    <input
                      value={username}
                      onChange={(e) => setUsername(e.target.value)}
                      placeholder="Username"
                      autoFocus
                      required
                      autoComplete="username"
                    />
                  </div>
                  <div className="field-line">
                    <MdLockOutline size={18} aria-hidden />
                    <input
                      type={showPassword ? 'text' : 'password'}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      placeholder="Password"
                      required
                      autoComplete="current-password"
                    />
                    <button
                      type="button"
                      className="field-eye"
                      aria-label={showPassword ? 'Hide password' : 'Show password'}
                      onClick={() => setShowPassword((v) => !v)}
                    >
                      {showPassword ? <MdVisibilityOff size={18} /> : <MdVisibility size={18} />}
                    </button>
                  </div>
                  <label className="remember">
                    <input
                      type="checkbox"
                      checked={rememberMe}
                      onChange={(e) => setRememberMe(e.target.checked)}
                    />
                    Keep me signed in
                  </label>
                  <button className="auth-submit" type="submit" disabled={busy}>
                    {busy ? 'Signing in…' : 'Log in'}
                  </button>
                </form>
              )}
            </>
          ) : (
            <form className="auth-form" onSubmit={(e) => void onLogin(e)}>
              <div className="field-line">
                <MdPersonOutline size={18} aria-hidden />
                <input
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  placeholder="Username"
                  autoFocus
                  required
                  autoComplete="username"
                />
              </div>
              <div className="field-line">
                <MdLockOutline size={18} aria-hidden />
                <input
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Password"
                  required
                  autoComplete="current-password"
                />
                <button
                  type="button"
                  className="field-eye"
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  onClick={() => setShowPassword((v) => !v)}
                >
                  {showPassword ? <MdVisibilityOff size={18} /> : <MdVisibility size={18} />}
                </button>
              </div>
              <label className="remember">
                <input
                  type="checkbox"
                  checked={rememberMe}
                  onChange={(e) => setRememberMe(e.target.checked)}
                />
                Remember me
              </label>
              <button className="auth-submit" type="submit" disabled={busy}>
                {busy ? 'Signing in…' : 'Log in'}
              </button>
            </form>
          )}
        </div>
      </section>
    </div>
  );
}
