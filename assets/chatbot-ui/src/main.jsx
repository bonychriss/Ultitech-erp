import React from 'react';
import { createRoot } from 'react-dom/client';
import Chatbot from './Chatbot';
import './chatbot.css';

const ICON_CSS = `
#erp-chatbot-root{position:relative;z-index:10260}
#erp-chatbot-root .erp-chatbot-dock{
  position:fixed!important;z-index:10260!important;width:3rem;height:3rem;pointer-events:auto;
}
#erp-chatbot-root .erp-chatbot-fab{
  display:inline-flex!important;align-items:center!important;justify-content:center!important;
  position:relative!important;left:auto!important;top:auto!important;
  width:3rem!important;height:3rem!important;border-radius:50%!important;
  background:#7c3aed!important;background-image:none!important;border:none!important;overflow:hidden!important;
  opacity:1!important;filter:none!important;box-shadow:0 10px 22px rgba(124,58,237,.34)!important;color:#fff!important;
}
#erp-chatbot-root .erp-chatbot-fab:hover:not(.is-dragging){
  opacity:1!important;filter:none!important;background:#6d28d9!important;
}
#erp-chatbot-root .erp-chatbot-dock{overflow:visible!important}
#erp-chatbot-root .erp-chatbot-speed-btn{
  width:3.15rem!important;height:3.15rem!important;border-radius:50%!important;
  color:#fff!important;padding:0!important;
}
#erp-chatbot-root .erp-chatbot-speed-btn svg{
  width:23px!important;height:23px!important;display:block!important;
}
#erp-chatbot-root .erp-chatbot-speed-btn--help{
  background:linear-gradient(135deg,#6366f1 0%,#a855f7 100%)!important;
}
#erp-chatbot-root .erp-chatbot-speed-btn--call{
  background:linear-gradient(135deg,#22c55e 0%,#16a34a 100%)!important;
}
#erp-chatbot-root .erp-chatbot-fab-icon{
  display:flex!important;align-items:center!important;justify-content:center!important;
  width:18px!important;height:18px!important;
  visibility:visible!important;opacity:1!important;overflow:visible!important;
}
#erp-chatbot-root .erp-chatbot-fab-icon svg{
  display:block!important;width:18px!important;height:18px!important;
}
#erp-chatbot-root .erp-chatbot-fab-img,
#erp-chatbot-root .erp-chatbot-fab-bubble,
#erp-chatbot-root .erp-chatbot-fab-bubble-tail{display:none!important}
@media (max-width:767.98px){
  #erp-chatbot-root .erp-chatbot-dock{width:2.85rem;height:2.85rem}
  #erp-chatbot-root .erp-chatbot-fab{width:2.85rem!important;height:2.85rem!important}
}
`;

function ensureCssWins() {
  const cfg = window.__CHATBOT__ || {};
  const href = cfg.cssUrl;
  if (href) {
    document.querySelectorAll('link[data-erp-chatbot-css="1"]').forEach((el) => el.remove());
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.setAttribute('data-erp-chatbot-css', '1');
    document.head.appendChild(link);
  }

  let style = document.getElementById('erp-chatbot-icon-fix');
  if (!style) {
    style = document.createElement('style');
    style.id = 'erp-chatbot-icon-fix';
  }
  style.textContent = ICON_CSS;
  document.head.appendChild(style);
}

function mount() {
  ensureCssWins();

  let rootEl = document.getElementById('erp-chatbot-root');
  if (!rootEl) {
    rootEl = document.createElement('div');
    rootEl.id = 'erp-chatbot-root';
  }

  if (rootEl.parentElement !== document.body) {
    document.body.appendChild(rootEl);
  }

  document.getElementById('chatbotLauncher')?.remove();
  document.getElementById('chatbotPanel')?.remove();

  if (rootEl.dataset.reactMounted === '1') {
    ensureCssWins();
    return;
  }
  rootEl.dataset.reactMounted = '1';

  createRoot(rootEl).render(
    <React.StrictMode>
      <Chatbot />
    </React.StrictMode>
  );

  window.setTimeout(ensureCssWins, 0);
  window.setTimeout(ensureCssWins, 500);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount);
} else {
  mount();
}
