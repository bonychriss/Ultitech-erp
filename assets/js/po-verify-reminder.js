/**
 * Google-style PO verification reminder popup.
 * Expects window.__PO_VERIFY_REMINDERS__ = { items, markReadUrl, autoShow }
 * Optional anchor: #sm-po-notify-bell (select-module topbar).
 */
(function () {
  if (window.__ultitechPoRemindBooted) return;
  window.__ultitechPoRemindBooted = true;

  function cfg() {
    return window.__PO_VERIFY_REMINDERS__ || {};
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function markRead(id) {
    var base = String(cfg().markReadUrl || '/api/get_notifications.php');
    if (!id) return;
    var url = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'action=read&id=' + encodeURIComponent('s' + id);
    try {
      fetch(url, { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    } catch (e) {}
  }

  function findAnchor() {
    return document.getElementById('sm-po-notify-bell');
  }

  function positionCard(card, anchor) {
    if (!card) return;
    var gap = 12;
    var cardW = Math.min(360, window.innerWidth - 24);
    card.style.width = cardW + 'px';

    if (anchor) {
      var rect = anchor.getBoundingClientRect();
      var left = rect.right - cardW;
      var top = rect.bottom + gap;
      if (left < 12) left = 12;
      if (left + cardW > window.innerWidth - 12) {
        left = Math.max(12, window.innerWidth - cardW - 12);
      }
      if (top + 220 > window.innerHeight - 12) {
        top = Math.max(12, rect.top - 220 - gap);
      }
      card.style.left = left + 'px';
      card.style.top = top + 'px';
      card.style.right = 'auto';
      var beak = card.querySelector('.po-remind-beak');
      if (beak) {
        var beakLeft = Math.min(cardW - 28, Math.max(18, rect.left + rect.width / 2 - left - 7));
        beak.style.left = beakLeft + 'px';
        beak.style.right = 'auto';
        beak.style.top = '-6px';
      }
      return;
    }

    card.style.top = '72px';
    card.style.right = '16px';
    card.style.left = 'auto';
  }

  function removeDom() {
    var root = document.getElementById('po-remind-root');
    if (root) root.remove();
    var anchor = findAnchor();
    if (anchor) anchor.classList.remove('is-active');
    document.documentElement.classList.remove('po-remind-open');
  }

  function showItem(item, index, total) {
    removeDom();
    item = item || {};
    var title = String(item.title || 'PO verification reminder');
    var message = String(item.message || 'You are being reminded to verify a purchase order.');
    var link = item.link ? String(item.link) : '';
    var id = Number(item.id || 0);
    var anchor = findAnchor();

    var root = document.createElement('div');
    root.id = 'po-remind-root';
    root.className = 'po-remind-root';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', title);
    root.innerHTML =
      '<div class="po-remind-scrim" data-po-remind-dismiss="1"></div>' +
      '<div class="po-remind-card" role="document">' +
      '<span class="po-remind-beak" aria-hidden="true"></span>' +
      '<button type="button" class="po-remind-close" data-po-remind-dismiss="1" aria-label="Dismiss">' +
      '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">' +
      '<path d="M3 3l8 8M11 3L3 11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>' +
      '</svg></button>' +
      '<div class="po-remind-head">' +
      '<span class="po-remind-icon" aria-hidden="true">' +
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none">' +
      '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
      '<path d="M13.73 21a2 2 0 0 1-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
      '</svg></span>' +
      '<div><p class="po-remind-kicker">Reminder' +
      (total > 1 ? ' · ' + (index + 1) + ' of ' + total : '') +
      '</p>' +
      '<h3 class="po-remind-title">' + escapeHtml(title) + '</h3></div></div>' +
      '<p class="po-remind-body">' + escapeHtml(message) + '</p>' +
      '<div class="po-remind-actions">' +
      '<button type="button" class="po-remind-btn po-remind-btn--ghost" data-po-remind-dismiss="1">Dismiss</button>' +
      (link
        ? '<button type="button" class="po-remind-btn po-remind-btn--primary" data-po-remind-open="1">Open PO</button>'
        : '') +
      '</div></div>';

    document.body.appendChild(root);
    document.documentElement.classList.add('po-remind-open');
    if (anchor) anchor.classList.add('is-active');

    var card = root.querySelector('.po-remind-card');
    positionCard(card, anchor);

    function onResize() {
      positionCard(card, findAnchor());
    }
    window.addEventListener('resize', onResize);

    root.addEventListener('click', function (e) {
      var t = e.target;
      if (!(t instanceof Element)) return;
      if (t.closest('[data-po-remind-open="1"]')) {
        markRead(id);
        window.removeEventListener('resize', onResize);
        if (link) {
          window.location.href = link;
          return;
        }
      }
      if (t.closest('[data-po-remind-dismiss="1"]')) {
        markRead(id);
        window.removeEventListener('resize', onResize);
        removeDom();
        var items = Array.isArray(cfg().items) ? cfg().items.slice() : [];
        var nextIndex = index + 1;
        // Drop current from live config so bell badge can update
        if (items.length) {
          cfg().items = items.filter(function (row, i) {
            return i !== index;
          });
          if (typeof window.ultitechPoRemindBadgeRefresh === 'function') {
            window.ultitechPoRemindBadgeRefresh(cfg().items.length);
          }
        }
        if (nextIndex < items.length) {
          // After filtering, show the item that shifted into this index
          var remaining = cfg().items || [];
          if (remaining.length) {
            setTimeout(function () {
              showItem(remaining[0], 0, remaining.length);
            }, 120);
          }
        }
      }
    });
  }

  function start(force) {
    var items = Array.isArray(cfg().items) ? cfg().items : [];
    if (!items.length) return;
    if (!force && cfg().autoShow === false) return;
    showItem(items[0], 0, items.length);
  }

  window.ultitechShowPoVerifyReminder = function () {
    start(true);
  };

  function boot() {
    var tries = 0;
    function attempt() {
      var items = Array.isArray(cfg().items) ? cfg().items : [];
      if (!items.length) return;
      var onSelectModule = !!document.getElementById('sm-po-notify-bell') || !!document.querySelector('.sm-page');
      if (onSelectModule && !document.getElementById('sm-po-notify-bell') && tries < 40) {
        tries += 1;
        setTimeout(attempt, 100);
        return;
      }
      if (cfg().autoShow !== false) {
        start(false);
      }
    }
    attempt();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
