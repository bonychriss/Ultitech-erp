import InvoiceCreatePage from './pages/InvoiceCreatePage.jsx';

import InvoicesListPage from './pages/InvoicesListPage.jsx';

import InvoiceViewPage from './pages/InvoiceViewPage.jsx';



export default function App() {

  const page = typeof window !== 'undefined' ? window.__INVOICES_PAGE__ : 'create';

  if (page === 'list') {

    return <InvoicesListPage />;

  }

  if (page === 'invoice_view') {

    return <InvoiceViewPage />;

  }

  return <InvoiceCreatePage />;

}

