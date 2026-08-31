import ExpensesDeskPage from './pages/ExpensesDeskPage';
import ExpenseCreatePage from './pages/ExpenseCreatePage';
import ExpenseSmartInsightsPage from './pages/ExpenseSmartInsightsPage';
import ExpenseImportPage from './pages/ExpenseImportPage';

function resolvePage() {
  if (typeof window !== 'undefined' && window.__EXPENSES_PAGE__) {
    return String(window.__EXPENSES_PAGE__);
  }
  return 'list';
}

function App() {
  const page = resolvePage();
  if (page === 'create' || page === 'edit') {
    return <ExpenseCreatePage />;
  }
  if (page === 'insights') {
    return <ExpenseSmartInsightsPage />;
  }
  if (page === 'import') {
    return <ExpenseImportPage />;
  }
  return <ExpensesDeskPage />;
}

export default App;
