import PayrollDeskPage from './pages/PayrollDeskPage';
import SalariesDeskPage from './pages/SalariesDeskPage';
import SalaryEditPage from './pages/SalaryEditPage';
import ViewRunPage from './pages/ViewRunPage';

function resolvePage() {
  if (typeof window !== 'undefined' && window.__PAYROLL_PAGE__) {
    return String(window.__PAYROLL_PAGE__);
  }
  return 'dashboard';
}

export default function App() {
  const page = resolvePage();

  if (page === 'salaries') {
    return <SalariesDeskPage />;
  }

  if (page === 'salary-edit') {
    return <SalaryEditPage />;
  }

  if (page === 'view-run') {
    return <ViewRunPage />;
  }

  return <PayrollDeskPage />;
}
