import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './index.css';

const init = window.__EMAIL_PAGE__ || { page: 'inbox', data: {} };
const rootEl = document.getElementById('root');

if (rootEl) {
  ReactDOM.createRoot(rootEl).render(
    <React.StrictMode>
      <App page={init.page} data={init.data || {}} />
    </React.StrictMode>
  );
} else {
  console.error('[email-ui] #root element not found');
}
