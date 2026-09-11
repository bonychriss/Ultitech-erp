import BooksPage from './pages/BooksPage.jsx'
import BookLedgerPage from './pages/BookLedgerPage.jsx'
import CategoriesPage from './pages/CategoriesPage.jsx'
import ReportsPage from './pages/ReportsPage.jsx'
import ImportPage from './pages/ImportPage.jsx'

function pageKey() {
  if (typeof window === 'undefined') return 'books'
  return String(window.__CASHBOOK_PAGE__ || 'books').toLowerCase()
}

export default function App() {
  switch (pageKey()) {
    case 'book':
      return <BookLedgerPage />
    case 'categories':
      return <CategoriesPage />
    case 'reports':
      return <ReportsPage />
    case 'import':
      return <ImportPage />
    case 'books':
    default:
      return <BooksPage />
  }
}
