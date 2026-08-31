import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './index.css';

const init = window.__STOCK_PAGE__ || { page: 'dashboard', data: {} };
const rootEl = document.getElementById('root');

if (rootEl) {
  ReactDOM.createRoot(rootEl).render(
    <React.StrictMode>
      <App page={init.page} data={init.data || {}} />
    </React.StrictMode>
  );
} else {
  console.error('[stock-ui] #root element not found');
}
