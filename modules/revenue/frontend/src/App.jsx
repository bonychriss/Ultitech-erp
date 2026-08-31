import RevenueDeskPage from './pages/RevenueDeskPage';
import RevenueCreatePage from './pages/RevenueCreatePage';
import RevenueImportPage from './pages/RevenueImportPage';

function resolvePage() {
  if (typeof window !== 'undefined' && window.__REVENUE_PAGE__) {
    return String(window.__REVENUE_PAGE__);
  }
  return 'list';
}

function App() {
  const page = resolvePage();
  if (page === 'create') {
    return <RevenueCreatePage />;
  }
  if (page === 'import') {
    return <RevenueImportPage />;
  }
  if (page === 'list') {
    return <RevenueDeskPage />;
  }
  return <RevenueDeskPage />;
}

export default App;
