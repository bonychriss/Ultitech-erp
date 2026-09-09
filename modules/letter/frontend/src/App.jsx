import { useEffect } from 'react';
import ComposeLetterPage from './pages/ComposeLetterPage.jsx';
import LetterInboxPage from './pages/LetterInboxPage.jsx';
import LettersListPage from './pages/LettersListPage.jsx';
import StampEditorPage from './pages/StampEditorPage.jsx';
import { syncLetterInboxNavDot } from './utils/letterStore.js';

function resolvePage() {
  if (typeof window !== 'undefined' && window.__LETTER_PAGE__) {
    return String(window.__LETTER_PAGE__);
  }
  return 'list';
}

export default function App() {
  useEffect(() => {
    syncLetterInboxNavDot();
  }, []);

  const page = resolvePage();
  if (page === 'stamp') {
    return <StampEditorPage />;
  }
  if (page === 'compose') {
    return <ComposeLetterPage />;
  }
  if (page === 'inbox') {
    return <LetterInboxPage />;
  }
  return <LettersListPage />;
}
