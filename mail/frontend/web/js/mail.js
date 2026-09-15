(function () {
  function qs(id) {
    return document.getElementById(id);
  }

  function openCompose(opts) {
    var popup = qs('compose-popup');
    if (!popup) return;
    opts = opts || {};
    popup.classList.add('is-open');
    popup.classList.remove('is-minimized');
    popup.setAttribute('aria-hidden', 'false');

    if (opts.title) {
      var heading = qs('compose-popup-heading');
      if (heading) heading.textContent = opts.title;
    }
    if (typeof opts.to === 'string') {
      var to = qs('compose-to');
      if (to) to.value = opts.to;
    }
    if (typeof opts.subject === 'string') {
      var subject = qs('compose-subject');
      if (subject) subject.value = opts.subject;
    }
    if (typeof opts.body === 'string') {
      var body = qs('compose-body');
      if (body) body.value = opts.body;
    }
    if (typeof opts.inReplyTo === 'string') {
      var irt = document.querySelector('#compose-form input[name="ComposeForm[in_reply_to]"]');
      if (irt) irt.value = opts.inReplyTo;
    }
    if (typeof opts.draftId !== 'undefined' && opts.draftId !== null) {
      var draft = document.querySelector('#compose-form input[name="ComposeForm[draft_id]"]');
      if (draft) draft.value = opts.draftId;
    }

    var focusEl = qs('compose-to');
    if (focusEl) {
      setTimeout(function () { focusEl.focus(); }, 50);
    }
  }

  function closeCompose(reset) {
    var popup = qs('compose-popup');
    if (!popup) return;
    popup.classList.remove('is-open', 'is-minimized');
    popup.setAttribute('aria-hidden', 'true');
    if (reset) {
      var form = qs('compose-form');
      if (form) form.reset();
      var list = qs('attach-list');
      if (list) list.innerHTML = '';
      var heading = qs('compose-popup-heading');
      if (heading) heading.textContent = 'New Message';
      var irt = document.querySelector('#compose-form input[name="ComposeForm[in_reply_to]"]');
      if (irt) irt.value = '';
      var draft = document.querySelector('#compose-form input[name="ComposeForm[draft_id]"]');
      if (draft) draft.value = '';
    }
  }

  function toggleMinimize() {
    var popup = qs('compose-popup');
    if (!popup) return;
    popup.classList.toggle('is-minimized');
  }

  document.addEventListener('DOMContentLoaded', function () {
    var openBtn = qs('compose-open-btn');
    if (openBtn) {
      openBtn.addEventListener('click', function (e) {
        e.preventDefault();
        openCompose({ title: 'New Message' });
      });
    }

    var closeBtn = qs('compose-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        closeCompose(true);
      });
    }

    var minBtn = qs('compose-minimize');
    if (minBtn) {
      minBtn.addEventListener('click', toggleMinimize);
    }

    var titleBar = document.querySelector('.compose-popup-title');
    if (titleBar) {
      titleBar.addEventListener('dblclick', toggleMinimize);
    }

    var input = qs('compose-attachments');
    var list = qs('attach-list');
    if (input && list) {
      input.addEventListener('change', function () {
        list.innerHTML = '';
        Array.prototype.forEach.call(input.files || [], function (file) {
          var row = document.createElement('div');
          row.className = 'attach-chip';
          row.textContent = file.name + ' (' + Math.max(1, Math.round(file.size / 1024)) + ' KB)';
          list.appendChild(row);
        });
      });
    }

    document.querySelectorAll('[data-compose-reply], [data-compose-forward]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        // allow normal navigation to mail/compose which reopens popup with data
      });
    });

    if (window.__MAIL_OPEN_COMPOSE__) {
      openCompose(window.__MAIL_OPEN_COMPOSE__);
    }
  });

  window.MailCompose = { open: openCompose, close: closeCompose };
})();
