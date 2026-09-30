(function () {
    var cfg = window.__AI_AGENT_ALERT__ || {};
    if (!cfg.apiUrl) {
        return;
    }
    if (window.location.pathname.indexOf('/ai-agent') !== -1) {
        return;
    }
    var dayKey = 'ultitech-ai-agent-alert-' + cfg.companyId + '-' + cfg.date;
    if ((window.localStorage && localStorage.getItem(dayKey) === '1') || (window.sessionStorage && sessionStorage.getItem(dayKey + '-seen') === '1')) {
        return;
    }
    if (window.sessionStorage) {
        sessionStorage.setItem(dayKey + '-seen', '1');
    }
    fetch(cfg.apiUrl + (cfg.apiUrl.indexOf('?') === -1 ? '?' : '&') + 'action=alert', { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            var alert = data && data.alert;
            if (!data || !data.ok || !alert || !alert.show) {
                return;
            }
            var key = 'ultitech-ai-agent-alert-' + (alert.company_id || cfg.companyId) + '-' + (alert.date || cfg.date);
            if (window.localStorage && localStorage.getItem(key) === '1') {
                return;
            }
            var count = Number(alert.overdue_count || 0);
            var box = document.createElement('aside');
            box.className = 'ai-alert';
            box.setAttribute('role', 'status');
            box.innerHTML = ''
                + '<div class="ai-alert-head"><strong>AI Agent</strong><button type="button" class="ai-close" aria-label="Dismiss">&times;</button></div>'
                + '<p>' + count + ' customer invoice' + (count === 1 ? ' is' : 's are') + ' overdue.</p>'
                + '<p>Total overdue:<br><strong>' + String(alert.amount_label || '') + '</strong></p>'
                + '<p><a class="ai-btn" href="' + String(alert.review_url || cfg.pageUrl || '#') + '">Review receivables</a></p>';
            document.body.appendChild(box);
            var close = box.querySelector('.ai-close');
            if (close) {
                close.addEventListener('click', function () {
                    if (window.localStorage) {
                        localStorage.setItem(key, '1');
                    }
                    box.remove();
                });
            }
        })
        .catch(function () {});
})();
