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
      unit_price: price ? parseFloat(price) : null,
      image: pageImage()
    };
  }

  function pageImage() {
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

  function addProducts(items) {
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
    if (typeof ugtCloseCart === "function") ugtCloseCart();
    openPanel();
  }

  function addCurrent() {
    var product = productFromPage();
    if (!product) return;
    addProducts([product]);
  }

  window.ultitechAddQuoteItems = addProducts;

  function paintCount() {
    var n = load().reduce(function (sum, row) { return sum + (row.quantity > 0 ? 1 : 0); }, 0);
    var badge = document.getElementById("ultitech-quote-count");
    if (badge) badge.textContent = String(n);
  }

  function linesHtml() {
    var rows = load();
    if (!rows.length) {
      return '<p class="uq-empty">No products yet. Open a product and choose Request quote.</p>';
    }
    var images = cartImages();
    return rows.map(function (row, index) {
      var src = safeImage(row.image) || images[row.website_product_id] || "";
      var thumb = src
        ? '<img class="uq-thumb" alt="" src="' + src.replace(/"/g, "%22") + '">'
        : '<span class="uq-thumb"></span>';
      return '<div class="uq-line">' +
        thumb +
        '<div class="uq-name"></div>' +
        '<label>Qty <input type="number" min="0.01" step="any" data-index="' + index + '" value="' + row.quantity + '"></label>' +
        '<button type="button" data-remove="' + index + '" aria-label="Remove"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/></svg></button>' +
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
    clearSent();
    panel.querySelector(".uq-lines").innerHTML = linesHtml();
    fillNames();
    panel.hidden = false;
  }

  function clearSent() {
    var sheet = document.querySelector("#ultitech-quote-panel .uq-sheet");
    if (!sheet) return;
    sheet.classList.remove("is-sent");
    var title = sheet.querySelector("h2");
    if (title) title.textContent = "Your quotation";
    var done = sheet.querySelector(".uq-done");
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
    var done = sheet.querySelector(".uq-done");
    if (done) done.textContent = message;
    var msg = sheet.querySelector(".uq-msg");
    if (msg) msg.textContent = "";
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
      ".uq-line{display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #e5e7eb}",
      ".uq-thumb{width:48px;height:48px;object-fit:cover;border-radius:6px;background:#f1f5f9;flex:0 0 48px}",
      ".uq-name{flex:1;font-weight:600}",
      ".uq-line input{width:88px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px}",
      ".uq-close,.uq-add{border:0;background:#f1f5f9;border-radius:8px;padding:8px 10px;cursor:pointer}",
      ".uq-line button{border:0;background:transparent;color:#64748b;width:36px;height:36px;border-radius:8px;padding:0;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 36px}",
      ".uq-line button:hover{background:#fee2e2;color:#b91c1c}",
      ".uq-form{display:grid;gap:8px;margin-top:12px}",
      ".uq-form input,.uq-form textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:8px}",
      ".uq-submit{background:#0f766e;color:#fff;border:0;border-radius:999px;padding:12px 16px;font-weight:700;cursor:pointer}",
      ".uq-msg{margin-top:10px}",
      ".uq-done{display:none;margin:12px 0 4px;font-size:16px;line-height:1.5}",
      ".uq-sheet.is-sent .uq-form,.uq-sheet.is-sent .uq-lines{display:none}",
      ".uq-sheet.is-sent .uq-done{display:block}",
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
      '<p class="uq-done"></p>' +
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
        showSent(data.message || "Your request has been received successfully. Our sales person will contact you shortly.");
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

  function start() {
    ensureUi();
    mountProductButton();
    bindCartModalQuote();
    bindCartQuote();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
