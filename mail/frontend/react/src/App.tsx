import { useCallback, useEffect, useState } from 'react';
import { api, type Bootstrap } from './api';
import { LoadingMail } from './components/LoadingMail';
import { LoginPage } from './components/LoginPage';
import { MailApp } from './components/MailApp';
import { SuccessOverlay } from './components/SuccessOverlay';
import './index.css';

const MIN_LOADING_MS =
  typeof window !== 'undefined' && /localhost|127\.0\.0\.1/.test(window.location.hostname)
    ? 400
    : 900;

export default function App() {
  const [boot, setBoot] = useState<Bootstrap | null>(null);
  const [error, setError] = useState('');
  const [ready, setReady] = useState(false);
  const [showSuccess, setShowSuccess] = useState(false);

  useEffect(() => {
    const started = Date.now();
    api
      .bootstrap()
      .then((data) => {
        const wait = Math.max(0, MIN_LOADING_MS - (Date.now() - started));
        window.setTimeout(() => {
          setBoot(data);
          setReady(true);
          if (data.authenticated && data.message) {
            setShowSuccess(true);
          }
        }, wait);
      })
      .catch((err) => {
        setError(err instanceof Error ? err.message : 'Failed to start');
        setReady(true);
      });
  }, []);

  const handleLoggedIn = useCallback((data: Bootstrap) => {
    setBoot(data);
    setShowSuccess(true);
  }, []);

  const dismissSuccess = useCallback(() => setShowSuccess(false), []);

  if (!ready || (!boot && !error)) {
    return <LoadingMail />;
  }

  if (error) {
    return <LoadingMail label={error} />;
  }

  if (!boot) {
    return <LoadingMail />;
  }

  if (!boot.authenticated || !boot.user) {
    return (
      <>
        <LoginPage bootstrap={boot} onLoggedIn={handleLoggedIn} />
        {showSuccess ? <SuccessOverlay onDone={dismissSuccess} /> : null}
      </>
    );
  }

  return (
    <>
      <MailApp
        user={boot.user}
        account={boot.account}
        initialFolders={boot.folders || []}
        welcomeMessage=""
        onLogout={() =>
          setBoot({
            ...boot,
            authenticated: false,
            user: null,
            account: null,
            folders: [],
            message: undefined,
          })
        }
      />
      {showSuccess ? <SuccessOverlay onDone={dismissSuccess} /> : null}
    </>
  );
}
