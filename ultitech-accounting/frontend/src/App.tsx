import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, HashRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { AuthProvider, useAuth } from './lib/auth'
import { readBoot } from './lib/boot'
import { AccountsPage } from './pages/AccountsPage'
import { AdvancedAccountingPage } from './pages/AdvancedAccountingPage'
import { AssetsPage } from './pages/AssetsPage'
import { BankingPage } from './pages/BankingPage'
import { BankingMovementsPage } from './pages/BankingMovementsPage'
import { BudgetsPage } from './pages/BudgetsPage'
import { DashboardPage } from './pages/DashboardPage'
import { ErpHandoffPage } from './pages/ErpHandoffPage'
import { ErpIntegrationPage } from './pages/ErpIntegrationPage'
import { FinancialStatementsPage } from './pages/FinancialStatementsPage'
import { GovernancePage } from './pages/GovernancePage'
import { InventoryPage } from './pages/InventoryPage'
import { JournalDetailPage } from './pages/JournalDetailPage'
import { JournalsPage } from './pages/JournalsPage'
import { LoginPage } from './pages/LoginPage'
import { ExpensesPage, PurchasesPage } from './pages/PurchasesPage'
import { ReconciliationPage } from './pages/ReconciliationPage'
import { RevenuePage } from './pages/RevenuePage'
import { SettingsPage } from './pages/SettingsPage'
import { StandardsPage } from './pages/StandardsPage'
import { TaxControlPage } from './pages/TaxControlPage'
import { TrialBalancePage } from './pages/TrialBalancePage'

const queryClient = new QueryClient()
const hostedInErp = Boolean(readBoot().hostedInErp)
const Router = hostedInErp ? HashRouter : BrowserRouter

function Protected({ children }: { children: React.ReactNode }) {
  const { token } = useAuth()
  if (!token) return <Navigate to={hostedInErp ? '/' : '/login'} replace />
  return children
}

function AppRoutes() {
  return (
    <Routes>
      {!hostedInErp ? <Route path="/login" element={<LoginPage />} /> : null}
      {!hostedInErp ? <Route path="/erp-handoff" element={<ErpHandoffPage />} /> : null}
      <Route
        path="/"
        element={
          <Protected>
            <AppShell />
          </Protected>
        }
      >
        <Route index element={<DashboardPage />} />
        <Route path="revenue" element={<RevenuePage />} />
        <Route path="expenses" element={<ExpensesPage />} />
        <Route path="purchases" element={<PurchasesPage />} />
        <Route path="banking" element={<BankingPage />} />
        <Route path="banking/movements" element={<BankingMovementsPage />} />
        <Route path="reconciliation" element={<ReconciliationPage />} />
        <Route path="assets" element={<AssetsPage />} />
        <Route path="inventory" element={<InventoryPage />} />
        <Route path="accounts" element={<AccountsPage />} />
        <Route path="journals" element={<JournalsPage />} />
        <Route path="journals/:id" element={<JournalDetailPage />} />
        <Route path="trial-balance" element={<TrialBalancePage />} />
        <Route path="financial-statements" element={<FinancialStatementsPage />} />
        <Route path="tax" element={<TaxControlPage />} />
        <Route path="standards" element={<StandardsPage />} />
        <Route path="budgets" element={<BudgetsPage />} />
        <Route path="advanced" element={<AdvancedAccountingPage />} />
        <Route path="governance" element={<GovernancePage />} />
        <Route path="erp" element={<ErpIntegrationPage />} />
        <Route path="settings" element={<SettingsPage />} />
      </Route>
    </Routes>
  )
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <Router>
          <AppRoutes />
        </Router>
      </AuthProvider>
    </QueryClientProvider>
  )
}
