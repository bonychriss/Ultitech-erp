import DashboardPage from './pages/DashboardPage.jsx';
import StatisticsPage from './pages/StatisticsPage.jsx';

export default function App() {
  const url = new URL(window.location.href);
  const isStatisticsDesk = url.searchParams.get('desk') === 'statistics'
    || /\/sales\/statistics\/?$/.test(url.pathname);
  if (isStatisticsDesk) return <StatisticsPage />;

  return <DashboardPage />;
}
