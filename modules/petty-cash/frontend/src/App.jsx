import BooksPage from './pages/BooksPage.jsx'
import BookLedgerPage from './pages/BookLedgerPage.jsx'
import CategoriesPage from './pages/CategoriesPage.jsx'
import ReportsPage from './pages/ReportsPage.jsx'

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
    case 'books':
    default:
      return <BooksPage />
  }
}
