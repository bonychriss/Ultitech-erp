(function () {
  'use strict';

  function b64urlToBuffer(value) {
    var str = String(value || '')
      .replace(/-/g, '+')
      .replace(/_/g, '/');
    var pad = str.length % 4 === 0 ? '' : '='.repeat(4 - (str.length % 4));
    var binary = window.atob(str + pad);
    var bytes = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
  }

  function bufferToB64url(buffer) {
    var bytes = buffer instanceof ArrayBuffer ? new Uint8Array(buffer) : new Uint8Array(buffer.buffer || buffer);
    var binary = '';
    for (var i = 0; i < bytes.length; i += 1) binary += String.fromCharCode(bytes[i]);
    return window
      .btoa(binary)
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=+$/g, '');
  }

  function coerceToBuffer(value) {
    if (!value) return new ArrayBuffer(0);
    if (value instanceof ArrayBuffer) return value;
    if (ArrayBuffer.isView(value)) {
      return value.buffer.slice(value.byteOffset, value.byteOffset + value.byteLength);
    }
    if (typeof value === 'string') {
      var m = value.match(/^=\?BINARY\?B\?(.+)\?=$/);
      if (m) {
        var binary = window.atob(m[1]);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
        return bytes.buffer;
      }
      return b64urlToBuffer(value);
    }
    if (typeof value === 'object' && value !== null) {
      if (typeof value.base64url === 'string') return b64urlToBuffer(value.base64url);
      if (typeof value.base64 === 'string') return b64urlToBuffer(value.base64);
      if (typeof value.data === 'string') return coerceToBuffer(value.data);
    }
    return new ArrayBuffer(0);
  }

  function hostLooksLikeIp() {
    var host = String(window.location.hostname || '');
    return /^\d{1,3}(\.\d{1,3}){3}$/.test(host) || host.indexOf(':') !== -1;
  }

  function isWebAuthnSupported() {
    return (
      typeof window !== 'undefined' &&
      !!window.isSecureContext &&
      typeof window.PublicKeyCredential !== 'undefined' &&
      navigator.credentials &&
      typeof navigator.credentials.create === 'function'
    );
  }

  function isMobilePhone() {
    var ua = String(navigator.userAgent || '');
    if (/Android|iPhone|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i.test(ua)) return true;
    if (/iPad/i.test(ua)) return true;
    if (navigator.platform === 'MacIntel' && Number(navigator.maxTouchPoints || 0) > 1) return true;
    try {
      if (window.matchMedia && window.matchMedia('(pointer: coarse) and (max-width: 900px)').matches) {
        return true;
      }
    } catch (e) {}
    return false;
  }

  function supportBlockReason() {
    if (!window.isSecureContext) {
      return 'Phone unlock needs HTTPS (or localhost). Open your company HTTPS URL ? plain http:// or a LAN IP will not work.';
    }
    if (hostLooksLikeIp()) {
      return 'Phone unlock cannot use a raw IP address. Open the site with your company domain over HTTPS.';
    }
    if (typeof window.PublicKeyCredential === 'undefined') {
      return 'This browser does not support phone unlock (Face ID, fingerprint, or passcode).';
    }
    return '';
  }

  function guessDeviceLabel() {
    var ua = String(navigator.userAgent || '');
    if (/iPhone/i.test(ua)) return 'iPhone';
    if (/iPad/i.test(ua)) return 'iPad';
    if (/Android/i.test(ua)) return 'Android phone';
    if (/Windows/i.test(ua)) return 'Windows device';
    if (/Mac OS|Macintosh/i.test(ua)) return 'Mac';
    return 'Smartphone';
  }

  function prepareCreateOptions(publicKey, mode) {
    var pk = Object.assign({}, publicKey || {});
    var usePasskey = mode === 'passkey';
    pk.challenge = coerceToBuffer(pk.challenge);
    if (pk.user) {
      pk.user = Object.assign({}, pk.user, { id: coerceToBuffer(pk.user.id) });
    }
    if (Array.isArray(pk.excludeCredentials)) {
      pk.excludeCredentials = pk.excludeCredentials
        .map(function (cred) {
          return Object.assign({}, cred, {
            id: coerceToBuffer(cred.id),
            transports: Array.isArray(cred.transports) && cred.transports.length
              ? cred.transports
              : ['internal', 'hybrid', 'usb', 'nfc', 'ble'],
          });
        })
        .filter(function (cred) {
          return cred.id && cred.id.byteLength > 0;
        });
    }
    // Safari is happier without unknown extension bags from the PHP library.
    if (pk.extensions) {
      delete pk.extensions;
    }
    if (!pk.authenticatorSelection) {
      pk.authenticatorSelection = {};
    }
    pk.authenticatorSelection = Object.assign({}, pk.authenticatorSelection, {
      userVerification: 'preferred',
      residentKey: usePasskey ? 'preferred' : 'discouraged',
      requireResidentKey: false,
    });
    if (usePasskey) {
      // Omit attachment so Chrome can offer Google Password Manager (not only NFC/QR security keys).
      delete pk.authenticatorSelection.authenticatorAttachment;
    } else {
      pk.authenticatorSelection.authenticatorAttachment = 'platform';
    }
    // Dead credentials after Face ID / screen repair can block Safari create.
    if (!usePasskey) pk.excludeCredentials = [];
    return pk;
  }

  function formatWebAuthnError(err) {
    var name = (err && err.name) || '';
    var msg = (err && err.message) || 'Enrollment failed.';
    if (name === 'NotAllowedError') {
      return 'Unlock was cancelled or blocked. Tap Enroll again and approve with Face ID, fingerprint, or your phone passcode.';
    }
    if (name === 'InvalidStateError') {
      return 'This phone is already enrolled for another account, or the authenticator rejected a duplicate. Remove an old enrollment and try again.';
    }
    if (name === 'SecurityError' || name === 'NotSupportedError') {
      return supportBlockReason() || 'This browser blocked phone unlock for this site. Use your company HTTPS domain.';
    }
    if (name === 'AbortError') {
      return 'Enrollment was interrupted. Tap Enroll this phone again.';
    }
    return msg;
  }

  function toast(type, title) {
    var kind = type === 'error' ? 'error' : type === 'info' ? 'info' : 'success';
    var message = String(title || '');
    if (!message) return;

    if (typeof window.psNotify === 'function') {
      window.psNotify(message, kind, kind === 'error' ? 5500 : 3200);
      return;
    }

    var existing = document.getElementById('psToast');
    if (existing) existing.remove();
    var el = document.createElement('div');
    el.className = 'ps-toast ps-toast--' + kind;
    el.id = 'psToast';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    var icon =
      kind === 'error' ? 'fa-circle-exclamation' : kind === 'info' ? 'fa-circle-info' : 'fa-circle-check';
    el.innerHTML =
      '<i class="fas ' +
      icon +
      '" aria-hidden="true"></i><span></span><button type="button" class="ps-toast-close" aria-label="Dismiss">&times;</button>';
    el.querySelector('span').textContent = message;
    el.querySelector('.ps-toast-close').addEventListener('click', function () {
      el.remove();
    });
    document.body.appendChild(el);
    requestAnimationFrame(function () {
      el.classList.add('is-in');
    });
    setTimeout(
      function () {
        el.classList.remove('is-in');
        setTimeout(function () {
          if (el.parentNode) el.remove();
        }, 250);
      },
      kind === 'error' ? 5500 : 3200
    );
  }

  function postAction(apiUrl, payload) {
    return fetch(apiUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(payload),
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try {
          data = text ? JSON.parse(text) : null;
        } catch (e) {
          data = null;
        }
        if (!res.ok || !data) {
          var msg = (data && data.message) || '';
          if (!msg && text) {
            var stripped = String(text).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            if (stripped) msg = stripped.slice(0, 180);
          }
          if (!msg) msg = 'Request failed (' + res.status + ')';
          else msg = msg + ' (' + res.status + ')';
          throw new Error(msg);
        }
        return data;
      });
    });
  }

  function render(root, state) {
    var bio = state.biometric || {};
    var devices = Array.isArray(bio.devices) ? bio.devices : [];
    var max = Number(bio.max || 2);
    var canEnrollMore = devices.length < max;
    var supported = isWebAuthnSupported() && !hostLooksLikeIp();
    var blockReason = supportBlockReason();
    var required = !!bio.required;
    var onPhone = isMobilePhone();

    var statusHtml =
      '<span class="ps-bio-pill ' +
      (devices.length ? 'is-ok' : 'is-warn') +
      '">' +
      (devices.length ? devices.length + ' enrolled' : 'Not enrolled') +
      '</span>';

    var statusSlot = document.getElementById('psBioStatusSlot');
    if (statusSlot) statusSlot.innerHTML = statusHtml;

    var devicesHtml = '';
    if (devices.length) {
      devicesHtml =
        '<ul class="ps-bio-devices">' +
        devices
          .map(function (device) {
            return (
              '<li>' +
              '<span><strong>' +
              escapeHtml(device.device_label || 'Smartphone') +
              '</strong>' +
              (device.last_used_at
                ? '<em> ? last used ' + escapeHtml(String(device.last_used_at).slice(0, 16)) + '</em>'
                : '') +
              '</span>' +
              '<button type="button" class="ps-bio-remove" data-device-id="' +
              Number(device.id || 0) +
              '"' +
              (state.busy ? ' disabled' : '') +
              '>Remove</button>' +
              '</li>'
            );
          })
          .join('') +
        '</ul>';
    }

    var helpHtml = '';
    var actionHtml = '';

    if (!onPhone) {
      helpHtml =
        '<div class="ps-bio-desktop-note">' +
        '<i class="fas fa-mobile-screen-button" aria-hidden="true"></i>' +
        '<div>' +
        '<strong>Use your phone to enroll</strong>' +
        '<p>Open Settings on your mobile phone to enroll Face ID, fingerprint, or passcode. Enrolled phones will show here on desktop.</p>' +
        '</div>' +
        '</div>';
      if (required && !devices.length) {
        helpHtml += '<p class="ps-bio-help">Required for clock in / out.</p>';
      }
      if (devices.length) {
        actionHtml =
          '<button type="button" class="ps-bio-reset" id="psBioResetBtn"' +
          (state.busy ? ' disabled' : '') +
          '>Clear all &amp; re-enroll</button>';
      }
    } else {
      if (blockReason) {
        helpHtml = '<p class="ps-bio-help">' + escapeHtml(blockReason) + '</p>';
      } else if (required && !devices.length) {
        helpHtml = '<p class="ps-bio-help">Required for clock in / out.</p>';
      }
      if (state.prefetchError) {
        helpHtml +=
          '<p class="ps-bio-help" style="color:#b91c1c;">' +
          escapeHtml(state.prefetchError) +
          '</p>';
      }

      if (canEnrollMore) {
        var ready = supported && !!state.prefetchedPublicKey && !state.prefetching;
        var passkeyReady = supported && !!state.prefetchedPasskeyPublicKey && !state.prefetching;
        var showPasskey = !!state.showPasskey;
        actionHtml =
          '<div class="ps-bio-actions">' +
          (!showPasskey
            ? '<button type="button" class="ps-bio-passkey-link" id="psBioShowPasskeyBtn">Failed to enroll?</button>'
            : '<span class="ps-bio-actions-spacer" aria-hidden="true"></span>') +
          '<div class="ps-bio-actions-right">' +
          '<button type="button" class="btn-save ps-bio-enroll" id="psBioEnrollBtn"' +
          (state.busy || !ready ? ' disabled' : '') +
          '>' +
          '<i class="fas fa-fingerprint" aria-hidden="true"></i> ' +
          (state.busy
            ? 'Waiting...'
            : state.prefetching
              ? 'Preparing...'
              : 'Enroll security') +
          '</button>' +
          (showPasskey
            ? '<button type="button" class="ps-bio-passkey" id="psBioPasskeyBtn"' +
              (state.busy || !passkeyReady ? ' disabled' : '') +
              '>' +
              '<i class="fab fa-google" aria-hidden="true"></i> ' +
              (state.busy ? 'Waiting...' : 'Passkey (Google / iCloud)') +
              '</button>'
            : '') +
          '</div>' +
          '</div>';
        if (supported && !state.prefetchedPublicKey && !state.prefetching && !state.prefetchError) {
          actionHtml +=
            '<button type="button" class="ps-bio-retry" id="psBioPrefetchBtn">Retry prepare</button>';
        }
      } else {
        helpHtml +=
          '<p class="ps-bio-help">Limit reached (' + max + '). Remove a phone to add another.</p>';
      }

      if (devices.length) {
        actionHtml +=
          '<button type="button" class="ps-bio-reset" id="psBioResetBtn"' +
          (state.busy ? ' disabled' : '') +
          '>Clear all &amp; re-enroll</button>';
      }
    }

    root.innerHTML =
      '<div class="ps-bio-copy">' +
      helpHtml +
      devicesHtml +
      '</div>' +
      (actionHtml ? '<div class="ps-bio-side">' + actionHtml + '</div>' : '');
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function init() {
    var cfg = window.__PROFILE_BIOMETRIC__ || {};
    var root = document.getElementById('psBioRoot');
    if (!root || !cfg.apiUrl) return;

    var state = {
      busy: false,
      prefetching: false,
      prefetchedPublicKey: null,
      prefetchedPasskeyPublicKey: null,
      prefetchError: '',
      showPasskey: false,
      biometric: cfg.biometric || {
        required: false,
        enrolled: false,
        count: 0,
        max: 2,
        devices: [],
      },
    };

    function paint() {
      render(root, state);
    }

    function setBusy(busy) {
      state.busy = !!busy;
      paint();
    }

    function applyBiometric(next) {
      if (next) state.biometric = next;
      paint();
    }

    function prefetchOptions() {
      var devices = Array.isArray(state.biometric.devices) ? state.biometric.devices : [];
      var max = Number(state.biometric.max || 2);
      if (!isMobilePhone()) {
        state.prefetchedPublicKey = null;
        state.prefetchedPasskeyPublicKey = null;
        state.prefetchError = '';
        paint();
        return Promise.resolve();
      }
      if (devices.length >= max) return Promise.resolve();
      if (!isWebAuthnSupported() || hostLooksLikeIp()) {
        state.prefetchedPublicKey = null;
        state.prefetchError = supportBlockReason();
        paint();
        return Promise.resolve();
      }

      state.prefetching = true;
      state.prefetchError = '';
      paint();

      return postAction(cfg.apiUrl, { action: 'webauthn_register_options', mode: 'platform' })
        .then(function (options) {
          if (!options.success || !options.publicKey) {
            throw new Error(options.message || 'Could not prepare enrollment.');
          }
          state.prefetchedPublicKey = options.publicKey;
          state.prefetchError = '';
          return postAction(cfg.apiUrl, { action: 'webauthn_register_options', mode: 'passkey' });
        })
        .then(function (passkeyOptions) {
          if (passkeyOptions && passkeyOptions.success && passkeyOptions.publicKey) {
            state.prefetchedPasskeyPublicKey = passkeyOptions.publicKey;
          } else {
            state.prefetchedPasskeyPublicKey = null;
          }
        })
        .catch(function (err) {
          state.prefetchedPublicKey = null;
          state.prefetchedPasskeyPublicKey = null;
          state.prefetchError = (err && err.message) || 'Could not prepare enrollment.';
        })
        .finally(function () {
          state.prefetching = false;
          paint();
        });
    }

    root.addEventListener('click', function (e) {
      var enrollBtn = e.target.closest('#psBioEnrollBtn');
      if (enrollBtn) {
        e.preventDefault();
        e.stopPropagation();
        enroll('platform');
        return;
      }
      var showPasskeyBtn = e.target.closest('#psBioShowPasskeyBtn');
      if (showPasskeyBtn) {
        e.preventDefault();
        e.stopPropagation();
        state.showPasskey = true;
        paint();
        return;
      }
      var passkeyBtn = e.target.closest('#psBioPasskeyBtn');
      if (passkeyBtn) {
        e.preventDefault();
        e.stopPropagation();
        enroll('passkey');
        return;
      }
      var retryBtn = e.target.closest('#psBioPrefetchBtn');
      if (retryBtn) {
        e.preventDefault();
        prefetchOptions();
        return;
      }
      var removeBtn = e.target.closest('.ps-bio-remove');
      if (removeBtn) {
        e.preventDefault();
        removeDevice(Number(removeBtn.getAttribute('data-device-id') || 0));
        return;
      }
      var resetBtn = e.target.closest('#psBioResetBtn');
      if (resetBtn) {
        e.preventDefault();
        resetAllDevices();
      }
    });

    function enroll(mode) {
      mode = mode === 'passkey' ? 'passkey' : 'platform';
      if (!isMobilePhone()) {
        toast('info', 'Open Settings on your phone to enroll smartphone unlock.');
        paint();
        return;
      }
      var blockReason = supportBlockReason();
      if (blockReason) {
        toast('error', blockReason);
        return;
      }
      var publicKey =
        mode === 'passkey' ? state.prefetchedPasskeyPublicKey : state.prefetchedPublicKey;
      if (!publicKey) {
        toast(
          'error',
          mode === 'passkey'
            ? 'Still preparing Google passkey. Wait a moment, then try again.'
            : 'Still preparing phone unlock. Wait a moment, then tap Enroll again.'
        );
        prefetchOptions();
        return;
      }

      // Critical for iPhone: call credentials.create() synchronously in the tap handler.
      var createPromise;
      try {
        createPromise = navigator.credentials.create({
          publicKey: prepareCreateOptions(publicKey, mode),
        });
      } catch (err) {
        toast('error', formatWebAuthnError(err));
        prefetchOptions();
        return;
      }

      state.busy = true;
      var btn = document.getElementById(mode === 'passkey' ? 'psBioPasskeyBtn' : 'psBioEnrollBtn');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML =
          mode === 'passkey'
            ? '<i class="fab fa-google" aria-hidden="true"></i> Waiting...'
            : '<i class="fas fa-fingerprint" aria-hidden="true"></i> Waiting...';
      }

      createPromise
        .then(function (credential) {
          if (!credential) throw new Error('Phone unlock enrollment was cancelled.');
          if (mode === 'passkey') state.prefetchedPasskeyPublicKey = null;
          else state.prefetchedPublicKey = null;
          return postAction(cfg.apiUrl, {
            action: 'webauthn_register',
            mode: mode,
            device_label: mode === 'passkey' ? 'Google passkey' : guessDeviceLabel(),
            credential: {
              id: bufferToB64url(credential.rawId),
              rawId: bufferToB64url(credential.rawId),
              type: credential.type,
              clientDataJSON: bufferToB64url(credential.response.clientDataJSON),
              attestationObject: bufferToB64url(credential.response.attestationObject),
            },
          });
        })
        .then(function (result) {
          if (!result.success) throw new Error(result.message || 'Enrollment failed.');
          applyBiometric((result.data && result.data.biometric) || state.biometric);
          toast(
            'success',
            result.message ||
              (mode === 'passkey' ? 'Google passkey enrolled.' : 'Phone unlock enrolled.')
          );
          return prefetchOptions();
        })
        .catch(function (err) {
          toast('error', formatWebAuthnError(err));
          return prefetchOptions();
        })
        .finally(function () {
          setBusy(false);
        });
    }

    function removeDevice(id) {
      if (!id) return;
      if (!window.confirm('Remove this enrolled phone?')) return;
      setBusy(true);
      postAction(cfg.apiUrl, { action: 'webauthn_remove', credential_row_id: id })
        .then(function (result) {
          if (!result.success) throw new Error(result.message || 'Could not remove device.');
          applyBiometric((result.data && result.data.biometric) || state.biometric);
          toast('success', result.message || 'Enrolled phone removed.');
          return prefetchOptions();
        })
        .catch(function (err) {
          toast('error', (err && err.message) || 'Could not remove device.');
        })
        .finally(function () {
          setBusy(false);
        });
    }

    function resetAllDevices() {
      if (
        !window.confirm(
          'Clear all enrolled phones for this account? You will need to enroll Face ID or passcode again on this iPhone.'
        )
      ) {
        return;
      }
      setBusy(true);
      postAction(cfg.apiUrl, { action: 'webauthn_reset' })
        .then(function (result) {
          if (!result.success) throw new Error(result.message || 'Could not clear enrollments.');
          applyBiometric((result.data && result.data.biometric) || state.biometric);
          toast('success', result.message || 'Old unlock cleared. Tap Enroll this phone.');
          return prefetchOptions();
        })
        .catch(function (err) {
          toast('error', (err && err.message) || 'Could not clear enrollments.');
        })
        .finally(function () {
          setBusy(false);
        });
    }

    paint();
    prefetchOptions();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
