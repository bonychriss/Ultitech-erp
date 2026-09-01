(function () {
  'use strict';

  if (window.__ULTITECH_DESKTOP_UPDATE_BANNER__) {
    return;
  }
  window.__ULTITECH_DESKTOP_UPDATE_BANNER__ = true;

  var BANNER_ID = 'ultitech-update-banner';
  var GIFT_ICON =
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' +
    '<rect x="3" y="8" width="18" height="13" rx="1.5"/>' +
    '<path d="M12 8V21M12 8c-1.8 0-3-1.2-3-3s1.2-3 3-3 3 1.2 3 3-1.2 3-3 3zM3 12h18M7.5 8C6 6.5 6 4 8 4s2.5 2 4 4M16.5 8C18 6.5 18 4 16 4s-2.5 2-4 4"/>' +
    '</svg>';

  var cfg = window.__ERP_DESKTOP_UPDATE__ || {};
  var latestVersion = String(cfg.latestVersion || '').trim();
  var downloadUrl = String(cfg.downloadUrl || '').trim();

  function getClient() {
    return window.ultitechClient && window.ultitechClient.platform === 'desktop'
      ? window.ultitechClient
      : null;
  }

  var state = null;
  var version = null;

  function dismissKey(ver) {
    return 'ultitech_desktop_update_dismiss_' + (ver || 'latest');
  }

  function isDismissed(ver) {
    try {
      return localStorage.getItem(dismissKey(ver)) === '1';
    } catch (e) {
      return false;
    }
  }

  function writeDismissed(ver) {
    try {
      localStorage.setItem(dismissKey(ver), '1');
    } catch (e) {
      /* ignore */
    }
  }

  function compareVersions(a, b) {
    var pa = String(a || '0').split('.').map(function (n) { return parseInt(n, 10) || 0; });
    var pb = String(b || '0').split('.').map(function (n) { return parseInt(n, 10) || 0; });
    var len = Math.max(pa.length, pb.length);
    for (var i = 0; i < len; i += 1) {
      var da = pa[i] || 0;
      var db = pb[i] || 0;
      if (da > db) return 1;
      if (da < db) return -1;
    }
    return 0;
  }

  function removeBanner() {
    var existing = document.getElementById(BANNER_ID);
    if (existing) {
      existing.remove();
    }
    document.body.classList.remove('ultitech-update-banner-open');
    state = null;
  }

  function availableMessage() {
    var v = version || latestVersion;
    return v ? 'Desktop app ' + v + ' available' : 'Desktop app update available';
  }

  function messageForState() {
    if (state === 'downloading') {
      return 'Downloading updateù';
    }
    if (state === 'ready') {
      return version ? 'Update ' + version + ' ready to install' : 'Update ready to install';
    }
    return availableMessage();
  }

  function primaryLabel() {
    if (state === 'ready') {
      return 'Install Now';
    }
    if (state === 'downloading') {
      return 'Downloadingù';
    }
    return 'Download';
  }

  function renderBanner(extraClass) {
    if (!state) {
      removeBanner();
      return;
    }

    var banner = document.getElementById(BANNER_ID);
    if (!banner) {
      banner = document.createElement('div');
      banner.id = BANNER_ID;
      banner.setAttribute('role', 'status');
      document.body.appendChild(banner);
    }

    banner.className = extraClass || '';
    banner.innerHTML =
      '<div class="ultitech-update-left">' +
      '<span class="ultitech-update-icon">' + GIFT_ICON + '</span>' +
      '<span class="ultitech-update-text">' + messageForState() + '</span>' +
      '</div>' +
      '<div class="ultitech-update-actions">' +
      '<button type="button" class="ultitech-btn-later" data-action="later">Later</button>' +
      '<button type="button" class="ultitech-btn-primary" data-action="primary">' + primaryLabel() + '</button>' +
      '</div>';

    document.body.classList.add('ultitech-update-banner-open');

    var laterBtn = banner.querySelector('[data-action="later"]');
    var primaryBtn = banner.querySelector('[data-action="primary"]');

    laterBtn.addEventListener('click', function () {
      writeDismissed(version || latestVersion);
      removeBanner();
      var client = getClient();
      if (client && client.dismissUpdate) {
        client.dismissUpdate();
      }
    });

    primaryBtn.disabled = state === 'downloading';
    primaryBtn.addEventListener('click', function () {
      var client = getClient();
      if (client) {
        if (state === 'ready') {
          client.installUpdate && client.installUpdate();
          return;
        }
        if (state === 'available') {
          state = 'downloading';
          renderBanner();
          client.downloadUpdate && client.downloadUpdate();
        }
        return;
      }
      if (downloadUrl) {
        window.location.href = downloadUrl;
      }
    });
  }

  function showAvailable(ver) {
    var client = getClient();
    version = ver || latestVersion || null;
    if (!version && !client) {
      return;
    }
    if (isDismissed(version || latestVersion)) {
      return;
    }
    if (client && latestVersion) {
      var installed = client.version || '0';
      if (compareVersions(installed, latestVersion) >= 0) {
        return;
      }
      version = latestVersion;
    }
    state = 'available';
    renderBanner();
  }

  function onDesktopEvent(event) {
    var detail = (event && event.detail) || {};
    var type = detail.type;

    if (type === 'available') {
      version = detail.version || latestVersion || null;
      if (isDismissed(version || latestVersion)) {
        removeBanner();
        return;
      }
      if (!getClient()) {
        return;
      }
      state = 'available';
      renderBanner();
      return;
    }

    if (type === 'downloading') {
      state = 'downloading';
      if (detail.version) {
        version = String(detail.version);
      }
      renderBanner();
      if (typeof detail.percent === 'number') {
        var text = document.querySelector('#' + BANNER_ID + ' .ultitech-update-text');
        if (text) {
          text.textContent = 'Downloading updateù ' + Math.round(detail.percent) + '%';
        }
      }
      return;
    }

    if (type === 'ready') {
      state = 'ready';
      version = detail.version || version || latestVersion || null;
      renderBanner();
      return;
    }

    if (type === 'dismiss') {
      removeBanner();
      return;
    }

    if (type === 'up-to-date') {
      removeBanner();
      var toast = document.createElement('div');
      toast.id = BANNER_ID;
      toast.className = 'ultitech-update-banner--toast';
      toast.setAttribute('role', 'status');
      toast.innerHTML =
        '<div class="ultitech-update-left">' +
        '<span class="ultitech-update-icon">' + GIFT_ICON + '</span>' +
        '<span class="ultitech-update-text">You have the latest version' +
        (detail.version ? ' (' + detail.version + ')' : '') +
        '</span></div>';
      document.body.appendChild(toast);
      document.body.classList.add('ultitech-update-banner-open');
      window.setTimeout(function () {
        toast.remove();
        document.body.classList.remove('ultitech-update-banner-open');
      }, 3500);
    }
  }

  window.addEventListener('ultitech:desktop-update', onDesktopEvent);

  function replayPendingEvent() {
    try {
      var pending = window.__ULTITECH_DESKTOP_UPDATE_PENDING__;
      if (pending && pending.type) {
        onDesktopEvent({ detail: pending });
      }
    } catch (e) {
      /* ignore */
    }
  }

  function boot(attempt) {
    var client = getClient();
    if (!client) {
      if ((attempt || 0) < 30) {
        window.setTimeout(function () {
          boot((attempt || 0) + 1);
        }, 100);
      }
      return;
    }

    replayPendingEvent();

    if (!latestVersion) {
      client.checkForUpdates && client.checkForUpdates();
      return;
    }

    var installed = client.version || '0';
    if (compareVersions(installed, latestVersion) < 0 && !isDismissed(latestVersion)) {
      showAvailable(latestVersion);
      return;
    }

    client.checkForUpdates && client.checkForUpdates();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
