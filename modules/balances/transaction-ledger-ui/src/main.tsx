import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './index.css';

function showBootError(message: string) {
  const rootEl = document.getElementById('root');
  if (!rootEl) return;
  rootEl.innerHTML = `<div class="tl-boot-error" role="alert"><strong>Transaction ledger failed to load.</strong><p>${message}</p></div>`;
}

const rootEl = document.getElementById('root');
if (!rootEl) {
  showBootError('Root element not found on the page.');
} else {
  try {
    createRoot(rootEl).render(
      <StrictMode>
        <App />
      </StrictMode>,
    );
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Unknown startup error.';
    showBootError(message);
  }
}
