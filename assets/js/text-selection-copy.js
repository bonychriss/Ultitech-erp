(function () {
  'use strict';

  if (window.__ERP_TEXT_SELECTION_COPY__) {
    return;
  }
  window.__ERP_TEXT_SELECTION_COPY__ = true;

  var BTN_ID = 'erp-selection-copy-btn';
  var MIN_CHARS = 1;
  var HIDE_DELAY_MS = 120;
  var btn = null;
  var hideTimer = null;
  var lastText = '';
  var suppressUntil = 0;

  function isEditableTarget(node) {
    if (!node || node.nodeType !== 1) {
      node = node && node.parentElement ? node.parentElement : null;
    }
    if (!node) return false;
    if (node.closest('input, textarea, select, [contenteditable="true"], [contenteditable=""], .tox-edit-area, .mce-content-body')) {
      return true;
    }
    var tag = (node.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select';
  }

  function getSelectedText() {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || sel.isCollapsed) {
      return '';
    }
    return String(sel.toString() || '').replace(/\u00a0/g, ' ').trim();
  }

  function selectionAnchor() {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return null;
    var range = sel.getRangeAt(0);
    var node = range.commonAncestorContainer;
    if (node.nodeType === 3) node = node.parentElement;
    return node;
  }

  function ensureButton() {
    if (btn && document.body.contains(btn)) return btn;
    btn = document.createElement('button');
    btn.id = BTN_ID;
    btn.type = 'button';
    btn.className = 'erp-selection-copy-btn';
    btn.setAttribute('aria-label', 'Copy selected text');
    btn.innerHTML =
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
      '<rect x="9" y="9" width="13" height="13" rx="2"/>' +
      '<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>' +
      '</svg>' +
      '<span>Copy</span>';
    btn.addEventListener('mousedown', function (e) {
      // Keep selection while clicking the button
      e.preventDefault();
    });
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      copySelection();
    });
    document.body.appendChild(btn);
    return btn;
  }

  function positionButton() {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;
    var range = sel.getRangeAt(0);
    var rect = range.getBoundingClientRect();
    if (!rect || (rect.width === 0 && rect.height === 0)) {
      var rects = range.getClientRects();
      if (rects && rects.length) {
        rect = rects[rects.length - 1];
      }
    }
    if (!rect) return;

    var el = ensureButton();
    el.hidden = false;
    el.classList.add('is-visible');

    var btnW = el.offsetWidth || 72;
    var btnH = el.offsetHeight || 32;
    var gap = 8;
    var left = rect.left + rect.width / 2 - btnW / 2;
    var top = rect.top - btnH - gap;

    if (top < 8) {
      top = rect.bottom + gap;
    }
    left = Math.max(8, Math.min(left, window.innerWidth - btnW - 8));
    top = Math.max(8, Math.min(top, window.innerHeight - btnH - 8));

    el.style.left = Math.round(left) + 'px';
    el.style.top = Math.round(top) + 'px';
  }

  function hideButton() {
    if (!btn) return;
    btn.classList.remove('is-visible');
    btn.hidden = true;
    lastText = '';
  }

  function scheduleHide() {
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function () {
      if (!getSelectedText()) {
        hideButton();
      }
    }, HIDE_DELAY_MS);
  }

  function notify(ok, message) {
    if (window.Toast && typeof window.Toast.fire === 'function') {
      window.Toast.fire({
        icon: ok ? 'success' : 'error',
        title: message
      });
      return;
    }
    if (typeof window.showToast === 'function') {
      window.showToast(ok ? 'success' : 'error', message);
      return;
    }
    if (window.Swal && typeof window.Swal.fire === 'function') {
      window.Swal.fire({
        toast: true,
        position: 'top-end',
        icon: ok ? 'success' : 'error',
        title: message,
        showConfirmButton: false,
        timer: 2200
      });
    }
  }

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '0';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (e) {
      ok = false;
    }
    document.body.removeChild(ta);
    return ok;
  }

  function copySelection() {
    var text = lastText || getSelectedText();
    if (!text) {
      hideButton();
      return;
    }

    function done(ok) {
      suppressUntil = Date.now() + 400;
      hideButton();
      try {
        window.getSelection().removeAllRanges();
      } catch (e) { /* ignore */ }
      notify(ok, ok ? 'Copied to clipboard' : 'Could not copy');
    }

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(text).then(function () {
        done(true);
      }).catch(function () {
        done(fallbackCopy(text));
      });
      return;
    }
    done(fallbackCopy(text));
  }

  function updateFromSelection() {
    if (Date.now() < suppressUntil) {
      hideButton();
      return;
    }

    var text = getSelectedText();
    if (!text || text.length < MIN_CHARS) {
      scheduleHide();
      return;
    }

    var anchor = selectionAnchor();
    if (isEditableTarget(anchor)) {
      hideButton();
      return;
    }

    lastText = text;
    clearTimeout(hideTimer);
    positionButton();
  }

  function onPointerUp() {
    // Let the selection settle after mouse/touch release
    setTimeout(updateFromSelection, 10);
  }

  document.addEventListener('mouseup', onPointerUp);
  document.addEventListener('touchend', onPointerUp, { passive: true });
  document.addEventListener('keyup', function (e) {
    if (e.key === 'Shift' || e.key.indexOf('Arrow') === 0 || e.key === 'a' || e.key === 'A') {
      setTimeout(updateFromSelection, 10);
    }
  });
  document.addEventListener('selectionchange', function () {
    if (!getSelectedText()) {
      scheduleHide();
    }
  });
  document.addEventListener('scroll', function () {
    if (btn && btn.classList.contains('is-visible')) {
      positionButton();
    }
  }, true);
  window.addEventListener('resize', hideButton);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') hideButton();
  });
})();
