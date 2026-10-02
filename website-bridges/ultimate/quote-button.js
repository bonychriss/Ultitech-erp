(function () {
  var KEY = "ultitechQuoteList";
  var ENDPOINT = "/ultitech/quote.php";

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
    var priceNode = document.querySelector(".product-price, strong.fs-16, .fw-600.fs-16");
    var price = priceNode ? priceNode.textContent.replace(/[^0-9.]/g, "") : "";
    return {
      website_product_id: id,
      product_name: name || "Product",
      quantity: qty(qtyInput ? qtyInput.value : 1),
      unit_price: price ? parseFloat(price) : null
    };
  }

  function addCurrent() {
    var product = productFromPage();
    if (!product) return;
    var rows = load();
    var found = false;
    rows.forEach(function (row) {
      if (row.website_product_id === product.website_product_id) {
        row.quantity = qty(row.quantity + product.quantity);
        row.product_name = product.product_name;
        found = true;
      }
    });
    if (!found) rows.push(product);
    save(rows);
    openPanel();
  }

  function paintCount() {
    var n = load().reduce(function (sum, row) { return sum + (row.quantity > 0 ? 1 : 0); }, 0);
    var badge = document.getElementById("ultitech-quote-count");
    if (badge) badge.textContent = String(n);
  }

  function linesHtml() {
    var rows = load();
    if (!rows.length) {
      return '<p class="uq-empty">No products yet. Open a product and choose Add to quote.</p>';
    }
    return rows.map(function (row, index) {
      return '<div class="uq-line">' +
        '<div class="uq-name"></div>' +
        '<label>Qty <input type="number" min="0.01" step="any" data-index="' + index + '" value="' + row.quantity + '"></label>' +
        '<button type="button" data-remove="' + index + '">Remove</button>' +
        "</div>";
    }).join("");
  }

  function fillNames() {
    var rows = load();
    document.querySelectorAll(".uq-name").forEach(function (node, index) {
      if (rows[index]) node.textContent = rows[index].product_name;
    });
  }

  function openPanel() {
    var panel = document.getElementById("ultitech-quote-panel");
    if (!panel) return;
    panel.querySelector(".uq-lines").innerHTML = linesHtml();
    fillNames();
    panel.hidden = false;
  }

  function closePanel() {
    var panel = document.getElementById("ultitech-quote-panel");
    if (panel) panel.hidden = true;
  }

  function ensureUi() {
    if (document.getElementById("ultitech-quote-fab")) return;
    var style = document.createElement("style");
    style.textContent = [
      "#ultitech-quote-fab{position:fixed;right:18px;bottom:18px;z-index:10040;border:0;border-radius:999px;background:#0f766e;color:#fff;padding:12px 18px;font:600 15px/1.2 Arial,sans-serif;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.18)}",
      "#ultitech-quote-panel{position:fixed;inset:0;z-index:10050;background:rgba(15,23,42,.45);display:flex;align-items:flex-end;justify-content:center}",
      "#ultitech-quote-panel[hidden]{display:none}",
      ".uq-sheet{background:#fff;width:min(560px,100%);max-height:92vh;overflow:auto;border-radius:16px 16px 0 0;padding:18px 18px 28px;font:14px/1.4 Arial,sans-serif;color:#111}",
      ".uq-sheet h2{margin:0 0 8px;font-size:20px}",
      ".uq-line{display:flex;gap:8px;align-items:center;padding:10px 0;border-bottom:1px solid #e5e7eb}",
      ".uq-name{flex:1;font-weight:600}",
      ".uq-line input{width:88px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px}",
      ".uq-line button,.uq-close,.uq-add{border:0;background:#f1f5f9;border-radius:8px;padding:8px 10px;cursor:pointer}",
      ".uq-form{display:grid;gap:8px;margin-top:12px}",
      ".uq-form input,.uq-form textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:8px}",
      ".uq-submit{background:#0f766e;color:#fff;border:0;border-radius:999px;padding:12px 16px;font-weight:700;cursor:pointer}",
      ".uq-msg{margin-top:10px}",
      ".uq-hp{position:absolute;left:-9999px}"
    ].join("");
    document.head.appendChild(style);

    var fab = document.createElement("button");
    fab.id = "ultitech-quote-fab";
    fab.type = "button";
    fab.innerHTML = 'Quote (<span id="ultitech-quote-count">0</span>)';
    fab.addEventListener("click", openPanel);
    document.body.appendChild(fab);

    var panel = document.createElement("div");
    panel.id = "ultitech-quote-panel";
    panel.hidden = true;
    panel.innerHTML = '<div class="uq-sheet" role="dialog" aria-label="Request a quotation">' +
      '<button type="button" class="uq-close">Close</button>' +
      "<h2>Your quotation</h2>" +
      '<div class="uq-lines"></div>' +
      '<form class="uq-form">' +
      '<input name="customer_name" required placeholder="Your name" autocomplete="name">' +
      '<input name="customer_phone" required placeholder="Phone" autocomplete="tel">' +
      '<input name="customer_email" type="email" placeholder="Email (optional)" autocomplete="email">' +
      '<textarea name="notes" rows="3" placeholder="Notes (optional)"></textarea>' +
      '<input class="uq-hp" name="company_website" tabindex="-1" autocomplete="off">' +
      '<button class="uq-submit" type="submit">Request quotation</button>' +
      '<div class="uq-msg"></div>' +
      "</form></div>";
    document.body.appendChild(panel);
    panel.querySelector(".uq-close").addEventListener("click", closePanel);
    panel.addEventListener("click", function (event) {
      if (event.target === panel) closePanel();
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
    paintCount();
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
    var button = form.querySelector(".uq-submit");
    button.disabled = true;
    fetch(ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({
        customer_name: form.customer_name.value,
        customer_phone: form.customer_phone.value,
        customer_email: form.customer_email.value,
        notes: form.notes.value,
        company_website: form.company_website.value,
        items: rows
      })
    }).then(function (res) { return res.json(); }).then(function (data) {
      button.disabled = false;
      if (data && data.success) {
        save([]);
        form.reset();
        panelLinesClear();
        msg.textContent = data.message || "Your request has been received successfully. Our sales person will contact you shortly.";
        return;
      }
      msg.textContent = (data && data.message) || "Please check the form and try again.";
    }).catch(function () {
      button.disabled = false;
      msg.textContent = "Please try again in a moment.";
    });
  }

  function panelLinesClear() {
    var box = document.querySelector("#ultitech-quote-panel .uq-lines");
    if (box) box.innerHTML = linesHtml();
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
      style.textContent = ".product-cart-buttons{display:flex;flex-wrap:wrap;align-items:stretch;gap:12px}.product-cart-buttons>.btn{margin:0!important;min-width:160px;min-height:46px;padding:10px 18px;display:inline-flex!important;align-items:center;justify-content:center;gap:8px;line-height:1.2}";
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

  function start() {
    ensureUi();
    mountProductButton();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
