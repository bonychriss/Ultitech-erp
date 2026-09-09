import ComposeLetterPage from './pages/ComposeLetterPage.jsx';

function resolvePage() {
  if (typeof window !== 'undefined' && window.__LETTER_PAGE__) {
    return String(window.__LETTER_PAGE__);
  }
  return 'compose';
}

export default function App() {
  const page = resolvePage();
  if (page === 'compose') {
    return <ComposeLetterPage />;
  }
  return <ComposeLetterPage />;
}
