import { useEffect, useState } from 'react';
import DashboardPage from './pages/DashboardPage';
import CategoryYearsPage from './pages/CategoryYearsPage';
import YearMonthsPage from './pages/YearMonthsPage';
import MonthDetailPage from './pages/MonthDetailPage';
import TransactionsPage from './pages/TransactionsPage';
import { readVatView } from './nav';
import type { VatView } from './types';

export default function App() {
  const [view, setView] = useState<VatView>(() => readVatView());

  useEffect(() => {
    const onPop = () => setView(readVatView());
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);

  if (view.name === 'category') return <CategoryYearsPage category={view.category} />;
  if (view.name === 'year') return <YearMonthsPage year={view.year} />;
  if (view.name === 'month') return <MonthDetailPage ym={view.ym} />;
  if (view.name === 'transactions') {
    return <TransactionsPage ym={view.ym} source={view.source} />;
  }
  return <DashboardPage />;
}
