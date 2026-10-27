import { useEffect, useState } from 'react';
import DashboardPage from './pages/DashboardPage';
import LiquidityAccountsPage from './pages/LiquidityAccountsPage';
import AccountMonthsPage from './pages/AccountMonthsPage';
import MonthTransactionsPage from './pages/MonthTransactionsPage';
import TransactionDetailPage from './pages/TransactionDetailPage';
import { navigateLdView, readLdView } from './nav';
import type { LdView } from './types';

function fallbackUnderTransaction(view: Extract<LdView, { name: 'transaction' }>): LdView {
  if (view.ym) return { name: 'month', accountId: view.accountId, ym: view.ym };
  if (view.accountId > 0) return { name: 'account', accountId: view.accountId };
  return { name: 'dashboard' };
}

function renderView(view: LdView) {
  if (view.name === 'liquidity') return <LiquidityAccountsPage bucket={view.bucket ?? null} />;
  if (view.name === 'account') return <AccountMonthsPage accountId={view.accountId} />;
  if (view.name === 'month') return <MonthTransactionsPage accountId={view.accountId} ym={view.ym} />;
  return <DashboardPage />;
}

export default function App() {
  const initial = readLdView();
  const [view, setView] = useState<LdView>(initial);
  const [baseView, setBaseView] = useState<LdView>(() =>
    initial.name === 'transaction' ? fallbackUnderTransaction(initial) : initial,
  );

  useEffect(() => {
    const onPop = () => {
      const next = readLdView();
      setView(next);
      if (next.name !== 'transaction') {
        setBaseView(next);
      }
    };
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);

  return (
    <>
      {renderView(baseView)}
      {view.name === 'transaction' ? (
        <TransactionDetailPage
          accountId={view.accountId}
          ym={view.ym}
          txId={view.txId}
          onClose={() => navigateLdView(baseView, true)}
        />
      ) : null}
    </>
  );
}
