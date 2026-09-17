import { useState, type FormEvent } from 'react';
import {
  MdEmail,
  MdLockOutline,
  MdPersonOutline,
  MdVisibility,
  MdVisibilityOff,
} from 'react-icons/md';
import { FaFacebookF, FaGoogle, FaTwitter } from 'react-icons/fa';
import { api, type Bootstrap } from '../api';

type Props = {
  bootstrap: Bootstrap;
  onLoggedIn: (data: Bootstrap) => void;
};

type Mode = 'login' | 'register';

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

export function LoginPage({ bootstrap: _bootstrap, onLoggedIn }: Props) {
  const [mode, setMode] = useState<Mode>('login');
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [rememberMe, setRememberMe] = useState(true);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  function switchMode(next: Mode) {
    setMode(next);
    setError('');
    setShowPassword(false);
    setShowConfirm(false);
    setUsername('');
    setEmail('');
    setPassword('');
    setPasswordConfirm('');
  }

  async function ensureCsrf() {
    // Always refresh so cookie + token stay in sync on live hosting
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

  async function onRegister(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      if (password.length < 8) {
        throw new Error('Password must be at least 8 characters.');
      }
      if (password !== passwordConfirm) {
        throw new Error('Password confirmation does not match.');
      }
      await ensureCsrf();
      const data = await api.signup({
        username: username.trim(),
        email: email.trim(),
        password,
        password_confirm: passwordConfirm,
      });
      onLoggedIn(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Registration failed');
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
          <h1>{mode === 'login' ? 'Log in' : 'Sign up'}</h1>

          {error ? <div className="error">{error}</div> : null}

          {mode === 'login' ? (
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
          ) : (
            <form className="auth-form" onSubmit={(e) => void onRegister(e)}>
              <div className="field-line">
                <MdPersonOutline size={18} aria-hidden />
                <input
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  placeholder="Your Name"
                  autoFocus
                  required
                  minLength={2}
                  autoComplete="username"
                />
              </div>
              <div className="field-line">
                <MdEmail size={18} aria-hidden />
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="Company email"
                  required
                  autoComplete="email"
                />
              </div>
              <p className="field-hint-inline">
                Company mailbox used to send and receive mail (not a personal Gmail).
              </p>
              <div className="field-line">
                <MdLockOutline size={18} aria-hidden />
                <input
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Password"
                  required
                  minLength={8}
                  autoComplete="new-password"
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
              <div className="field-line">
                <MdLockOutline size={18} aria-hidden />
                <input
                  type={showConfirm ? 'text' : 'password'}
                  value={passwordConfirm}
                  onChange={(e) => setPasswordConfirm(e.target.value)}
                  placeholder="Confirm password"
                  required
                  minLength={8}
                  autoComplete="new-password"
                />
                <button
                  type="button"
                  className="field-eye"
                  aria-label={showConfirm ? 'Hide password' : 'Show password'}
                  onClick={() => setShowConfirm((v) => !v)}
                >
                  {showConfirm ? <MdVisibilityOff size={18} /> : <MdVisibility size={18} />}
                </button>
              </div>

              <button className="auth-submit" type="submit" disabled={busy}>
                {busy ? 'Creating account…' : 'Sign up'}
              </button>
            </form>
          )}

          <div className="social-block">
            <p>Or {mode === 'login' ? 'login' : 'continue'} with</p>
            <div className="social-row">
              <button type="button" className="social fb" title="Facebook" aria-label="Facebook">
                <FaFacebookF size={14} />
              </button>
              <button type="button" className="social tw" title="Twitter" aria-label="Twitter">
                <FaTwitter size={14} />
              </button>
              <button type="button" className="social go" title="Google" aria-label="Google">
                <FaGoogle size={14} />
              </button>
            </div>
            <button
              type="button"
              className="auth-switch"
              onClick={() => switchMode(mode === 'login' ? 'register' : 'login')}
            >
              {mode === 'login' ? 'Create an account' : 'Already have an account? Log in'}
            </button>
          </div>
        </div>
      </section>
    </div>
  );
}
