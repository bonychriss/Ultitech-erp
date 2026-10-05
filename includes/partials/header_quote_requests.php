<?php
/**
 * Header quote icon for Ultimate website quotation requests.
 */
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    return;
}
if (!function_exists('isUltimate') || !isUltimate()) {
    return;
}
if (!empty($GLOBALS['_ugt_quote_header'])) {
    return;
}
$GLOBALS['_ugt_quote_header'] = true;

$quoteApi = function_exists('app_url') ? app_url('/api/website_quotes.php') : '/api/website_quotes.php';
$quotePage = function_exists('company_url') ? company_url('website/quotes') : '/ultimate/website/quotes';
?>
<style>
.ugt-quote-btn{position:relative;display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border:0;background:transparent;border-radius:10px;cursor:pointer;color:#111827;padding:0}
.ugt-quote-btn:hover{background:rgba(15,23,42,.06)}
.ugt-quote-btn svg{width:22px;height:22px;display:block}
.ugt-quote-badge{position:absolute;top:4px;right:2px;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#0f766e;color:#fff;font-size:10px;font-weight:700;line-height:16px;text-align:center;box-shadow:0 0 0 2px #fff}
.ugt-quote-panel{position:fixed;z-index:1075;width:min(420px,calc(100vw - 16px));max-height:min(72vh,680px);overflow:auto;background:#fff;color:#0f172a;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 18px 48px rgba(15,23,42,.18);padding:12px 12px 8px;font-family:DM Sans,system-ui,sans-serif}
.ugt-quote-panel[hidden]{display:none}
.ugt-quote-panel h2{margin:0;font-size:1rem}
.ugt-quote-top{display:flex;justify-content:space-between;align-items:center;margin:0 0 8px}
.ugt-quote-top a{font-size:.85rem;font-weight:600;color:#0f766e;text-decoration:none}
.ugt-quote-card{border-top:1px solid #e2e8f0;padding:10px 2px}
.ugt-quote-card header{display:flex;justify-content:space-between;gap:8px}
.ugt-quote-card strong{display:block;font-size:.9rem}
.ugt-quote-card time,.ugt-quote-card p{color:#64748b;font-size:.78rem;margin:2px 0 0}
.ugt-quote-line{display:flex;align-items:center;gap:8px;padding:6px 0}
.ugt-quote-line img,.ugt-quote-ph{width:36px;height:36px;object-fit:cover;border-radius:6px;background:#f1f5f9;flex:0 0 36px}
.ugt-quote-line span{flex:1;font-weight:600;font-size:.85rem}
.ugt-quote-line b{font-weight:500;color:#475569;font-size:.8rem}
.ugt-quote-toast{position:fixed;top:76px;right:16px;z-index:1085;width:min(360px,calc(100vw - 24px));background:#fff;color:#0f172a;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 16px 40px rgba(15,23,42,.18);padding:14px 14px 12px;font-family:DM Sans,system-ui,sans-serif}
.ugt-quote-toast[hidden]{display:none}
.ugt-quote-toast h3{margin:0 0 4px;font-size:.95rem}
.ugt-quote-toast p{margin:0 0 10px;color:#475569;font-size:.875rem}
.ugt-quote-toast .row{display:flex;gap:8px}
.ugt-quote-toast button,.ugt-quote-toast .ghost{border:0;border-radius:999px;padding:8px 12px;font-weight:700;cursor:pointer}
.ugt-quote-toast button{background:#0f766e;color:#fff}
.ugt-quote-toast .ghost{background:#f1f5f9;color:#334155}
html[data-theme="dark"] .ugt-quote-btn{color:#e2e8f0}
html[data-theme="dark"] .ugt-quote-btn:hover{background:rgba(148,163,184,.12)}
html[data-theme="dark"] .ugt-quote-badge{box-shadow:0 0 0 2px #0f172a}
html[data-theme="dark"] .ugt-quote-panel,html[data-theme="dark"] .ugt-quote-toast{background:#1e293b;color:#e2e8f0;border-color:#334155}
html[data-theme="dark"] .ugt-quote-card time,html[data-theme="dark"] .ugt-quote-card p,html[data-theme="dark"] .ugt-quote-toast p{color:#94a3b8}
</style>
<button type="button" class="ugt-quote-btn" id="ugt-quote-btn" aria-label="Quote requests" title="Quote requests" aria-expanded="false" aria-controls="ugt-quote-panel">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
        <polyline points="14 2 14 8 20 8"></polyline>
        <line x1="8" y1="13" x2="16" y2="13"></line>
        <line x1="8" y1="17" x2="13" y2="17"></line>
    </svg>
    <span class="ugt-quote-badge" id="ugt-quote-badge" hidden>0</span>
</button>
<div id="ugt-quote-panel" class="ugt-quote-panel" hidden role="dialog" aria-label="Quote requests"></div>
<div id="ugt-quote-toast" class="ugt-quote-toast" hidden role="status"></div>
<script>
(function () {
    var API = <?= json_encode($quoteApi, JSON_UNESCAPED_SLASHES) ?>;
    var PAGE = <?= json_encode($quotePage, JSON_UNESCAPED_SLASHES) ?>;
    var REVIEWED = "ugtQuoteReviewed";
    var DISMISSED = "ugtQuoteToastDismissed";
    var quotes = [];

    function readSet(key) {
        try { return JSON.parse(localStorage.getItem(key) || "[]"); } catch (e) { return []; }
    }
    function writeSet(key, values) {
        var unique = [];
        values.forEach(function (value) {
            if (value && unique.indexOf(value) === -1) unique.push(value);
        });
        localStorage.setItem(key, JSON.stringify(unique.slice(-200)));
    }
    function unseen() {
        var seen = readSet(REVIEWED);
        return quotes.filter(function (quote) { return seen.indexOf(quote.quote_number) === -1; });
    }
    function money(amount) {
        var n = Number(amount);
        if (!isFinite(n) || n <= 0) return "";
        return "TZS " + n.toLocaleString("en", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function text(node, value) {
        node.textContent = value || "";
        return node;
    }
    function paintBadge() {
        var badge = document.getElementById("ugt-quote-badge");
        var n = unseen().length;
        if (!badge) return;
        badge.hidden = n < 1;
        badge.textContent = n > 9 ? "9+" : String(n);
    }
    function renderPanel() {
        var panel = document.getElementById("ugt-quote-panel");
        if (!panel) return;
        panel.textContent = "";
        var top = document.createElement("div");
        top.className = "ugt-quote-top";
        var title = document.createElement("h2");
        text(title, "Quote requests");
        var all = document.createElement("a");
        all.href = PAGE;
        text(all, "View all");
        top.appendChild(title);
        top.appendChild(all);
        panel.appendChild(top);
        if (!quotes.length) {
            var empty = document.createElement("p");
            text(empty, "No quotation requests yet.");
            panel.appendChild(empty);
            return;
        }
        quotes.forEach(function (quote) {
            var card = document.createElement("article");
            card.className = "ugt-quote-card";
            var head = document.createElement("header");
            var who = document.createElement("div");
            var strong = document.createElement("strong");
            text(strong, quote.quote_number || "Quotation");
            var name = document.createElement("div");
            text(name, quote.customer_name || "");
            who.appendChild(strong);
            who.appendChild(name);
            var time = document.createElement("time");
            text(time, quote.created_at || "");
            head.appendChild(who);
            head.appendChild(time);
            card.appendChild(head);
            var meta = [quote.customer_phone, quote.customer_email].filter(Boolean).join(" ù ");
            if (meta) {
                var p = document.createElement("p");
                text(p, meta);
                card.appendChild(p);
            }
            if (quote.notes) {
                var notes = document.createElement("p");
                text(notes, quote.notes);
                card.appendChild(notes);
            }
            var subtotal = 0;
            (quote.items || []).forEach(function (item) {
                var line = document.createElement("div");
                line.className = "ugt-quote-line";
                if (item.image) {
                    var img = document.createElement("img");
                    img.alt = "";
                    img.src = item.image;
                    line.appendChild(img);
                } else {
                    var ph = document.createElement("span");
                    ph.className = "ugt-quote-ph";
                    line.appendChild(ph);
                }
                var label = document.createElement("span");
                text(label, item.name || "Product");
                var qty = document.createElement("b");
                var q = Number(item.quantity) || 0;
                text(qty, (q % 1 === 0 ? String(q) : String(q)) + "ù");
                line.appendChild(label);
                line.appendChild(qty);
                card.appendChild(line);
                subtotal += (Number(item.unit_price) || 0) * q;
            });
            var total = money(subtotal);
            if (total) {
                var foot = document.createElement("p");
                text(foot, "Subtotal " + total);
                card.appendChild(foot);
            }
            panel.appendChild(card);
        });
    }
    function placePanel() {
        var btn = document.getElementById("ugt-quote-btn");
        var panel = document.getElementById("ugt-quote-panel");
        if (!btn || !panel) return;
        var rect = btn.getBoundingClientRect();
        panel.style.top = Math.round(rect.bottom + 8) + "px";
        panel.style.right = Math.max(8, Math.round(window.innerWidth - rect.right)) + "px";
    }
    function markReviewed() {
        writeSet(REVIEWED, readSet(REVIEWED).concat(quotes.map(function (quote) { return quote.quote_number; })));
        writeSet(DISMISSED, readSet(DISMISSED).concat(quotes.map(function (quote) { return quote.quote_number; })));
        paintBadge();
        var toast = document.getElementById("ugt-quote-toast");
        if (toast) toast.hidden = true;
    }
    function openPanel() {
        var panel = document.getElementById("ugt-quote-panel");
        var btn = document.getElementById("ugt-quote-btn");
        if (!panel) return;
        renderPanel();
        placePanel();
        panel.hidden = false;
        if (btn) btn.setAttribute("aria-expanded", "true");
        markReviewed();
    }
    function closePanel() {
        var panel = document.getElementById("ugt-quote-panel");
        var btn = document.getElementById("ugt-quote-btn");
        if (panel) panel.hidden = true;
        if (btn) btn.setAttribute("aria-expanded", "false");
    }
    function showToast() {
        var toast = document.getElementById("ugt-quote-toast");
        if (!toast) return;
        var dismissed = readSet(DISMISSED);
        var fresh = unseen().filter(function (quote) { return dismissed.indexOf(quote.quote_number) === -1; });
        if (!fresh.length) {
            toast.hidden = true;
            return;
        }
        var newest = fresh[0];
        var count = fresh.length;
        toast.textContent = "";
        var h = document.createElement("h3");
        text(h, count === 1 ? "New quotation request" : count + " new quotation requests");
        var p = document.createElement("p");
        var items = (newest.items || []).length;
        text(p, (newest.customer_name || "A customer") + " requested " + items + " product" + (items === 1 ? "" : "s") + ".");
        var row = document.createElement("div");
        row.className = "row";
        var review = document.createElement("button");
        review.type = "button";
        text(review, "Review");
        review.addEventListener("click", openPanel);
        var later = document.createElement("button");
        later.type = "button";
        later.className = "ghost";
        text(later, "Later");
        later.addEventListener("click", function () {
            writeSet(DISMISSED, readSet(DISMISSED).concat(fresh.map(function (quote) { return quote.quote_number; })));
            toast.hidden = true;
        });
        row.appendChild(review);
        row.appendChild(later);
        toast.appendChild(h);
        toast.appendChild(p);
        toast.appendChild(row);
        toast.hidden = false;
    }
    function apply(list) {
        quotes = Array.isArray(list) ? list : [];
        paintBadge();
        var panel = document.getElementById("ugt-quote-panel");
        if (panel && !panel.hidden) renderPanel();
        showToast();
    }
    function load() {
        fetch(API, { credentials: "same-origin", headers: { "Accept": "application/json" } })
            .then(function (res) { return res.json(); })
            .then(function (data) { apply(data && data.quotes); })
            .catch(function () {});
    }
    var btn = document.getElementById("ugt-quote-btn");
    if (btn) {
        btn.addEventListener("click", function (event) {
            event.stopPropagation();
            var panel = document.getElementById("ugt-quote-panel");
            if (panel && !panel.hidden) closePanel();
            else openPanel();
        });
    }
    document.addEventListener("click", function (event) {
        var panel = document.getElementById("ugt-quote-panel");
        if (!panel || panel.hidden) return;
        if (event.target.closest("#ugt-quote-panel, #ugt-quote-btn, #ugt-quote-toast")) return;
        closePanel();
    });
    load();
    window.setInterval(load, 45000);
})();
</script>
