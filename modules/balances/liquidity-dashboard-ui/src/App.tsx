import { useEffect, useState } from 'react';
import DashboardPage from './pages/DashboardPage';
import LiquidityAccountsPage from './pages/LiquidityAccountsPage';
import AccountMonthsPage from './pages/AccountMonthsPage';
import MonthTransactionsPage from './pages/MonthTransactionsPage';
import TransactionDetailPage from './pages/TransactionDetailPage';
import { readLdView } from './nav';
import type { LdView } from './types';

export default function App() {
  const [view, setView] = useState<LdView>(() => readLdView());

  useEffect(() => {
    const onPop = () => setView(readLdView());
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);

  if (view.name === 'liquidity') return <LiquidityAccountsPage bucket={view.bucket ?? null} />;
  if (view.name === 'account') return <AccountMonthsPage accountId={view.accountId} />;
  if (view.name === 'month') return <MonthTransactionsPage accountId={view.accountId} ym={view.ym} />;
  if (view.name === 'transaction') {
    return <TransactionDetailPage accountId={view.accountId} ym={view.ym} txId={view.txId} />;
  }
  return <DashboardPage />;
}
