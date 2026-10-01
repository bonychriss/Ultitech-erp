import InvoiceCreatePage from './pages/InvoiceCreatePage.jsx';

import InvoicesListPage from './pages/InvoicesListPage.jsx';

import InvoiceViewPage from './pages/InvoiceViewPage.jsx';

import WrongInvoicesPage from './pages/WrongInvoicesPage.jsx';



export default function App() {

  const page = typeof window !== 'undefined' ? window.__INVOICES_PAGE__ : 'create';

  if (page === 'list') {

    return <InvoicesListPage />;

  }

  if (page === 'corrections') {

    return <WrongInvoicesPage />;

  }

  if (page === 'invoice_view') {

    return <InvoiceViewPage />;

  }

  if (page === 'quote_edit' || page === 'invoice_edit') {

    return <InvoiceCreatePage mode="edit" />;

  }

  return <InvoiceCreatePage />;

}

