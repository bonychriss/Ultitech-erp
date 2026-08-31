import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App.jsx';
import ErrorBoundary from './ErrorBoundary.jsx';
import '../../../expenses/frontend/src/expenses-desk.css';
import '../../../expenses/frontend/src/expense-create.css';
import '../../../expenses/frontend/src/expenses-dark.css';
import './index.css';

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <ErrorBoundary>
      <App />
    </ErrorBoundary>
  </React.StrictMode>,
);
