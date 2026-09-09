import ComposeLetterPage from './pages/ComposeLetterPage.jsx';
import StampEditorPage from './pages/StampEditorPage.jsx';

function resolvePage() {
  if (typeof window !== 'undefined' && window.__LETTER_PAGE__) {
    return String(window.__LETTER_PAGE__);
  }
  return 'compose';
}

export default function App() {
  const page = resolvePage();
  if (page === 'stamp') {
    return <StampEditorPage />;
  }
  return <ComposeLetterPage />;
}
