import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './index.css';

const init = window.__ADD_PRODUCT_INIT__ || {
  autoCode: 'PRD-2026-001',
  autoCodeTruck: 'TRK-2026-001',
  categories: [],
  suppliers: [],
  trucksCategoryId: null,
  indexUrl: 'index.php',
  formAction: 'add.php',
};

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App init={init} />
  </React.StrictMode>
);
