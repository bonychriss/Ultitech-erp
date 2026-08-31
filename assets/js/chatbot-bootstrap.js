(function () {
  function ensureMinimalPanel() {
    var panel = document.getElementById('chatbotPanel');
    if (panel) return panel;
    panel = document.createElement('div');
    panel.id = 'chatbotPanel';
    panel.className = 'chatbot-panel';
    // Inline minimal styles in case external CSS failed
    panel.style.position = 'fixed';
    panel.style.bottom = '72px';
    panel.style.right = '16px';
    panel.style.width = '340px';
    panel.style.maxHeight = '520px';
    panel.style.display = 'flex';
    panel.style.flexDirection = 'column';
    panel.style.background = '#fff';
    panel.style.border = '1px solid #e5e7eb';
    panel.style.boxShadow = '0 6px 24px rgba(0,0,0,.18)';
    panel.style.borderRadius = '8px';
    panel.style.zIndex = '1500';

    var header = document.createElement('div');
    header.style.padding = '10px 14px';
    header.style.borderBottom = '1px solid #e5e7eb';
    header.style.background = '#f9fafb';
    header.style.display = 'flex';
    header.style.alignItems = 'center';
    header.style.justifyContent = 'space-between';

    var h3 = document.createElement('div'); h3.textContent = 'Help Assistant'; h3.style.fontWeight = '600'; h3.style.color = '#111827'; h3.style.fontSize = '15px';
    var close = document.createElement('button'); close.textContent = '×'; close.style.background = 'transparent'; close.style.border = 'none'; close.style.cursor = 'pointer'; close.onclick = function () { panel.style.display = 'none'; };
    header.appendChild(h3); header.appendChild(close);

    var body = document.createElement('div');
    body.id = 'chatbotBody';
    body.style.padding = '12px';
    body.style.overflowY = 'auto';
    body.style.flex = '1';
    body.style.fontSize = '13px';
    body.textContent = 'Hi! Ask me about creating vouchers, approvals, budget types, statuses, or reports.';

    var footer = document.createElement('div');
    footer.style.padding = '10px'; footer.style.borderTop = '1px solid #e5e7eb'; footer.style.background = '#f9fafb'; footer.style.display = 'flex'; footer.style.gap = '6px';
    var input = document.createElement('input'); input.type = 'text'; input.placeholder = 'Ask a question…'; input.style.flex = '1'; input.style.padding = '8px 10px'; input.style.fontSize = '13px'; input.id = 'chatbotInput';
    var send = document.createElement('button'); send.textContent = 'Ask'; send.style.background = '#2563eb'; send.style.color = '#fff'; send.style.border = 'none'; send.style.padding = '8px 14px'; send.style.fontSize = '13px'; send.style.cursor = 'pointer';
    send.onclick = function () {
      var q = (input.value || '').trim(); if (!q) return; input.value = '';
      var apiPath = (window.APP_BASE_PATH || '') + '/chatbot_api.php';
      fetch(apiPath + '?q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (j) {
        var txt = '';
        if (j && j.results && j.results.length) { txt = j.results.map(function (g) { return g.title + ': ' + (g.answer || g.answer_short || ''); }).join('\n\n'); }
        else { txt = 'No direct matches. Try keywords like "create voucher", "approvals", or "budget types".'; }
        var p = document.createElement('div'); p.textContent = txt; p.style.whiteSpace = 'pre-wrap'; p.style.marginTop = '8px'; body.appendChild(p); body.scrollTop = body.scrollHeight;
      }).catch(function () { var p = document.createElement('div'); p.textContent = '(Offline) Try again later.'; body.appendChild(p); });
    };
    footer.appendChild(input); footer.appendChild(send);
    panel.appendChild(header); panel.appendChild(body); panel.appendChild(footer);
    document.body.appendChild(panel);
    return panel;
  }

  function bind(btn) {
    if (!btn || btn.dataset.bound) return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', function () {
      try {
        var panel = document.getElementById('chatbotPanel');
        if (panel) { panel.style.display = (panel.style.display === 'none' ? 'flex' : 'none'); return; }
        // Try to load the full chatbot first
        var s = document.createElement('script');
        s.src = (window.CHATBOT_JS_SRC || '/assets/js/chatbot.js?v=1');
        var loaded = false;
        s.onload = function () {
          loaded = true; setTimeout(function () {
            var p = document.getElementById('chatbotPanel');
            if (p) { p.classList && p.classList.remove('hidden'); p.style.display = 'flex'; }
            else { ensureMinimalPanel(); }
          }, 50);
        };
        s.onerror = function () { ensureMinimalPanel(); };
        document.head.appendChild(s);
        // Fallback: if not loaded within 500ms, make minimal panel
        setTimeout(function () { if (!loaded && !document.getElementById('chatbotPanel')) ensureMinimalPanel(); }, 500);
      } catch (_) { }
    });
  }

  function ensure() {
    try {
      var iconHtml = '<span class="chatbot-launcher-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a4 4 0 0 1-4 4H7l-4 4V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg></span>';
      var btn = document.getElementById('chatbotLauncher');
      if (!btn) {
        btn = document.createElement('button');
        btn.id = 'chatbotLauncher';
        btn.className = 'chatbot-launcher';
        btn.type = 'button';
        btn.innerHTML = iconHtml;
        btn.title = 'Help Assistant';
        btn.setAttribute('aria-label', 'Help Assistant');
        document.body.appendChild(btn);
      } else if (!btn.querySelector('.chatbot-launcher-icon') || btn.querySelector('.robot-container')) {
        btn.innerHTML = iconHtml;
      }
      bind(btn);
    } catch (e) { /* silent */ }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', ensure); } else { ensure(); }
})();