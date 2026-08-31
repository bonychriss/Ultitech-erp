import CustomerEditPage from './pages/CustomerEditPage.jsx';
import CustomerAddPage from './pages/CustomerAddPage.jsx';
import CustomerCataloguePage from './pages/CustomerCataloguePage.jsx';
import CustomerIndexPage from './pages/CustomerIndexPage.jsx';
import CustomerViewPage from './pages/CustomerViewPage.jsx';

function getDeskPage() {
  if (typeof window === 'undefined') return 'catalogue';
  return window.__CUSTOMERS_DESK_PAGE__ || 'catalogue';
}

export default function App() {
  const deskPage = getDeskPage();
  if (deskPage === 'add') {
    return <CustomerAddPage />;
  }
  if (deskPage === 'edit') {
    return <CustomerEditPage />;
  }
  if (deskPage === 'view') {
    return <CustomerViewPage />;
  }
  if (deskPage === 'index') {
    return <CustomerIndexPage />;
  }
  return <CustomerCataloguePage />;
}
