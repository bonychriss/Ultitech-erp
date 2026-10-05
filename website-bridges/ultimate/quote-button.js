(function () {
  var KEY = "ultitechQuoteList";
  var ENDPOINT = "/ultitech/quote.php";
  var RESUME_KEY = "ultitechQuoteResume";
  var RESUME_MINUTES = 60;

  function load() {
    try {
      var rows = JSON.parse(localStorage.getItem(KEY) || "[]");
      return Array.isArray(rows) ? rows : [];
    } catch (e) {
      return [];
    }
  }

  function save(rows) {
    localStorage.setItem(KEY, JSON.stringify(rows));
    paintCount();
  }

  function qty(value) {
    var n = parseFloat(value);
    if (!isFinite(n) || n <= 0) return 1;
    if (n > 1000000) return 1000000;
    return Math.round(n * 100) / 100;
  }

  function productFromPage() {
    var form = document.querySelector("#option-choice-form");
    if (!form) return null;
    var idInput = form.querySelector('input[name="id"]');
    var id = idInput ? parseInt(idInput.value, 10) : 0;
    if (!id) return null;
    var title = document.querySelector("h1");
    var name = title ? title.textContent.trim() : "";
    if (!name) {
      var named = form.querySelector('input[name="title"]');
      name = named ? named.value : document.title;
    }
    var qtyInput = form.querySelector('input[name="quantity"]');
    return {
      website_product_id: id,
      product_name: name || "Product",
      quantity: qty(qtyInput ? qtyInput.value : 1),
      unit_price: null,
      image: pageImage()
    };
  }

  function pageImage() {
    var form = document.getElementById("option-choice-form");
    if (form && form.getAttribute("data-image")) return form.getAttribute("data-image");
    var main = document.getElementById("ugt-main-image");
    if (main && main.getAttribute("src")) return main.getAttribute("src");
    var img = document.querySelector(".product-gallery img[data-src]");
    if (!img) return "";
    return img.getAttribute("data-src") || "";
  }

  function safeImage(url) {
    if (typeof url !== "string") return "";
    url = url.trim();
    if (/^https?:\/\//i.test(url) || url.charAt(0) === "/") return url;
    return "";
  }

  function cartImages() {
    var map = {};
    var node = document.getElementById("ugt-cart-quote-items");
    if (!node) return map;
    try {
      JSON.parse(node.textContent || "[]").forEach(function (item) {
        var id = parseInt(item && item.website_product_id, 10);
        var image = safeImage(item && item.image);
        if (id && image) map[id] = image;
      });
    } catch (e) {}
    return map;
  }

  function addProducts(items, keepClosed) {
    var rows = load();
    (items || []).forEach(function (product) {
      var id = parseInt(product && product.website_product_id, 10);
      if (!id) return;
      var found = false;
      rows.forEach(function (row) {
        if (row.website_product_id === id) {
          row.quantity = qty((parseFloat(row.quantity) || 0) + qty(product.quantity));
          row.product_name = product.product_name || row.product_name;
          if (safeImage(product.image)) row.image = safeImage(product.image);
          found = true;
        }
      });
      if (!found) {
        rows.push({
          website_product_id: id,
          product_name: product.product_name || "Product",
          quantity: qty(product.quantity),
          unit_price: product.unit_price || null,
          image: safeImage(product.image)
        });
      }
    });
    save(rows);
    if (keepClosed) return;
    if (typeof ugtCloseCart === "function") ugtCloseCart();
    openPanel();
  }

  function addCurrent() {
    var product = productFromPage();
    if (!product) return;
    addProducts([product]);
  }

  function addCurrentToEnquiry() {
    var product = productFromPage();
    if (!product) return;
    addProducts([product], true);
    var message = product.product_name + " added to your enquiry.";
    if (window.AIZ && AIZ.plugins && AIZ.plugins.notify) {
      AIZ.plugins.notify("success", message);
    }
  }

  function bindProductPageQuote() {
    document.querySelectorAll("[data-ugt-quote]").forEach(function (button) {
      button.addEventListener("click", function () {
        if (button.getAttribute("data-ugt-quote") === "add") {
          addCurrentToEnquiry();
        } else {
          addCurrent();
        }
      });
    });
  }

  window.ultitechAddQuoteItems = addProducts;

  function paintCount() {
    var n = load().reduce(function (sum, row) { return sum + (row.quantity > 0 ? 1 : 0); }, 0);
    document.querySelectorAll(".ugt-enquiry-count").forEach(function (el) {
      el.textContent = String(n);
    });
    document.querySelectorAll(".ugt-enquiry-badge, .ugt-enquiry-dot").forEach(function (el) {
      el.hidden = n === 0;
    });
  }

  window.ultitechPaintQuoteCount = paintCount;

  function linesHtml() {
    var rows = load();
    if (!rows.length) {
      return '<p class="uq-empty">No products yet. Choose Request quote / price on any product.</p>';
    }
    var images = cartImages();
    return rows.map(function (row, index) {
      var src = safeImage(row.image) || images[row.website_product_id] || "";
      var thumb = src
        ? '<img class="uq-thumb" alt="" src="' + src.replace(/"/g, "%22") + '">'
        : '<span class="uq-thumb"></span>';
      return '<div class="uq-line">' +
        thumb +
        '<div class="uq-info"><div class="uq-name"></div>' +
        '<div class="uq-qty">' +
        '<button type="button" data-step="-1" data-index="' + index + '" aria-label="Decrease"><i class="las la-minus"></i></button>' +
        '<input type="number" min="0.01" step="any" data-index="' + index + '" value="' + row.quantity + '" aria-label="Quantity">' +
        '<button type="button" data-step="1" data-index="' + index + '" aria-label="Increase"><i class="las la-plus"></i></button>' +
        "</div></div>" +
        '<button type="button" class="uq-remove" data-remove="' + index + '" aria-label="Remove"><i class="las la-trash-alt"></i></button>' +
        "</div>";
    }).join("");
  }

  function paintLineCount() {
    var count = document.querySelector("#ultitech-quote-panel .uq-count");
    if (count) count.textContent = String(load().length);
  }

  function fillNames() {
    var rows = load();
    document.querySelectorAll(".uq-name").forEach(function (node, index) {
      if (rows[index]) node.textContent = rows[index].product_name;
    });
  }

  function openPanel(tab) {
    var panel = document.getElementById("ultitech-quote-panel");
    if (!panel) return;
    clearSent();
    paintAuth();
    panel.querySelector(".uq-lines").innerHTML = linesHtml();
    fillNames();
    paintLineCount();
    showTab(tab === "mine" ? "mine" : "new");
    panel.hidden = false;
  }

  function esc(text) {
    return String(text == null ? "" : text).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function showTab(tab) {
    var sheet = document.querySelector("#ultitech-quote-panel .uq-sheet");
    if (!sheet) return;
    var mine = tab === "mine";
    if (mine) clearSent();
    sheet.classList.toggle("is-mine", mine);
    sheet.querySelectorAll(".uq-tabs [data-tab]").forEach(function (button) {
      var active = button.getAttribute("data-tab") === tab;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-selected", active ? "true" : "false");
    });
    if (mine) loadMine();
  }

  function formatDate(value) {
    var date = new Date(String(value || "").replace(" ", "T"));
    if (isNaN(date.getTime())) return "";
    return date.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
  }

  function mineHtml(quotes) {
    if (!quotes.length) {
      return '<div class="uq-mine-state"><i class="las la-clipboard-list"></i><p>You have not sent any requests yet.</p></div>';
    }
    return quotes.map(function (quote, index) {
      var items = quote.items || [];
      var status = String(quote.status || "").replace(/[^a-z]/g, "");
      var lines = items.map(function (item) {
        var src = safeImage(item.image);
        return "<li>" + (src ? '<img alt="" src="' + esc(src) + '">' : '<i class="uq-req-ph"></i>') +
          '<span class="uq-req-name">' + esc(item.name) + "</span><b>&times; " + esc(qty(item.quantity)) + "</b></li>";
      }).join("");
      return '<details class="uq-req"' + (index === 0 ? " open" : "") + ">" +
        "<summary><div><strong>" + esc(quote.number) + "</strong><small>" + esc(formatDate(quote.created_at)) +
        " &middot; " + items.length + " product" + (items.length === 1 ? "" : "s") + "</small></div>" +
        '<span class="uq-req-right"><span class="uq-status is-' + status + '">' + esc(quote.status_label) + "</span>" +
        '<i class="las la-angle-down"></i></span></summary>' +
        '<ul class="uq-req-items">' + lines + "</ul></details>";
    }).join("");
  }

  function loadMine() {
    var box = document.querySelector("#ultitech-quote-panel .uq-mine");
    if (!box) return;
    var auth = authInfo();
    if (!auth.user || !auth.token) {
      box.innerHTML = '<div class="uq-mine-state"><i class="las la-user-lock"></i><p>Log in to see the requests you have sent.</p>' +
        '<button type="button" class="uq-submit uq-login-btn"><i class="las la-sign-in-alt"></i> Log in</button></div>';
      return;
    }
    box.innerHTML = '<p class="uq-mine-state">Loading your requests&hellip;</p>';
    fetch(ENDPOINT + "?mine=1", {
      headers: { "Accept": "application/json", "X-Ultitech-Token": auth.token },
      credentials: "same-origin"
    }).then(function (res) { return res.json(); }).then(function (data) {
      if (!data || !data.success) {
        box.innerHTML = '<p class="uq-mine-state">' + esc((data && data.message) || "Please try again in a moment.") + "</p>";
        return;
      }
      box.innerHTML = mineHtml(data.quotes || []);
    }).catch(function () {
      box.innerHTML = '<p class="uq-mine-state">Please try again in a moment.</p>';
    });
  }

  function clearSent() {
    var sheet = document.querySelector("#ultitech-quote-panel .uq-sheet");
    if (!sheet) return;
    sheet.classList.remove("is-sent");
    var title = sheet.querySelector("h2");
    if (title) title.textContent = "Your quotation";
    var done = sheet.querySelector(".uq-done p");
    if (done) done.textContent = "";
    var msg = sheet.querySelector(".uq-msg");
    if (msg) msg.textContent = "";
  }

  function showSent(message) {
    var sheet = document.querySelector("#ultitech-quote-panel .uq-sheet");
    if (!sheet) return;
    sheet.classList.add("is-sent");
    var title = sheet.querySelector("h2");
    if (title) title.textContent = "Request received";
    var done = sheet.querySelector(".uq-done p");
    if (done) done.textContent = message;
    var msg = sheet.querySelector(".uq-msg");
    if (msg) msg.textContent = "";
  }

  function closePanel() {
    var panel = document.getElementById("ultitech-quote-panel");
    if (panel) panel.hidden = true;
  }

  function ensureUi() {
    if (document.getElementById("ultitech-quote-panel")) return;
    var style = document.createElement("style");
    style.textContent = [
      "#ultitech-quote-panel{position:fixed;inset:0;z-index:10050;background:rgba(17,24,39,.5);display:flex;align-items:center;justify-content:center;padding:16px}",
      "#ultitech-quote-panel[hidden]{display:none}",
      ".uq-sheet{--uq-accent:var(--secondary-base,#ffc519);background:#fff;width:min(600px,100%);max-height:min(92vh,760px);display:flex;flex-direction:column;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.2);font-family:inherit;font-size:14px;line-height:1.5;color:#1f2937;overflow:hidden}",
      ".uq-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:20px 24px 8px}",
      ".uq-tabs{display:flex;gap:24px;padding:0 24px;border-bottom:1px solid #eceef2}",
      ".uq-tabs button{border:0;background:none;padding:10px 0;margin-bottom:-1px;border-bottom:3px solid transparent;font:inherit;font-weight:700;color:#6b7280;cursor:pointer}",
      ".uq-tabs button:hover{color:#111827}",
      ".uq-tabs button.is-active{color:#111827;border-bottom-color:var(--uq-accent)}",
      ".uq-pane-mine{display:none}",
      ".uq-sheet.is-mine .uq-pane-new,.uq-sheet.is-mine .uq-sub{display:none}",
      ".uq-sheet.is-mine .uq-pane-mine{display:block}",
      ".uq-mine-state{display:grid;justify-items:center;gap:12px;margin:0;padding:28px 8px;color:#6b7280;text-align:center}",
      ".uq-mine-state i{font-size:44px;color:#d1d5db}",
      ".uq-mine-state p{margin:0}",
      ".uq-mine-state .uq-submit{padding:0 28px}",
      ".uq-mine-state .uq-submit i{font-size:20px;color:inherit}",
      ".uq-req{border:1px solid #eceef2;border-radius:10px;margin-bottom:10px;overflow:hidden}",
      ".uq-req summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;cursor:pointer;list-style:none}",
      ".uq-req summary::-webkit-details-marker{display:none}",
      ".uq-req summary strong{display:block;color:#111827}",
      ".uq-req summary small{color:#6b7280;font-size:12px}",
      ".uq-req[open] summary{background:#fafafa;border-bottom:1px solid #f1f2f4}",
      ".uq-req-right{display:inline-flex;align-items:center;gap:8px}",
      ".uq-req-right .la-angle-down{color:#9ca3af;transition:transform .2s}",
      ".uq-req[open] .la-angle-down{transform:rotate(180deg)}",
      ".uq-status{padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;background:#f3f4f6;color:#374151}",
      ".uq-status.is-pending{background:#fef3c7;color:#92400e}",
      ".uq-status.is-contacted{background:#dbeafe;color:#1e40af}",
      ".uq-status.is-quoted{background:#ede9fe;color:#5b21b6}",
      ".uq-status.is-accepted{background:#dcfce7;color:#166534}",
      ".uq-status.is-rejected{background:#f3f4f6;color:#6b7280}",
      ".uq-req-items{list-style:none;margin:0;padding:4px 14px}",
      ".uq-req-items li{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid #f6f7f9}",
      ".uq-req-items li:last-child{border-bottom:0}",
      ".uq-req-items img,.uq-req-ph{width:40px;height:40px;object-fit:contain;border:1px solid #f1f2f4;border-radius:6px;background:#fafafa;flex:0 0 40px}",
      ".uq-req-name{flex:1;min-width:0;font-weight:600;color:#111827}",
      ".uq-req-items b{font-weight:600;color:#6b7280;white-space:nowrap}",
      ".uq-done-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}",
      ".uq-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}",
      ".uq-shop{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 12px;border:1.5px solid #111827;border-radius:8px;background:#fff;color:#111827;font:inherit;font-weight:700;cursor:pointer}",
      ".uq-shop:hover{background:#111827;color:#fff}",
      ".uq-shop i{font-size:18px}",
      ".uq-actions .uq-shop,.uq-actions .uq-submit{min-height:40px;font-size:14px}",
      ".uq-actions .uq-submit i{font-size:18px}",
      ".uq-done .uq-view-mine{background:var(--uq-accent);border-color:var(--uq-accent)}",
      ".uq-done .uq-view-mine:hover{background:var(--uq-accent);color:#111827;filter:brightness(.95)}",
      ".uq-head h2{margin:0;font-size:20px;font-weight:700;color:#111827}",
      ".uq-sub{margin:4px 0 0;color:#6b7280;font-size:13px}",
      ".uq-close{flex:0 0 36px;width:36px;height:36px;border:0;border-radius:50%;background:#f3f4f6;color:#374151;font-size:18px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}",
      ".uq-close:hover{background:#e5e7eb}",
      ".uq-body{padding:16px 24px 24px;overflow:auto}",
      ".uq-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin:4px 0 8px}",
      ".uq-count{min-width:22px;height:22px;padding:0 6px;border-radius:999px;background:var(--uq-accent);color:#111827;font-size:12px;display:inline-flex;align-items:center;justify-content:center}",
      ".uq-lines{border:1px solid #eceef2;border-radius:10px;padding:0 14px;margin-bottom:20px}",
      ".uq-line{display:flex;gap:14px;align-items:center;padding:12px 0;border-bottom:1px solid #f1f2f4}",
      ".uq-line:last-child{border-bottom:0}",
      ".uq-thumb{width:56px;height:56px;object-fit:contain;border-radius:8px;background:#fafafa;border:1px solid #f1f2f4;flex:0 0 56px}",
      ".uq-info{flex:1;min-width:0}",
      ".uq-name{font-weight:600;color:#111827;margin-bottom:6px}",
      ".uq-qty{display:inline-flex;align-items:center;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}",
      ".uq-qty button{width:32px;height:32px;border:0;background:#f9fafb;color:#111827;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}",
      ".uq-qty button:hover{background:#f3f4f6}",
      ".uq-qty input{width:52px;height:32px;border:0;text-align:center;font-weight:600;-moz-appearance:textfield}",
      ".uq-qty input::-webkit-outer-spin-button,.uq-qty input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}",
      ".uq-remove{flex:0 0 36px;width:36px;height:36px;border:0;border-radius:8px;background:transparent;color:#9ca3af;font-size:18px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}",
      ".uq-remove:hover{background:#fee2e2;color:#b91c1c}",
      ".uq-empty{margin:0;padding:18px 0;color:#6b7280;text-align:center}",
      ".uq-form{display:grid;gap:12px}",
      ".uq-form input,.uq-form textarea{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #e5e7eb;border-radius:8px;font:inherit;color:#111827;background:#fff;transition:border-color .2s,box-shadow .2s}",
      ".uq-form input:focus,.uq-form textarea:focus{outline:0;border-color:var(--uq-accent);box-shadow:0 0 0 3px rgba(255,197,25,.25)}",
      ".uq-submit{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:48px;background:var(--uq-accent);color:#111827;border:1px solid var(--uq-accent);border-radius:8px;font-weight:700;font-size:15px;cursor:pointer}",
      ".uq-submit:hover{filter:brightness(.95)}",
      ".uq-submit[disabled]{opacity:.6;cursor:wait}",
      ".uq-submit i{font-size:20px}",
      ".uq-note{margin:0;color:#6b7280;font-size:12px;text-align:center}",
      ".uq-msg{color:#b91c1c;font-size:13px}",
      ".uq-msg:empty{display:none}",
      ".uq-done{display:none;text-align:center;padding:24px 8px 8px}",
      ".uq-done i{font-size:56px;color:#16a34a}",
      ".uq-done p{margin:12px 0 20px;font-size:15px}",
      ".uq-done button{min-height:44px;padding:0 24px;border:1.5px solid #111827;border-radius:8px;background:#fff;color:#111827;font-weight:700;cursor:pointer}",
      ".uq-done button:hover{background:#111827;color:#fff}",
      ".uq-who{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid #eceef2;border-radius:10px;background:#fafafa}",
      ".uq-who i{font-size:34px;color:#9ca3af}",
      ".uq-who strong{display:block;color:#111827}",
      ".uq-who span{display:block;color:#6b7280;font-size:13px}",
      ".uq-login{display:none;gap:12px}",
      ".uq-login-text{margin:0;color:#4b5563;text-align:center}",
      ".uq-register{color:#111827;font-weight:700;text-decoration:underline}",
      ".uq-sheet.is-guest .uq-form{display:none}",
      ".uq-sheet.is-guest .uq-login{display:grid}",
      ".uq-sheet.is-sent .uq-form,.uq-sheet.is-sent .uq-login,.uq-sheet.is-sent .uq-lines,.uq-sheet.is-sent .uq-title,.uq-sheet.is-sent .uq-sub{display:none!important}",
      ".uq-sheet.is-sent .uq-done{display:block}",
      ".uq-hp{position:absolute;left:-9999px}",
      "@media (max-width:575px){.uq-actions{grid-template-columns:1fr}.uq-actions .uq-shop{order:2}.uq-head{padding:16px 16px 12px}.uq-body{padding:12px 16px 18px}}"
    ].join("");
    document.head.appendChild(style);

    var panel = document.createElement("div");
    panel.id = "ultitech-quote-panel";
    panel.hidden = true;
    panel.innerHTML = '<div class="uq-sheet" role="dialog" aria-modal="true" aria-label="Request a quotation">' +
      '<div class="uq-head"><div><h2>Your quotation</h2>' +
      '<p class="uq-sub">Tell us what you need and our sales team will send you a price.</p></div>' +
      '<button type="button" class="uq-close" aria-label="Close"><i class="las la-times"></i></button></div>' +
      '<div class="uq-tabs" role="tablist">' +
      '<button type="button" role="tab" data-tab="new" class="is-active" aria-selected="true">New request</button>' +
      '<button type="button" role="tab" data-tab="mine" aria-selected="false">My requests</button>' +
      "</div>" +
      '<div class="uq-body"><div class="uq-pane-new">' +
      '<div class="uq-title">Products <span class="uq-count">0</span></div>' +
      '<div class="uq-lines"></div>' +
      '<div class="uq-done"><i class="las la-check-circle"></i><p></p><div class="uq-done-actions">' +
      '<button type="button" class="uq-view-mine">View my requests</button>' +
      '<button type="button" class="uq-continue">Continue browsing</button></div></div>' +
      '<form class="uq-form">' +
      '<div class="uq-who"><i class="las la-user-circle"></i><div><strong class="uq-who-name"></strong><span class="uq-who-contact"></span></div></div>' +
      '<input class="uq-phone" name="customer_phone" placeholder="Your phone number *" autocomplete="tel" hidden>' +
      '<input class="uq-hp" name="company_website" tabindex="-1" autocomplete="off">' +
      '<div class="uq-msg"></div>' +
      '<div class="uq-actions"><button type="button" class="uq-shop"><i class="las la-arrow-left"></i> Continue shopping</button>' +
      '<button class="uq-submit" type="submit"><i class="las la-envelope"></i> Request quotation</button></div>' +
      '<p class="uq-note">Our sales team will contact you shortly with the price.</p>' +
      "</form>" +
      '<div class="uq-login">' +
      '<p class="uq-login-text">Log in to send your request. Your products stay in this list.</p>' +
      '<div class="uq-actions"><button type="button" class="uq-shop"><i class="las la-arrow-left"></i> Continue shopping</button>' +
      '<button type="button" class="uq-submit uq-login-btn"><i class="las la-sign-in-alt"></i> Log in to request quotation</button></div>' +
      '<p class="uq-note">New customer? <a class="uq-register" href="#">Create an account</a></p>' +
      "</div></div>" +
      '<div class="uq-pane-mine"><div class="uq-mine"></div></div>' +
      "</div></div>";
    document.body.appendChild(panel);
    panel.querySelector(".uq-close").addEventListener("click", closePanel);
    panel.querySelector(".uq-continue").addEventListener("click", closePanel);
    panel.addEventListener("click", function (event) {
      if (event.target === panel) closePanel();
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && !panel.hidden) closePanel();
    });
    panel.addEventListener("click", function (event) {
      var step = event.target.closest("[data-step][data-index]");
      if (!step) return;
      var rows = load();
      var index = parseInt(step.getAttribute("data-index"), 10);
      if (!rows[index]) return;
      rows[index].quantity = qty(Math.max(1, (parseFloat(rows[index].quantity) || 1) + parseInt(step.getAttribute("data-step"), 10)));
      save(rows);
      var input = panel.querySelector('.uq-qty input[data-index="' + index + '"]');
      if (input) input.value = rows[index].quantity;
    });
    panel.addEventListener("input", function (event) {
      var input = event.target;
      if (!input || input.getAttribute("data-index") === null) return;
      var rows = load();
      var index = parseInt(input.getAttribute("data-index"), 10);
      if (rows[index]) {
        rows[index].quantity = qty(input.value);
        save(rows);
      }
    });
    panel.addEventListener("click", function (event) {
      var button = event.target.closest("[data-remove]");
      if (!button) return;
      var rows = load();
      rows.splice(parseInt(button.getAttribute("data-remove"), 10), 1);
      save(rows);
      openPanel();
    });
    panel.querySelector("form").addEventListener("submit", submitQuote);
    panel.addEventListener("click", function (event) {
      if (event.target.closest(".uq-login-btn")) goLogin();
      if (event.target.closest(".uq-shop")) closePanel();
      var tab = event.target.closest(".uq-tabs [data-tab]");
      if (tab) showTab(tab.getAttribute("data-tab"));
      if (event.target.closest(".uq-view-mine")) showTab("mine");
    });
    var register = panel.querySelector(".uq-register");
    register.href = authInfo().register || "/users/registration";
    register.addEventListener("click", rememberResume);
    paintAuth();
    paintCount();
  }

  function authInfo() {
    return window.ultitechAuth || {};
  }

  function paintAuth() {
    var sheet = document.querySelector("#ultitech-quote-panel .uq-sheet");
    if (!sheet) return;
    var user = authInfo().user;
    sheet.classList.toggle("is-guest", !user);
    if (!user) return;
    sheet.querySelector(".uq-who-name").textContent = "Sending as " + user.name;
    sheet.querySelector(".uq-who-contact").textContent = [user.phone, user.email].filter(Boolean).join(" · ");
    var phone = sheet.querySelector(".uq-phone");
    phone.hidden = !!user.phone;
    phone.required = !user.phone;
  }

  function rememberResume() {
    localStorage.setItem(RESUME_KEY, JSON.stringify({ url: location.href.split("#")[0], at: Date.now() }));
  }

  function goLogin() {
    rememberResume();
    var modal = document.getElementById("login_modal");
    if (modal && window.jQuery && window.jQuery.fn.modal) {
      closePanel();
      window.jQuery(modal).modal("show");
      return;
    }
    location.href = authInfo().login || "/users/login";
  }

  function resumeAfterLogin() {
    var saved;
    try { saved = JSON.parse(localStorage.getItem(RESUME_KEY) || "null"); } catch (e) { saved = null; }
    if (!saved || !authInfo().user) return;
    if (!saved.url || Date.now() - (saved.at || 0) > RESUME_MINUTES * 60000 || !load().length) {
      localStorage.removeItem(RESUME_KEY);
      return;
    }
    if (location.href.split("#")[0] !== saved.url) {
      location.replace(saved.url);
      return;
    }
    localStorage.removeItem(RESUME_KEY);
    openPanel();
  }

  function submitQuote(event) {
    event.preventDefault();
    var form = event.target;
    var msg = form.querySelector(".uq-msg");
    var rows = load().filter(function (row) { return qty(row.quantity) > 0; });
    if (!rows.length) {
      msg.textContent = "Add at least one product to the quote.";
      return;
    }
    var user = authInfo().user;
    if (!user) {
      goLogin();
      return;
    }
    var phone = user.phone || form.customer_phone.value.trim();
    if (!phone) {
      msg.textContent = "Please enter your phone number.";
      return;
    }
    var button = form.querySelector(".uq-submit");
    button.disabled = true;
    fetch(ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json", "X-Ultitech-Token": authInfo().token || "" },
      body: JSON.stringify({
        customer_name: user.name,
        customer_phone: phone,
        customer_email: user.email || "",
        company_website: form.company_website.value,
        items: rows
      })
    }).then(function (res) { return res.json(); }).then(function (data) {
      button.disabled = false;
      if (data && data.success) {
        save([]);
        form.reset();
        showSent((data.message || "Your request has been received successfully. Our sales person will contact you shortly.") +
          (data.quote_number ? " Your reference is " + data.quote_number + "." : ""));
        return;
      }
      if (data && data.login) {
        goLogin();
        return;
      }
      msg.textContent = (data && data.message) || "Please check the form and try again.";
    }).catch(function () {
      button.disabled = false;
      msg.textContent = "Please try again in a moment.";
    });
  }

  function mountProductButton() {
    if (!/\/product\//.test(location.pathname)) return;
    var cart = document.querySelector("button.add-to-cart");
    if (!cart || document.getElementById("ultitech-add-quote")) return;
    var wrap = cart.closest(".mt-3") || cart.parentElement;
    if (!wrap) return;
    wrap.classList.add("product-cart-buttons");
    if (!document.getElementById("ultitech-product-buttons-style")) {
      var style = document.createElement("style");
      style.id = "ultitech-product-buttons-style";
      style.textContent = ".product-cart-buttons{display:flex;flex-wrap:wrap;align-items:stretch;gap:12px}.product-cart-buttons>.btn{margin:0!important;min-width:160px;min-height:46px;padding:10px 18px;display:inline-flex!important;align-items:center;justify-content:center;gap:8px;line-height:1.2}.product-cart-buttons>.btn.d-none,.product-cart-buttons>.out-of-stock{display:none!important}";
      document.head.appendChild(style);
    }
    var button = document.createElement("button");
    button.id = "ultitech-add-quote";
    button.type = "button";
    button.className = "btn btn-outline-dark fw-600 rounded-0";
    button.textContent = "Request quote";
    button.addEventListener("click", addCurrent);
    wrap.appendChild(button);
  }

  function bindCartQuote() {
    if (window.__ultitechCartQuote) return;
    window.__ultitechCartQuote = true;
    document.addEventListener("click", function (event) {
      var button = event.target.closest(".ugt-cart-request-quote");
      if (!button) return;
      event.preventDefault();
      var node = document.getElementById("ugt-cart-quote-items");
      var items = [];
      try {
        items = JSON.parse(node ? node.textContent : "[]");
      } catch (e) {
        items = [];
      }
      if (!items.length) return;
      addProducts(items);
    });
  }

  function bindCardQuote() {
    if (window.__ultitechCardQuote) return;
    window.__ultitechCardQuote = true;
    document.addEventListener("click", function (event) {
      var button = event.target.closest(".ugt-card-quote");
      if (!button) return;
      event.preventDefault();
      addProducts([{
        website_product_id: button.getAttribute("data-id"),
        product_name: button.getAttribute("data-name"),
        quantity: 1,
        image: button.getAttribute("data-image")
      }]);
    });
  }

  function bindCartModalQuote() {
    if (window.__ultitechQuoteFromCart) return;
    window.__ultitechQuoteFromCart = true;
    document.addEventListener("click", function (event) {
      var button = event.target.closest(".ultitech-quote-from-cart");
      if (!button) return;
      event.preventDefault();
      if (window.jQuery) window.jQuery("#addToCart").modal("hide");
      var pageButton = document.getElementById("ultitech-add-quote") || document.querySelector(".ultitech-request-quote");
      if (pageButton) pageButton.click();
    });
  }

  window.ultitechOpenQuote = function (tab) {
    ensureUi();
    openPanel(tab);
  };

  function start() {
    ensureUi();
    paintCount();
    resumeAfterLogin();
    mountProductButton();
    bindCartModalQuote();
    bindCartQuote();
    bindCardQuote();
    bindProductPageQuote();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
