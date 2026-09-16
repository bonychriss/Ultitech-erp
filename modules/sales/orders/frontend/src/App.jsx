import QuotationsListPage from './pages/QuotationsListPage.jsx';
import SalesOrdersListPage from './pages/SalesOrdersListPage.jsx';
import OrderViewPage from './pages/OrderViewPage.jsx';
import PoViewPage from './pages/PoViewPage.jsx';
import QuoteRequestsPage from './pages/QuoteRequestsPage.jsx';

export default function App() {
  const page = typeof window !== 'undefined' ? window.__ORDERS_DESK_PAGE__ : 'quotations';
  if (page === 'sales_orders') {
    return <SalesOrdersListPage />;
  }
  if (page === 'order_view') {
    return <OrderViewPage />;
  }
  if (page === 'po_view') {
    return <PoViewPage />;
  }
  if (page === 'quote_requests') {
    return <QuoteRequestsPage />;
  }
  return <QuotationsListPage />;
}
