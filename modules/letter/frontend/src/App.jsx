import ComposeLetterPage from './pages/ComposeLetterPage.jsx';
import LettersListPage from './pages/LettersListPage.jsx';
import StampEditorPage from './pages/StampEditorPage.jsx';

function resolvePage() {
  if (typeof window !== 'undefined' && window.__LETTER_PAGE__) {
    return String(window.__LETTER_PAGE__);
  }
  return 'list';
}

export default function App() {
  const page = resolvePage();
  if (page === 'stamp') {
    return <StampEditorPage />;
  }
  if (page === 'compose') {
    return <ComposeLetterPage />;
  }
  return <LettersListPage />;
}
