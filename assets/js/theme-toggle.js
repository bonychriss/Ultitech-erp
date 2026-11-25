/**
 * System-wide liquid-glass theme toggle.
 * Syncs every [data-erp-theme-toggle] with html[data-theme],
 * and adds press-squish feedback like the Figma liquid-glass demos.
 *
 * Preference (localStorage "theme"): light | dark | system
 * Applied (html data-theme): light | dark
 * Preference mirror (html data-theme-pref): light | dark | system
 */
(function (global) {
  'use strict';

  var STORAGE_KEY = 'theme';
  var booted = false;
  var mediaQuery = null;
  var mediaBound = false;

  function normalizePref(value) {
    if (value === 'dark' || value === 'system') return value;
    return 'light';
  }

  function systemResolved() {
    try {
      if (global.matchMedia && global.matchMedia('(prefers-color-scheme: dark)').matches) {
        return 'dark';
      }
    } catch (e) { /* ignore */ }
    return 'light';
  }

  function resolveTheme(pref) {
    pref = normalizePref(pref);
    return pref === 'system' ? systemResolved() : pref;
  }

  function getPreference() {
    try {
      var stored = localStorage.getItem(STORAGE_KEY);
      if (stored === 'dark' || stored === 'system' || stored === 'light') return stored;
    } catch (e) { /* private mode */ }
    var attr = document.documentElement.getAttribute('data-theme-pref');
    if (attr === 'dark' || attr === 'system' || attr === 'light') return attr;
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  }

  function currentTheme() {
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  }

  function syncMediaListener(pref) {
    if (!global.matchMedia) return;
    if (!mediaQuery) {
      try { mediaQuery = global.matchMedia('(prefers-color-scheme: dark)'); } catch (e) { return; }
    }
    if (!mediaQuery) return;

    if (pref === 'system') {
      if (!mediaBound) {
        var onChange = function () {
          if (getPreference() !== 'system') return;
          setTheme('system', { toast: false, fromMedia: true });
        };
        if (typeof mediaQuery.addEventListener === 'function') {
          mediaQuery.addEventListener('change', onChange);
        } else if (typeof mediaQuery.addListener === 'function') {
          mediaQuery.addListener(onChange);
        }
        mediaBound = true;
        mediaQuery._erpOnChange = onChange;
      }
    }
  }

  function setTheme(theme, opts) {
    opts = opts || {};
    var pref = normalizePref(theme);
    var next = resolveTheme(pref);

    document.documentElement.setAttribute('data-theme-pref', pref);
    document.documentElement.setAttribute('data-theme', next);
    try {
      localStorage.setItem(STORAGE_KEY, pref);
    } catch (e) { /* private mode */ }

    syncMediaListener(pref);

    document.querySelectorAll('[data-erp-theme-toggle]').forEach(function (btn) {
      var isDark = next === 'dark';
      btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
      btn.setAttribute('data-theme-state', next);
      btn.setAttribute('data-theme-pref', pref);
      btn.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
      btn.title = isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode';
      btn.classList.toggle('is-dark', isDark);
      btn.classList.toggle('is-light', !isDark);

      var icon = btn.querySelector('i');
      if (icon && !btn.classList.contains('theme-toggle-glass')) {
        icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
      }

      var label = btn.querySelector('.sidebar-text');
      if (label) {
        label.textContent = isDark ? 'Light Mode' : 'Dark Mode';
      }

      btn.classList.add('is-animating');
      global.setTimeout(function () {
        btn.classList.remove('is-animating');
      }, 520);
    });

    try {
      global.dispatchEvent(new CustomEvent('themeChanged', {
        detail: { theme: next, preference: pref },
      }));
    } catch (e2) { /* ignore */ }

    if (opts.toast === false || opts.fromMedia) return;
    if (pref === 'system') {
      showToast('Matching device theme (' + (next === 'dark' ? 'Dark' : 'Light') + ')');
    } else {
      showToast(next === 'dark' ? 'Dark theme activated' : 'Light theme activated');
    }
  }

  function showToast(message) {
    if (typeof global.Swal !== 'undefined') {
      global.Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: message,
        showConfirmButton: false,
        timer: 1600,
        timerProgressBar: true,
      });
      return;
    }
    var toast = document.createElement('div');
    toast.textContent = message;
    toast.setAttribute('role', 'status');
    toast.style.cssText = [
      'position:fixed', 'top:20px', 'right:20px', 'z-index:99999',
      'background:#10b981', 'color:#fff', 'padding:12px 20px',
      'border-radius:10px', 'font:600 13px/1.3 DM Sans,Segoe UI,sans-serif',
      'box-shadow:0 10px 24px rgba(15,23,42,.18)', 'transition:opacity .3s ease',
    ].join(';');
    document.body.appendChild(toast);
    setTimeout(function () {
      toast.style.opacity = '0';
      setTimeout(function () {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 300);
    }, 1600);
  }

  function clearPress(btn) {
    btn.classList.remove('is-pressing');
  }

  function onToggleClick(e) {
    e.preventDefault();
    var btn = e.currentTarget;
    clearPress(btn);
    // Header toggle always picks an explicit theme (exits System).
    setTheme(currentTheme() === 'dark' ? 'light' : 'dark');
  }

  function bindButton(btn) {
    if (btn.getAttribute('data-erp-theme-bound') === '1') return;
    btn.setAttribute('data-erp-theme-bound', '1');

    btn.addEventListener('pointerdown', function () {
      btn.classList.add('is-pressing');
    });
    btn.addEventListener('pointerup', function () { clearPress(btn); });
    btn.addEventListener('pointercancel', function () { clearPress(btn); });
    btn.addEventListener('pointerleave', function () { clearPress(btn); });
    btn.addEventListener('click', onToggleClick);
  }

  function bindAll() {
    document.querySelectorAll('[data-erp-theme-toggle]').forEach(bindButton);
    setTheme(getPreference(), { toast: false });
  }

  function boot() {
    if (booted) {
      bindAll();
      return;
    }
    booted = true;
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', bindAll);
    } else {
      bindAll();
    }
  }

  global.erpThemeToggle = {
    setTheme: setTheme,
    currentTheme: currentTheme,
    getPreference: getPreference,
    resolveTheme: resolveTheme,
    refresh: bindAll,
  };

  boot();
})(window);
