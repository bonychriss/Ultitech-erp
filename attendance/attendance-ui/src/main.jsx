import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './attendance-desk.css';
import './desk-extras.css';

const init = window.__ATTENDANCE_PAGE__ || { page: 'clock', data: {} };
const rootEl = document.getElementById('root');

if (rootEl) {
  ReactDOM.createRoot(rootEl).render(
    <React.StrictMode>
      <App page={init.page} data={init.data || {}} />
    </React.StrictMode>
  );
} else {
  console.error('[attendance-ui] #root element not found');
}
