import {StrictMode} from 'react';
import {createRoot} from 'react-dom/client';
import App from './App.tsx';
import LabelsApp from './LabelsApp.tsx';
import './index.css';

const page = typeof window !== 'undefined' ? window.__STORE_MGMT_CFG__?.page : undefined;
const Root = page === 'labels' ? LabelsApp : App;

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Root />
  </StrictMode>,
);
