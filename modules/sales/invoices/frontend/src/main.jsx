import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App.jsx';
import './index.css';
import './invoice-create.css';
import '../../../../expenses/frontend/src/expenses-desk.css';
import '../../../orders/frontend/src/quotations-list.css';
import '../../../orders/frontend/src/order-view.css';
import './invoices-list.css';
import ErrorBoundary from './ErrorBoundary.jsx';

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <ErrorBoundary>
      <App />
    </ErrorBoundary>
  </React.StrictMode>,
);