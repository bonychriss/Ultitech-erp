import ErrorBoundary from './components/ErrorBoundary.jsx'
import ReportsListPage from './pages/ReportsListPage.jsx'
import ReportEditorPage from './pages/ReportEditorPage.jsx'
import { CFG } from './config.js'

export default function App() {
  if (CFG.mode === 'editor') {
    return (
      <ErrorBoundary>
        <ReportEditorPage />
      </ErrorBoundary>
    )
  }
  return (
    <ErrorBoundary>
      <ReportsListPage />
    </ErrorBoundary>
  )
}
