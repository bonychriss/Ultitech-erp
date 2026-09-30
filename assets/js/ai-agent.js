(function () {
    var cfg = window.__AI_AGENT__ || {};
    var thread = document.getElementById('ai-thread');
    var form = document.getElementById('ai-ask-form');
    var input = document.getElementById('ai-message');
    if (!thread || !form || !input || !cfg.apiUrl) {
        return;
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function money(amount, currency) {
        var n = Number(amount || 0);
        return (currency || 'TZS') + ' ' + n.toLocaleString(undefined, { maximumFractionDigits: 0 });
    }

    function renderReply(reply) {
        var html = '<div class="ai-bubble"><p>' + esc(reply.text || '') + '</p>';
        if (reply.facts) {
            html += '<p>' + esc(reply.facts) + '</p>';
        }
        if (reply.analysis) {
            html += '<p class="ai-analysis">' + esc(reply.analysis) + '</p>';
        }
        (reply.customers || []).forEach(function (customer) {
            html += '<p><strong>' + esc(customer.customer_name) + '</strong> | '
                + esc(customer.invoice_count) + ' overdue | '
                + esc(money(customer.overdue_amount, customer.currency))
                + (customer.oldest_days ? ' | ' + esc(customer.oldest_days) + ' days' : '')
                + '</p>';
        });
        (reply.invoices || []).forEach(function (invoice) {
            var days = Number(invoice.days_overdue || 0);
            html += '<p><strong>' + esc(invoice.customer_name) + '</strong><br>'
                + esc(invoice.invoice_number) + '<br>'
                + esc(money(invoice.balance_due, invoice.currency))
                + (days > 0 ? '<br>' + days + ' days overdue' : '')
                + '<br><a class="ai-btn" href="' + esc(invoice.view_url) + '">View invoice</a></p>';
        });
        (reply.actions || []).forEach(function (action) {
            if (action.url) {
                html += '<p><a class="ai-btn" href="' + esc(action.url) + '">' + esc(action.label || 'Open') + '</a></p>';
            }
        });
        if (reply.follow_up && reply.follow_up.draft) {
            html += '<p><strong>Follow-up suggestion</strong></p>';
            html += '<pre>' + esc(reply.follow_up.draft) + '</pre>';
            if (reply.follow_up.email_on_file) {
                html += '<p>Email on the customer record: ' + esc(reply.follow_up.email_on_file) + '</p>';
            }
            html += '<p class="ai-future">' + esc(reply.follow_up.future || '') + '</p>';
            html += '<p><button type="button" class="ai-btn ai-btn-ghost" data-copy-draft="' + esc(reply.follow_up.draft) + '">Copy draft</button></p>';
        }
        html += '</div>';
        thread.insertAdjacentHTML('beforeend', html);
        thread.lastElementChild.scrollIntoView({ block: 'nearest' });
    }

    function pushUser(text) {
        thread.insertAdjacentHTML('beforeend', '<div class="ai-bubble ai-bubble-user"><p>' + esc(text) + '</p></div>');
    }

    function post(action, extra) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('csrf', cfg.csrf || '');
        Object.keys(extra || {}).forEach(function (key) {
            body.set(key, extra[key]);
        });
        return fetch(cfg.apiUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (res) { return res.json(); });
    }

    function loadAceBriefing() {
        var facts = document.getElementById('ai-summary-facts');
        var analysis = document.getElementById('ai-summary-analysis');
        var summarySource = document.getElementById('ai-summary-source');
        var briefingLead = document.getElementById('ai-briefing-lead');
        var briefingSource = document.getElementById('ai-briefing-source');
        if (summarySource) {
            summarySource.textContent = 'ù ACE is reading the company records';
        }
        post('ace_briefing').then(function (data) {
            if (!data || !data.ok || !data.text) {
                if (summarySource) {
                    summarySource.textContent = '';
                }
                return;
            }
            if (data.source === 'ace') {
                if (facts) {
                    facts.textContent = data.text;
                }
                if (analysis) {
                    analysis.textContent = '';
                }
                if (briefingLead) {
                    briefingLead.textContent = data.text;
                }
                if (summarySource) {
                    summarySource.textContent = 'ù from ACE';
                }
                if (briefingSource) {
                    briefingSource.textContent = 'ù from ACE';
                }
            } else if (summarySource) {
                summarySource.textContent = '';
            }
        }).catch(function () {
            if (summarySource) {
                summarySource.textContent = '';
            }
        });
    }

    function ask(message) {
        var text = String(message || '').trim();
        if (!text) {
            return;
        }
        pushUser(text);
        input.value = '';
        post('ask', { message: text })
            .then(function (data) {
                if (!data.ok) {
                    renderReply({ text: data.error || 'I could not read ERP data for this company.' });
                    return;
                }
                renderReply(data.reply || {});
            })
            .catch(function () {
                renderReply({ text: 'I could not read ERP data for this company.' });
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        ask(input.value);
    });
    document.querySelectorAll('[data-ask]').forEach(function (button) {
        button.addEventListener('click', function () {
            ask(button.getAttribute('data-ask'));
        });
    });
    document.addEventListener('click', function (event) {
        var follow = event.target.closest('[data-follow-up]');
        if (follow) {
            pushUser('Prepare a follow-up for this invoice.');
            post('follow_up', { invoice_id: follow.getAttribute('data-follow-up') || '' })
                .then(function (data) { renderReply((data && data.reply) || { text: 'I could not prepare that follow-up.' }); })
                .catch(function () { renderReply({ text: 'I could not prepare that follow-up.' }); });
            var chat = document.getElementById('ai-chat');
            if (chat) {
                chat.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
        var copy = event.target.closest('[data-copy-draft]');
        if (copy && navigator.clipboard) {
            navigator.clipboard.writeText(copy.getAttribute('data-copy-draft') || '');
            copy.textContent = 'Copied';
        }
    });
    loadAceBriefing();
})();
