(function () {
  if (!/\/product\//.test(window.location.pathname)) return;
  if (document.querySelector('.ultitech-request-quote')) return;

  var cart = document.querySelector('button.add-to-cart');
  if (!cart) return;
  var wrap = cart.closest('.mt-3') || cart.parentElement;
  if (!wrap) return;

  var button = document.createElement('button');
  button.type = 'button';
  button.className = 'btn btn-outline-dark fw-600 min-w-150px rounded-0 ultitech-request-quote';
  button.style.marginLeft = '8px';
  button.textContent = 'Request quote';
  wrap.appendChild(button);

  var overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;z-index:10050;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;padding:16px;';
  overlay.innerHTML = ''
    + '<form class="ultitech-quote-form" style="width:min(440px,100%);background:#fff;border-radius:16px;padding:22px 22px 18px;box-shadow:0 20px 50px rgba(15,23,42,.2);font-family:inherit;">'
    + '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:6px;">'
    + '<strong style="font-size:18px;">Request quote</strong>'
    + '<button type="button" data-close aria-label="Close" style="border:0;background:transparent;font-size:22px;line-height:1;cursor:pointer;">&times;</button>'
    + '</div>'
    + '<p data-product style="margin:0 0 14px;color:#64748b;font-size:14px;"></p>'
    + '<label style="display:block;font-size:13px;font-weight:600;margin:0 0 10px;">Name<input name="customer_name" required style="display:block;width:100%;margin-top:4px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:10px;"></label>'
    + '<label style="display:block;font-size:13px;font-weight:600;margin:0 0 10px;">Phone<input name="customer_phone" required style="display:block;width:100%;margin-top:4px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:10px;"></label>'
    + '<label style="display:block;font-size:13px;font-weight:600;margin:0 0 10px;">Email<input name="customer_email" type="email" style="display:block;width:100%;margin-top:4px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:10px;"></label>'
    + '<label style="display:block;font-size:13px;font-weight:600;margin:0 0 10px;">Quantity<input name="quantity" type="number" min="1" value="1" style="display:block;width:120px;margin-top:4px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:10px;"></label>'
    + '<label style="display:block;font-size:13px;font-weight:600;margin:0 0 12px;">Notes<textarea name="notes" rows="3" style="display:block;width:100%;margin-top:4px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:10px;"></textarea></label>'
    + '<input name="company_website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">'
    + '<p data-msg style="min-height:1.2em;margin:0 0 10px;font-size:14px;"></p>'
    + '<button type="submit" style="border:0;border-radius:999px;background:#111827;color:#fff;font-weight:700;padding:10px 18px;cursor:pointer;">Send request</button>'
    + '</form>';
  document.body.appendChild(overlay);

  var form = overlay.querySelector('form');
  var msg = overlay.querySelector('[data-msg]');
  var productLabel = overlay.querySelector('[data-product]');

  function productName() {
    var title = document.querySelector('input[name="title"]');
    if (title && title.value) return title.value.trim();
    return (document.title || '').trim();
  }

  function productId() {
    var id = document.querySelector('#option-choice-form input[name="id"]');
    return id ? parseInt(id.value, 10) || 0 : 0;
  }

  function openForm() {
    var qty = document.querySelector('#option-choice-form input[name="quantity"]');
    form.quantity.value = qty && qty.value ? qty.value : '1';
    productLabel.textContent = productName();
    msg.textContent = '';
    overlay.style.display = 'flex';
  }

  function closeForm() {
    overlay.style.display = 'none';
  }

  button.addEventListener('click', openForm);
  overlay.querySelector('[data-close]').addEventListener('click', closeForm);
  overlay.addEventListener('click', function (event) {
    if (event.target === overlay) closeForm();
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var data = new FormData(form);
    if ((data.get('company_website') || '').toString().trim() !== '') return;
    msg.style.color = '#334155';
    msg.textContent = 'Sending...';
    fetch('/ultitech/quote.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        customer_name: (data.get('customer_name') || '').toString(),
        customer_phone: (data.get('customer_phone') || '').toString(),
        customer_email: (data.get('customer_email') || '').toString(),
        notes: (data.get('notes') || '').toString(),
        quantity: (data.get('quantity') || '1').toString(),
        website_product_id: productId(),
        product_name: productName()
      })
    }).then(function (res) {
      return res.json().then(function (json) {
        return { ok: res.ok, json: json };
      });
    }).then(function (result) {
      if (!result.ok || !result.json.success) {
        throw new Error((result.json && result.json.error) || 'Could not send the request');
      }
      msg.style.color = '#15803d';
      msg.textContent = 'Quote request sent. We will contact you.';
      form.reset();
    }).catch(function (err) {
      msg.style.color = '#b91c1c';
      msg.textContent = err && err.message ? err.message : 'Could not send the request';
    });
  });
})();
