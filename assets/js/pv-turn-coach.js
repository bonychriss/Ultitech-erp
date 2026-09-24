/**
 * Google-style payment-voucher coach tip (sidebar Notifications).
 * Loads pending tasks from /api/pv_coach.php and shows an anchored tip.
 */
(function () {
  if (window.__ultitechPvCoachBooted) return;
  window.__ultitechPvCoachBooted = true;

  var CFG = window.ULTITECH_PV_COACH || {};
  var apiUrl = CFG.apiUrl || '/api/pv_coach.php';
  var anchorSel = CFG.anchor || '.sidebar-notif-item .sidebar-notif-trigger, .sidebar-notif-item .header-notif-bell-btn, .header-notif-bell-btn';
  var scrollHostSel = CFG.scrollHost || '#native-sidebar';
  var HIGHLIGHT_KEY = 'ultitech_pv_highlights';

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function dismissKey(userId) {
    return 'ultitech_pv_coach_dismissed_u' + String(userId || 0);
  }

  function wasDismissed(userId, fingerprint) {
    try {
      var raw = sessionStorage.getItem(dismissKey(userId));
      if (!raw) return false;
      var data = JSON.parse(raw);
      if (!data || data.fp !== fingerprint) {
        // Pending set changed (e.g. one voucher signed)  allow coach again.
        sessionStorage.removeItem(dismissKey(userId));
        return false;
      }
      if (data.at && Date.now() - Number(data.at) > 12 * 60 * 60 * 1000) {
        sessionStorage.removeItem(dismissKey(userId));
        return false;
      }
      return true;
    } catch (e) {
      return false;
    }
  }

  function markDismissed(userId, fingerprint) {
    try {
      sessionStorage.setItem(
        dismissKey(userId),
        JSON.stringify({ fp: fingerprint, at: Date.now() })
      );
    } catch (e) {}
  }

  function readHighlights() {
    try {
      var raw = localStorage.getItem(HIGHLIGHT_KEY);
      if (!raw) return { ids: {}, nos: {} };
      var data = JSON.parse(raw) || {};
      return { ids: data.ids || {}, nos: data.nos || {} };
    } catch (e) {
      return { ids: {}, nos: {} };
    }
  }

  function writeHighlights(store) {
    try {
      localStorage.setItem(HIGHLIGHT_KEY, JSON.stringify(store || { ids: {}, nos: {} }));
    } catch (e) {}
  }

  function rememberHighlights(data) {
    // Replace store with currently pending vouchers only (completed ones drop out).
    var store = { ids: {}, nos: {} };
    (data.voucherIds || []).forEach(function (id) {
      if (id) store.ids[String(id)] = 1;
    });
    (data.voucherNos || []).forEach(function (no) {
      if (no) store.nos[String(no).toUpperCase()] = 1;
    });
    (data.tasks || []).forEach(function (t) {
      if (t && t.id) store.ids[String(t.id)] = 1;
      if (t && t.voucher_no) store.nos[String(t.voucher_no).toUpperCase()] = 1;
    });
    writeHighlights(store);
    return store;
  }

  function clearAllHighlights() {
    writeHighlights({ ids: {}, nos: {} });
    var list = document.getElementById('notif-dd-list');
    if (!list) return;
    list.querySelectorAll('.nc-card.nc-card--pv-coach-hit').forEach(function (card) {
      card.classList.remove('nc-card--pv-coach-hit');
    });
  }

  function forgetHighlightFromCard(card) {
    if (!card) return;
    var store = readHighlights();
    var vid = card.getAttribute('data-voucher-id') || '';
    var vno = (card.getAttribute('data-voucher-no') || '').toUpperCase();
    if (vid) delete store.ids[vid];
    if (vno) delete store.nos[vno];
    var text = ((card.textContent || '') + '').toUpperCase();
    Object.keys(store.nos).forEach(function (no) {
      if (no && text.indexOf(no) !== -1) delete store.nos[no];
    });
    writeHighlights(store);
    card.classList.remove('nc-card--pv-coach-hit');
  }

  function forgetHighlightForVoucher(voucherId, voucherNo) {
    var store = readHighlights();
    var vid = voucherId ? String(voucherId) : '';
    var vno = voucherNo ? String(voucherNo).toUpperCase() : '';
    if (vid) delete store.ids[vid];
    if (vno) delete store.nos[vno];
    writeHighlights(store);
    var list = document.getElementById('notif-dd-list');
    if (!list) return;
    list.querySelectorAll('.nc-card').forEach(function (card) {
      var cvid = card.getAttribute('data-voucher-id') || '';
      var cvno = (card.getAttribute('data-voucher-no') || '').toUpperCase();
      var text = ((card.textContent || '') + '').toUpperCase();
      var hit =
        (vid && cvid === vid) ||
        (vno && (cvno === vno || text.indexOf(vno) !== -1));
      if (!hit) return;
      card.classList.remove('nc-card--pv-coach-hit');
      if (card.getAttribute('data-pv-action') === '1') {
        card.classList.remove('is-unread');
        var badge = card.querySelector('.nc-card-unread-label, .nc-card-unread-dot');
        if (badge) badge.remove();
      }
    });
  }

  function clearDismissedForUser(userId) {
    try {
      sessionStorage.removeItem(dismissKey(userId));
      Object.keys(sessionStorage).forEach(function (k) {
        if (k.indexOf('ultitech_pv_coach_dismissed') === 0) {
          sessionStorage.removeItem(k);
        }
      });
    } catch (e) {}
  }

  function removeCoachDom() {
    var existing = document.getElementById('pv-coach-root');
    if (existing && existing.parentNode) {
      existing.parentNode.removeChild(existing);
    }
    document.documentElement.classList.remove('pv-coach-open');
    document.querySelectorAll('.pv-coach-target').forEach(function (el) {
      el.classList.remove('pv-coach-target');
    });
    document.querySelectorAll('.pv-coach-target-wrap').forEach(function (el) {
      el.classList.remove('pv-coach-target-wrap');
    });
    document.querySelectorAll('.pv-coach-live-badge').forEach(function (el) {
      if (el.parentNode) el.parentNode.removeChild(el);
    });
  }

  /**
   * Re-fetch pending tasks and show the coach when any remain.
   * @param {{force?:boolean, delayMs?:number}} opts
   */
  function refreshPvCoach(opts) {
    opts = opts || {};
    var force = !!opts.force;
    var delayMs = typeof opts.delayMs === 'number' ? opts.delayMs : 350;
    var url = apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now();

    return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok || !data.count) {
          clearAllHighlights();
          removeCoachDom();
          return data;
        }
        if (force) {
          clearDismissedForUser(data.userId);
        }
        rememberHighlights(data);
        applyPersistedHighlights();
        removeCoachDom();
        setTimeout(function () {
          showCoach(data, { force: force });
        }, delayMs);
        return data;
      })
      .catch(function () {
        return null;
      });
  }

  window.ultitechRefreshPvCoach = refreshPvCoach;
  window.ultitechForgetPvHighlight = forgetHighlightForVoucher;

  function requestCoachReshowAfterReload() {
    try {
      sessionStorage.setItem('ultitech_pv_coach_reshow', '1');
    } catch (e) {}
  }

  window.ultitechRequestPvCoachReshow = requestCoachReshowAfterReload;

  function cardMatchesPending(card, store) {
    if (!card || !store) return false;
    var vid = card.getAttribute('data-voucher-id') || '';
    var vno = (card.getAttribute('data-voucher-no') || '').toUpperCase();
    var text = ((card.textContent || '') + '').toUpperCase();
    if (vid && store.ids[vid]) return true;
    if (vno && store.nos[vno]) return true;
    return Object.keys(store.nos).some(function (no) {
      return no && text.indexOf(no) !== -1;
    });
  }

  function applyPersistedHighlights() {
    var store = readHighlights();
    var list = document.getElementById('notif-dd-list');
    if (!list) return 0;
    var matched = 0;
    list.querySelectorAll('.nc-card').forEach(function (card) {
      var hit = cardMatchesPending(card, store);
      if (hit) {
        card.classList.add('nc-card--pv-coach-hit');
        matched += 1;
      } else {
        card.classList.remove('nc-card--pv-coach-hit');
      }
    });
    return matched;
  }

  function findAnchor() {
    var parts = String(anchorSel).split(',');
    for (var i = 0; i < parts.length; i++) {
      var el = document.querySelector(parts[i].trim());
      if (el) return el;
    }
    return null;
  }

  function scrollSidebarTo(anchor) {
    var host = document.querySelector(scrollHostSel);
    if (!host || !anchor) return;
    try {
      var hostRect = host.getBoundingClientRect();
      var aRect = anchor.getBoundingClientRect();
      var nextTop =
        host.scrollTop + (aRect.top - hostRect.top) - Math.max(24, hostRect.height * 0.2);
      host.scrollTo({ top: Math.max(0, nextTop), behavior: 'smooth' });
    } catch (e) {
      try {
        anchor.scrollIntoView({ block: 'center', behavior: 'smooth' });
      } catch (e2) {}
    }
  }

  function ensureBadge(anchor, badgeText) {
    if (!anchor) return null;
    var wrap = anchor.closest('.sidebar-notif-item') || anchor;
    var existing = wrap.querySelector('.pv-coach-live-badge');
    if (existing) {
      existing.textContent = badgeText || '1';
      return existing;
    }
    var badge = document.createElement('span');
    badge.className = 'pv-coach-live-badge';
    badge.setAttribute('aria-hidden', 'true');
    badge.textContent = badgeText || '1';
    wrap.appendChild(badge);
    return badge;
  }

  function positionCard(card, anchor) {
    if (!card || !anchor) return;
    var gap = 28;
    var rect = anchor.getBoundingClientRect();
    var cardW = Math.min(380, window.innerWidth - 24);
    var cardH = card.offsetHeight || 220;
    var left = rect.right + gap;
    var top = rect.top + rect.height / 2 - 48;

    if (left + cardW > window.innerWidth - 16) {
      left = Math.max(16, window.innerWidth - cardW - 16);
    }
    if (top < 16) top = 16;
    if (top + cardH > window.innerHeight - 16) {
      top = Math.max(16, window.innerHeight - cardH - 16);
    }

    card.style.width = cardW + 'px';
    card.style.left = left + 'px';
    card.style.top = top + 'px';
    card.style.right = 'auto';
    card.style.bottom = 'auto';
    card.style.transform = 'none';
  }

  function drawGuide(svg, fromEl, toEl) {
    if (!svg || !fromEl || !toEl) return;
    var a = fromEl.getBoundingClientRect();
    var b = toEl.getBoundingClientRect();
    var x1 = a.left + a.width / 2;
    var y1 = a.top + a.height / 2;
    var x2 = b.left + 2;
    var y2 = b.top + Math.min(56, b.height * 0.35);
    var dx = Math.max(40, (x2 - x1) * 0.55);
    var path = svg.querySelector('.pv-coach-guide-path');
    if (path) {
      path.setAttribute(
        'd',
        'M ' +
          x1 +
          ' ' +
          y1 +
          ' C ' +
          (x1 + dx) +
          ' ' +
          (y1 - 18) +
          ', ' +
          (x2 - 24) +
          ' ' +
          (y2 - 8) +
          ', ' +
          x2 +
          ' ' +
          y2
      );
    }
  }

  function openNotificationsAndHighlight(data) {
    rememberHighlights(data || {});

    var ids = {};
    var nos = {};
    var stored = readHighlights();
    Object.keys(stored.ids).forEach(function (id) {
      ids[id] = true;
    });
    Object.keys(stored.nos).forEach(function (no) {
      nos[no] = true;
    });
    (data.voucherIds || []).forEach(function (id) {
      ids[String(id)] = true;
    });
    (data.voucherNos || []).forEach(function (no) {
      nos[String(no).toUpperCase()] = true;
    });
    (data.tasks || []).forEach(function (t) {
      if (t && t.id) ids[String(t.id)] = true;
      if (t && t.voucher_no) nos[String(t.voucher_no).toUpperCase()] = true;
    });

    function revealAllTabs() {
      var list = document.getElementById('notif-dd-list');
      if (!list) return;
      list.querySelectorAll('.nc-card').forEach(function (card) {
        card.style.display = '';
      });
      var empty = list.querySelector('.nc-empty-filter');
      if (empty) empty.style.display = 'none';
      var tabs = document.querySelectorAll('#notif-dd [data-nc-tabs] [data-nc-filter]');
      tabs.forEach(function (tab) {
        tab.classList.toggle('is-active', (tab.getAttribute('data-nc-filter') || '') === 'week');
      });
    }

    function highlightCards() {
      var list = document.getElementById('notif-dd-list');
      if (!list) return 0;
      revealAllTabs();
      var matched = 0;
      var firstMatch = null;
      var store = { ids: ids, nos: nos };
      list.querySelectorAll('.nc-card').forEach(function (card) {
        var hit = cardMatchesPending(card, store);
        if (hit) {
          matched += 1;
          card.classList.add('nc-card--pv-coach-hit');
          card.style.display = '';
          if (!firstMatch) firstMatch = card;
        } else if (card.getAttribute('data-pv-action') === '1') {
          card.classList.remove('nc-card--pv-coach-hit');
        }
      });
      if (firstMatch && typeof firstMatch.scrollIntoView === 'function') {
        try {
          firstMatch.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } catch (e) {}
      }
      return matched;
    }

    function openPanel() {
      var dd = document.getElementById('notif-dd');
      if (dd && dd.parentElement !== document.body) {
        document.body.appendChild(dd);
      }
      var bd = document.getElementById('notif-backdrop');
      if (bd && bd.parentElement !== document.body) {
        document.body.appendChild(bd);
      }

      if (typeof window.setNotifDrawerOpen === 'function') {
        window.setNotifDrawerOpen(true);
      } else if (dd) {
        dd.classList.add('open');
        dd.setAttribute('aria-hidden', 'false');
        document.body.classList.add('notif-panel-open');
        if (bd) {
          bd.classList.add('is-open');
          bd.style.display = 'block';
        }
        var btn =
          document.querySelector('.sidebar-notif-trigger') ||
          document.querySelector('.header-notif-bell-btn');
        if (btn) btn.setAttribute('aria-expanded', 'true');
      }
      try {
        sessionStorage.setItem('ultitech_notif_drawer_open', '1');
      } catch (e) {}
      return !!(dd && dd.classList.contains('open'));
    }

    setTimeout(function () {
      openPanel();
      setTimeout(function () {
        highlightCards();
        applyPersistedHighlights();
        setTimeout(highlightCards, 100);
      }, 80);
    }, 30);
  }

  function showCoach(data, opts) {
    opts = opts || {};
    if (!data || !data.count || data.count < 1) return;
    if (!opts.force && wasDismissed(data.userId, data.fingerprint)) return;
    if (opts.force) {
      removeCoachDom();
    } else if (document.getElementById('pv-coach-root')) {
      return;
    }

    // Keep pending action cards remembered across refreshes.
    rememberHighlights(data);

    var tries = 0;
    function attempt() {
      var anchor = findAnchor();
      if (!anchor) {
        tries += 1;
        if (tries < 20) setTimeout(attempt, 250);
        return;
      }

      scrollSidebarTo(anchor);
      anchor.classList.add('pv-coach-target');
      var item = anchor.closest('.sidebar-notif-item');
      if (item) item.classList.add('pv-coach-target-wrap');
      var badge = ensureBadge(anchor, data.badge);

      var root = document.createElement('div');
      root.id = 'pv-coach-root';
      root.className = 'pv-coach-root';
      root.setAttribute('role', 'dialog');
      root.setAttribute('aria-modal', 'true');
      root.setAttribute('aria-label', data.title || 'Payment voucher action');
      root.innerHTML =
        '<div class="pv-coach-scrim" data-pv-coach-dismiss="1"></div>' +
        '<svg class="pv-coach-guide" aria-hidden="true">' +
        '<defs>' +
        '<marker id="pvCoachArrow" markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto">' +
        '<path d="M0,0 L8,3 L0,6 Z" fill="#1a73e8"></path>' +
        '</marker>' +
        '</defs>' +
        '<path class="pv-coach-guide-path" fill="none" stroke="#1a73e8" stroke-width="2.25" stroke-linecap="round" marker-end="url(#pvCoachArrow)"></path>' +
        '</svg>' +
        '<div class="pv-coach-card" role="document">' +
        '<span class="pv-coach-beak" aria-hidden="true"></span>' +
        '<button type="button" class="pv-coach-close" data-pv-coach-dismiss="1" aria-label="Dismiss">' +
        '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">' +
        '<path d="M3 3l8 8M11 3L3 11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>' +
        '</svg>' +
        '</button>' +
        '<div class="pv-coach-head">' +
        '<span class="pv-coach-icon" aria-hidden="true">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none">' +
        '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke="#1a73e8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
        '<path d="M13.73 21a2 2 0 0 1-3.46 0" stroke="#1a73e8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
        '</svg>' +
        '</span>' +
        '<div class="pv-coach-head-text">' +
        '<p class="pv-coach-kicker">Payment vouchers</p>' +
        '<h3 class="pv-coach-title">' +
        escapeHtml(data.title || 'Action needed') +
        '</h3>' +
        '</div>' +
        '</div>' +
        '<p class="pv-coach-body">' +
        escapeHtml(data.body || '') +
        '</p>' +
        '<div class="pv-coach-actions">' +
        '<button type="button" class="pv-coach-btn pv-coach-btn--ghost" data-pv-coach-dismiss="1">' +
        escapeHtml(data.secondary || 'Got it') +
        '</button>' +
        '<button type="button" class="pv-coach-btn pv-coach-btn--primary" data-pv-coach-go="1" style="border-radius:999px!important;padding:8px 22px!important;min-height:36px;display:inline-flex;align-items:center;gap:8px;">' +
        '<span>' +
        escapeHtml(data.action || 'View') +
        '</span>' +
        '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">' +
        '<path d="M3 7h8M8 3.5L11.5 7 8 10.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>' +
        '</svg>' +
        '</button>' +
        '</div>' +
        '</div>';

      document.body.appendChild(root);
      document.documentElement.classList.add('pv-coach-open');

      var card = root.querySelector('.pv-coach-card');
      var guide = root.querySelector('.pv-coach-guide');

      function place() {
        var a = findAnchor() || anchor;
        positionCard(card, a);
        var badgeEl = badge || ensureBadge(a, data.badge);
        drawGuide(guide, badgeEl || a, card);
      }

      function dismiss() {
        markDismissed(data.userId, data.fingerprint);
        anchor.classList.remove('pv-coach-target');
        if (item) item.classList.remove('pv-coach-target-wrap');
        if (badge && badge.parentNode) badge.parentNode.removeChild(badge);
        document.documentElement.classList.remove('pv-coach-open');
        if (root.parentNode) root.parentNode.removeChild(root);
        window.removeEventListener('resize', place);
        document.removeEventListener('keydown', onKey);
      }

      function onKey(ev) {
        if (ev.key === 'Escape') dismiss();
      }

      root.addEventListener('click', function (ev) {
        var t = ev.target;
        if (!t) return;
        if (
          t.getAttribute('data-pv-coach-dismiss') === '1' ||
          (t.closest && t.closest('[data-pv-coach-dismiss="1"]'))
        ) {
          dismiss();
          return;
        }
        if (
          t.getAttribute('data-pv-coach-go') === '1' ||
          (t.closest && t.closest('[data-pv-coach-go="1"]'))
        ) {
          if (ev.stopPropagation) ev.stopPropagation();
          if (ev.preventDefault) ev.preventDefault();

          markDismissed(data.userId, data.fingerprint);
          document.documentElement.classList.remove('pv-coach-open');
          if (root.parentNode) root.parentNode.removeChild(root);
          anchor.classList.remove('pv-coach-target');
          if (item) item.classList.remove('pv-coach-target-wrap');
          if (badge && badge.parentNode) badge.parentNode.removeChild(badge);
          window.removeEventListener('resize', place);
          document.removeEventListener('keydown', onKey);

          window.__ultitechIgnoreNotifOutsideClickUntil = Date.now() + 600;

          if (data.openNotifications || !data.href || data.count > 1) {
            openNotificationsAndHighlight(data);
            return;
          }
          if (data.href) {
            window.location.assign(data.href);
          }
        }
      });

      document.addEventListener('keydown', onKey);
      window.addEventListener('resize', place);
      setTimeout(place, 60);
      setTimeout(place, 320);
    }

    attempt();
  }

  // Clear persisted highlight only when the user opens that notification.
  document.addEventListener(
    'click',
    function (ev) {
      var card = ev.target && ev.target.closest ? ev.target.closest('.nc-card') : null;
      if (!card || !card.closest('#notif-dd-list')) return;
      forgetHighlightFromCard(card);
    },
    true
  );

  function boot() {
    try {
      sessionStorage.removeItem('ultitech_pv_coach_dismissed');
    } catch (e) {}

    applyPersistedHighlights();
    setTimeout(applyPersistedHighlights, 400);

    var force = false;
    var reshow = false;
    var onViewVoucher = false;
    try {
      force = /(?:\?|&)pv_coach=1(?:&|$)/.test(String(window.location.search || ''));
      reshow = sessionStorage.getItem('ultitech_pv_coach_reshow') === '1';
      onViewVoucher = /view-voucher\.php/i.test(String(window.location.pathname || ''));
      if (reshow) {
        sessionStorage.removeItem('ultitech_pv_coach_reshow');
      }
      if (force || reshow || onViewVoucher) {
        Object.keys(sessionStorage).forEach(function (k) {
          if (k.indexOf('ultitech_pv_coach_dismissed') === 0) sessionStorage.removeItem(k);
        });
      }
    } catch (e2) {}

    var mustShow = force || reshow || onViewVoucher;

    function stripCoachQuery() {
      try {
        if (!/(?:\?|&)pv_coach=1(?:&|$)/.test(String(window.location.search || ''))) return;
        var u = new URL(window.location.href);
        u.searchParams.delete('pv_coach');
        window.history.replaceState({}, '', u.toString());
      } catch (e3) {}
    }

    function showFromPayloadFallback() {
      try {
        var raw = sessionStorage.getItem('ultitech_pv_coach_reshow_payload');
        if (!raw) return false;
        sessionStorage.removeItem('ultitech_pv_coach_reshow_payload');
        var payload = JSON.parse(raw);
        if (!payload || !payload.remaining_count) return false;
        var first = (payload.remaining && payload.remaining[0]) || null;
        var count = Number(payload.remaining_count || 0);
        if (count < 1) return false;
        var data = {
          ok: true,
          count: count,
          fingerprint: 'reshow|' + count + '|' + (first && first.id ? first.id : 0),
          userId: 0,
          title: count === 1 ? (first && first.action_label ? first.action_label : 'Action needed') : 'Signatures needed',
          body:
            count === 1
              ? '1 voucher remaining' +
                (first && first.voucher_no ? '  open voucher ' + first.voucher_no + ' to finish.' : '.')
              : count + ' vouchers remaining. Continue with the next one.',
          action: count === 1 ? 'Open voucher' : 'View',
          href: count === 1 && first && first.view_url ? first.view_url : '',
          openNotifications: count > 1,
          voucherIds: (payload.remaining || []).map(function (t) {
            return t && t.id ? t.id : 0;
          }).filter(Boolean),
          voucherNos: (payload.remaining || []).map(function (t) {
            return t && t.voucher_no ? String(t.voucher_no).toUpperCase() : '';
          }).filter(Boolean),
          secondary: 'Got it',
          badge: count > 99 ? '99+' : String(count),
          tasks: payload.remaining || [],
        };
        rememberHighlights(data);
        applyPersistedHighlights();
        showCoach(data, { force: true });
        stripCoachQuery();
        return true;
      } catch (e4) {
        return false;
      }
    }

    function handleCoachData(data, attempt) {
      if (!data || !data.ok || !data.count) {
        if (mustShow && attempt < 2) {
          setTimeout(function () {
            fetchCoach(attempt + 1);
          }, 700);
          return;
        }
        if (mustShow && showFromPayloadFallback()) {
          return;
        }
        clearAllHighlights();
        if (force) {
          console.warn('[PV coach] No pending voucher tasks for this user.', data);
        }
        stripCoachQuery();
        return;
      }
      try {
        sessionStorage.removeItem('ultitech_pv_coach_reshow_payload');
      } catch (e5) {}
      rememberHighlights(data);
      applyPersistedHighlights();
      setTimeout(function () {
        showCoach(data, { force: mustShow });
        stripCoachQuery();
      }, mustShow ? 450 : 400);
    }

    function fetchCoach(attempt) {
      attempt = attempt || 0;
      var bust = mustShow || attempt > 0;
      var url = apiUrl + (bust ? (apiUrl.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now() : '');
      fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          handleCoachData(data, attempt);
        })
        .catch(function (err) {
          if (mustShow && attempt < 2) {
            setTimeout(function () {
              fetchCoach(attempt + 1);
            }, 700);
            return;
          }
          if (mustShow && showFromPayloadFallback()) {
            return;
          }
          if (force) console.warn('[PV coach] API failed', err);
          applyPersistedHighlights();
          stripCoachQuery();
        });
    }

    fetchCoach(0);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
