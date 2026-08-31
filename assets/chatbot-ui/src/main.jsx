import React from 'react';
import { createRoot } from 'react-dom/client';
import Chatbot from './Chatbot';
import './chatbot.css';

const ICON_CSS = `
#erp-chatbot-root .erp-chatbot-fab{
  display:inline-flex!important;align-items:center!important;justify-content:center!important;
  width:3.5rem!important;height:3.5rem!important;border-radius:50%!important;
  background-color:#7c3aed!important;background-image:linear-gradient(135deg,#6366f1 0%,#a855f7 100%)!important;border:2px solid #fff!important;overflow:visible!important;
}
#erp-chatbot-root .erp-chatbot-fab-icon{
  display:inline-flex!important;align-items:center!important;justify-content:center!important;
  visibility:visible!important;opacity:1!important;overflow:visible!important;
}
#erp-chatbot-root .erp-chatbot-fab-bubble{
  display:block!important;position:relative!important;box-sizing:border-box!important;
  width:1.05rem!important;height:0.8rem!important;margin:0 0 0.12rem!important;
  border:2.25px solid #ffffff!important;border-radius:0.28rem!important;
  background:transparent!important;visibility:visible!important;opacity:1!important;
}
#erp-chatbot-root .erp-chatbot-fab-bubble-tail{
  display:block!important;position:absolute!important;left:0.08rem!important;bottom:-0.38rem!important;
  width:0.42rem!important;height:0.42rem!important;box-sizing:border-box!important;
  border-left:2.25px solid #ffffff!important;border-bottom:2.25px solid #ffffff!important;
  border-top:0!important;border-right:0!important;background-color:#7c3aed!important;
  transform:rotate(-45deg)!important;visibility:visible!important;opacity:1!important;
}
@media (max-width:767.98px){
  #erp-chatbot-root .erp-chatbot-fab{width:3.25rem!important;height:3.25rem!important}
  #erp-chatbot-root .erp-chatbot-fab-bubble{width:1rem!important;height:0.75rem!important;border-width:2px!important}
  #erp-chatbot-root .erp-chatbot-fab-bubble-tail{width:0.38rem!important;height:0.38rem!important;border-left-width:2px!important;border-bottom-width:2px!important}
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
