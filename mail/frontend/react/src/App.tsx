import { useEffect, useState } from 'react';
import { api, type Bootstrap } from './api';
import { LoginPage } from './components/LoginPage';
import { MailApp } from './components/MailApp';
import './index.css';

export default function App() {
  const [boot, setBoot] = useState<Bootstrap | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api
      .bootstrap()
      .then(setBoot)
      .catch((err) => setError(err instanceof Error ? err.message : 'Failed to start'));
  }, []);

  if (error) {
    return <div className="loading">{error}</div>;
  }

  if (!boot) {
    return <div className="loading">Loading Mail…</div>;
  }

  if (!boot.authenticated || !boot.user) {
    return (
      <LoginPage
        bootstrap={boot}
        onLoggedIn={(data) => setBoot(data)}
      />
    );
  }

  return (
    <MailApp
      user={boot.user}
      account={boot.account}
      initialFolders={boot.folders || []}
      onLogout={() =>
        setBoot({
          ...boot,
          authenticated: false,
          user: null,
          account: null,
          folders: [],
        })
      }
    />
  );
}
