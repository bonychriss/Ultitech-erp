(function () {
  'use strict';

  if (window.__ERP_TEXT_SELECTION_COPY__) {
    return;
  }
  window.__ERP_TEXT_SELECTION_COPY__ = true;

  var MENU_ID = 'erp-selection-menu';
  var TOAST_ID = 'erp-selection-copy-toast';
  var MIN_CHARS = 1;
  var HIDE_DELAY_MS = 150;
  var SHOW_DELAY_MS = 40;
  var menu = null;
  var hideTimer = null;
  var showTimer = null;
  var lastText = '';
  var suppressUntil = 0;

  function isEditableEl(node) {
    if (!node) return false;
    if (node.nodeType !== 1) {
      node = node.parentElement || null;
    }
    if (!node || typeof node.closest !== 'function') return false;
    return !!node.closest(
      'input:not([type="button"]):not([type="submit"]):not([type="checkbox"]):not([type="radio"]):not([type="file"]), textarea, [contenteditable="true"], [contenteditable=""], .tox-edit-area, .mce-content-body'
    );
  }

  function isMenuTarget(node) {
    if (!node) return false;
    if (node.nodeType !== 1) node = node.parentElement || null;
    return !!(node && node.closest && node.closest('.erp-selection-menu'));
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
    if (node && node.nodeType === 3) node = node.parentElement;
    return node;
  }

  function stopPreserveSelection(e) {
    e.preventDefault();
    e.stopPropagation();
  }

  function ensureMenu() {
    if (menu && document.body && document.body.contains(menu)) return menu;
    if (!document.body) return null;

    menu = document.createElement('div');
    menu.id = MENU_ID;
    menu.className = 'erp-selection-menu';
    menu.setAttribute('role', 'toolbar');
    menu.setAttribute('aria-label', 'Selection actions');
    menu.hidden = true;
    menu.innerHTML =
      '<div class="erp-selection-menu__bubble">' +
      '<button type="button" class="erp-selection-menu__action" data-action="copy">Copy</button>' +
      '<span class="erp-selection-menu__divider" aria-hidden="true"></span>' +
      '<button type="button" class="erp-selection-menu__action" data-action="paste">Paste</button>' +
      '</div>' +
      '<div class="erp-selection-menu__arrow" aria-hidden="true"></div>';

    menu.addEventListener('mousedown', stopPreserveSelection);
    menu.addEventListener('mouseup', stopPreserveSelection);
    menu.addEventListener('pointerdown', stopPreserveSelection);
    menu.addEventListener('touchstart', stopPreserveSelection, { passive: false });

    menu.addEventListener('click', function (e) {
      var actionBtn = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
      if (!actionBtn) return;
      e.preventDefault();
      e.stopPropagation();
      var action = actionBtn.getAttribute('data-action');
      if (action === 'copy') {
        copySelection();
      } else if (action === 'paste') {
        pasteClipboard();
      }
    });

    document.body.appendChild(menu);
    return menu;
  }

  function positionMenu() {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;

    var range = sel.getRangeAt(0);
    var rect = range.getBoundingClientRect();
    if (!rect || (rect.width === 0 && rect.height === 0)) {
      var rects = range.getClientRects();
      if (rects && rects.length) {
        rect = rects[0];
      }
    }
    if (!rect || (rect.width === 0 && rect.height === 0 && rect.top === 0 && rect.left === 0)) {
      return;
    }

    var el = ensureMenu();
    if (!el) return;

    el.hidden = false;
    el.classList.add('is-visible');
    el.classList.remove('is-below');

    var menuW = el.offsetWidth || 140;
    var menuH = el.offsetHeight || 48;
    var gap = 6;
    var left = rect.left + rect.width / 2 - menuW / 2;
    var top = rect.top - menuH - gap;
    var placeBelow = false;

    if (top < 8) {
      top = rect.bottom + gap;
      placeBelow = true;
    }

    left = Math.max(8, Math.min(left, window.innerWidth - menuW - 8));
    top = Math.max(8, Math.min(top, window.innerHeight - menuH - 8));

    if (placeBelow) {
      el.classList.add('is-below');
    }

    el.style.left = Math.round(left) + 'px';
    el.style.top = Math.round(top) + 'px';
  }

  function hideMenu() {
    clearTimeout(showTimer);
    if (!menu) return;
    menu.classList.remove('is-visible', 'is-below');
    menu.hidden = true;
    lastText = '';
  }

  function scheduleHide() {
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function () {
      if (!getSelectedText()) {
        hideMenu();
      }
    }, HIDE_DELAY_MS);
  }

  function showNativeToast(ok, message) {
    var existing = document.getElementById(TOAST_ID);
    if (existing) existing.remove();

    var toast = document.createElement('div');
    toast.id = TOAST_ID;
    toast.className = 'erp-selection-copy-toast' + (ok ? ' is-ok' : ' is-err');
    toast.textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(function () {
      toast.classList.add('is-visible');
    });
    setTimeout(function () {
      toast.classList.remove('is-visible');
      setTimeout(function () {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 200);
    }, 2200);
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
      return;
    }
    showNativeToast(ok, message);
  }

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '0';
    document.body.appendChild(ta);
    ta.focus();
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

  function finishAction(ok, message) {
    suppressUntil = Date.now() + 500;
    hideMenu();
    try {
      window.getSelection().removeAllRanges();
    } catch (e) { /* ignore */ }
    notify(ok, message);
  }

  function copySelection() {
    var text = lastText || getSelectedText();
    if (!text) {
      hideMenu();
      return;
    }

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(text).then(function () {
        finishAction(true, 'Copied');
      }).catch(function () {
        var ok = fallbackCopy(text);
        finishAction(ok, ok ? 'Copied' : 'Could not copy');
      });
      return;
    }
    var ok = fallbackCopy(text);
    finishAction(ok, ok ? 'Copied' : 'Could not copy');
  }

  function insertTextAtSelection(text) {
    var active = document.activeElement;
    if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA')) {
      var start = active.selectionStart != null ? active.selectionStart : active.value.length;
      var end = active.selectionEnd != null ? active.selectionEnd : active.value.length;
      var value = String(active.value || '');
      active.value = value.slice(0, start) + text + value.slice(end);
      var caret = start + text.length;
      try {
        active.setSelectionRange(caret, caret);
      } catch (e) { /* ignore */ }
      active.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    }

    var sel = window.getSelection();
    if (sel && sel.rangeCount > 0 && isEditableEl(sel.anchorNode)) {
      try {
        if (document.queryCommandSupported && document.queryCommandSupported('insertText')) {
          return document.execCommand('insertText', false, text);
        }
        var range = sel.getRangeAt(0);
        range.deleteContents();
        range.insertNode(document.createTextNode(text));
        sel.collapseToEnd();
        return true;
      } catch (e) {
        return false;
      }
    }
    return false;
  }

  function pasteClipboard() {
    function applyText(text) {
      text = String(text || '');
      if (!text) {
        finishAction(false, 'Clipboard is empty');
        return;
      }
      var ok = insertTextAtSelection(text);
      finishAction(ok, ok ? 'Pasted' : 'Select a text field to paste');
    }

    if (navigator.clipboard && typeof navigator.clipboard.readText === 'function') {
      navigator.clipboard.readText().then(applyText).catch(function () {
        finishAction(false, 'Could not paste');
      });
      return;
    }
    finishAction(false, 'Paste is not supported here');
  }

  function updateFromSelection() {
    if (Date.now() < suppressUntil) {
      hideMenu();
      return;
    }

    var text = getSelectedText();
    if (!text || text.length < MIN_CHARS) {
      scheduleHide();
      return;
    }

    var anchor = selectionAnchor();
    if (isMenuTarget(anchor)) {
      return;
    }

    // Keep native editing UX in rich editors; still allow on plain page text
    if (anchor && typeof anchor.closest === 'function' && anchor.closest('.tox-edit-area, .mce-content-body')) {
      hideMenu();
      return;
    }

    lastText = text;
    clearTimeout(hideTimer);
    positionMenu();
  }

  function scheduleShow() {
    clearTimeout(showTimer);
    showTimer = setTimeout(updateFromSelection, SHOW_DELAY_MS);
  }

  function onPointerUp(e) {
    if (isMenuTarget(e.target)) return;
    scheduleShow();
  }

  function boot() {
    ensureMenu();
    document.addEventListener('mouseup', onPointerUp, true);
    document.addEventListener('touchend', onPointerUp, { capture: true, passive: true });
    document.addEventListener('pointerup', onPointerUp, true);
    document.addEventListener('keyup', function (e) {
      var k = e.key || '';
      if (k === 'Shift' || k.indexOf('Arrow') === 0 || k === 'a' || k === 'A') {
        scheduleShow();
      }
    });
    document.addEventListener('selectionchange', function () {
      if (getSelectedText()) {
        scheduleShow();
      } else {
        scheduleHide();
      }
    });
    document.addEventListener('scroll', function () {
      if (menu && menu.classList.contains('is-visible') && getSelectedText()) {
        positionMenu();
      } else if (!getSelectedText()) {
        hideMenu();
      }
    }, true);
    window.addEventListener('resize', hideMenu);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') hideMenu();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
