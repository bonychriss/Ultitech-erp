/**
 * Sitewide cookie consent banner.
 * Persists choices in localStorage + a first-party cookie so all pages share consent.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'erp_cookie_consent_v1';
  var COOKIE_NAME = 'erp_cookie_consent';
  var COOKIE_DAYS = 365;
  var VERSION = 1;

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function readStored() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) {
        raw = readCookie(COOKIE_NAME);
      }
      if (!raw) return null;
      var data = JSON.parse(raw);
      if (!data || typeof data !== 'object' || Number(data.version) !== VERSION) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  function readCookie(name) {
    var raw = typeof document !== 'undefined' ? document['cookie'] : '';
    var parts = String(raw || '').split(';');
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i].trim();
      if (p.indexOf(name + '=') === 0) {
        try {
          return decodeURIComponent(p.slice(name.length + 1));
        } catch (e) {
          return null;
        }
      }
    }
    return null;
  }

  function writeCookie(value) {
    var maxAge = COOKIE_DAYS * 24 * 60 * 60;
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document['cookie'] =
      COOKIE_NAME +
      '=' +
      encodeURIComponent(value) +
      '; Path=/; Max-Age=' +
      maxAge +
      '; SameSite=Lax' +
      secure;
  }

  function saveConsent(prefs) {
    var payload = {
      version: VERSION,
      necessary: true,
      analytics: !!prefs.analytics,
      marketing: !!prefs.marketing,
      ts: Date.now(),
    };
    var raw = JSON.stringify(payload);
    try {
      localStorage.setItem(STORAGE_KEY, raw);
    } catch (e) {
      /* ignore quota / private mode */
    }
    writeCookie(raw);
    window.__ERP_COOKIE_CONSENT__ = payload;
    try {
      window.dispatchEvent(new CustomEvent('erp:cookie-consent', { detail: payload }));
    } catch (e) {
      /* older browsers */
    }
    return payload;
  }

  function applyToggles(prefs) {
    var analytics = qs('#erp-cc-analytics');
    var marketing = qs('#erp-cc-marketing');
    if (analytics) analytics.checked = !!(prefs && prefs.analytics);
    if (marketing) marketing.checked = !!(prefs && prefs.marketing);
  }

  function openBanner() {
    var banner = qs('#erp-cc-banner');
    var reopen = qs('#erp-cc-reopen');
    if (banner) banner.classList.add('is-open');
    if (reopen) reopen.classList.remove('is-visible');
  }

  function closeBanner() {
    var banner = qs('#erp-cc-banner');
    var reopen = qs('#erp-cc-reopen');
    if (banner) banner.classList.remove('is-open');
    if (reopen) reopen.classList.add('is-visible');
  }

  function openSettings() {
    var overlay = qs('#erp-cc-overlay');
    if (overlay) {
      overlay.classList.add('is-open');
      overlay.setAttribute('aria-hidden', 'false');
    }
    var existing = readStored();
    applyToggles(existing || { analytics: false, marketing: false });
  }

  function closeSettings() {
    var overlay = qs('#erp-cc-overlay');
    if (overlay) {
      overlay.classList.remove('is-open');
      overlay.setAttribute('aria-hidden', 'true');
    }
  }

  function acceptAll() {
    saveConsent({ analytics: true, marketing: true });
    closeSettings();
    closeBanner();
  }

  function rejectAll() {
    saveConsent({ analytics: false, marketing: false });
    closeSettings();
    closeBanner();
  }

  function saveSettings() {
    var analytics = qs('#erp-cc-analytics');
    var marketing = qs('#erp-cc-marketing');
    saveConsent({
      analytics: !!(analytics && analytics.checked),
      marketing: !!(marketing && marketing.checked),
    });
    closeSettings();
    closeBanner();
  }

  function bind() {
    var root = qs('#erp-cookie-consent');
    if (!root || root.getAttribute('data-bound') === '1') return;
    root.setAttribute('data-bound', '1');

    var accept = qs('[data-erp-cc="accept"]', root);
    var reject = qs('[data-erp-cc="reject"]', root);
    var dismiss = qs('[data-erp-cc="dismiss"]', root);
    var settings = qs('[data-erp-cc="settings"]', root);
    var save = qs('[data-erp-cc="save"]', root);
    var rejectPanel = qs('[data-erp-cc="reject-panel"]', root);
    var acceptPanel = qs('[data-erp-cc="accept-panel"]', root);
    var close = qs('[data-erp-cc="close-settings"]', root);
    var overlay = qs('#erp-cc-overlay', root);
    var reopen = qs('#erp-cc-reopen', root);

    if (accept) accept.addEventListener('click', acceptAll);
    if (reject) reject.addEventListener('click', rejectAll);
    if (dismiss) dismiss.addEventListener('click', rejectAll);
    if (rejectPanel) rejectPanel.addEventListener('click', rejectAll);
    if (acceptPanel) acceptPanel.addEventListener('click', acceptAll);
    if (save) save.addEventListener('click', saveSettings);
    if (settings) settings.addEventListener('click', openSettings);
    if (close) close.addEventListener('click', closeSettings);
    if (reopen) reopen.addEventListener('click', function () {
      openBanner();
      openSettings();
    });
    if (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeSettings();
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeSettings();
    });

    var existing = readStored();
    window.__ERP_COOKIE_CONSENT__ = existing;
    if (!existing) {
      // Banner ships with is-open so it is visible even if JS is late.
      openBanner();
    } else {
      closeBanner();
      try {
        window.dispatchEvent(new CustomEvent('erp:cookie-consent', { detail: existing }));
      } catch (e) {
        /* ignore */
      }
    }
  }

  // Allow ?reset_cookies=1 to clear consent for testing.
  try {
    var params = new URLSearchParams(window.location.search || '');
    if (params.get('reset_cookies') === '1') {
      try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
      document['cookie'] = COOKIE_NAME + '=; Path=/; Max-Age=0; SameSite=Lax';
      window.__ERP_COOKIE_CONSENT__ = null;
    }
  } catch (e) {
    /* ignore */
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }

  window.erpCookieConsent = {
    open: function () {
      openBanner();
      openSettings();
    },
    get: readStored,
  };
})();
