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

  function supportBlockReason() {
    if (!window.isSecureContext) {
      return 'Phone unlock needs HTTPS (or localhost). Open your company HTTPS URL ù plain http:// or a LAN IP will not work.';
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

  function prepareCreateOptions(publicKey) {
    var pk = Object.assign({}, publicKey || {});
    pk.challenge = coerceToBuffer(pk.challenge);
    if (pk.user) {
      pk.user = Object.assign({}, pk.user, { id: coerceToBuffer(pk.user.id) });
    }
    if (Array.isArray(pk.excludeCredentials)) {
      pk.excludeCredentials = pk.excludeCredentials
        .map(function (cred) {
          return Object.assign({}, cred, {
            id: coerceToBuffer(cred.id),
            transports: Array.isArray(cred.transports) ? cred.transports : ['internal'],
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
      authenticatorAttachment: 'platform',
      userVerification: 'required',
      residentKey: pk.authenticatorSelection.residentKey || 'preferred',
    });
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
    if (window.Swal) {
      window.Swal.fire({
        toast: true,
        position: 'top-end',
        icon: type,
        title: title,
        showConfirmButton: false,
        timer: type === 'success' ? 3200 : 5500,
      });
      return;
    }
    window.alert(title);
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

    var statusHtml =
      '<span class="ps-bio-pill ' +
      (devices.length ? 'is-ok' : 'is-warn') +
      '">' +
      (devices.length ? devices.length + ' enrolled' : 'Not enrolled') +
      '</span>';

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
                ? '<em> ù last used ' + escapeHtml(String(device.last_used_at).slice(0, 16)) + '</em>'
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
    if (blockReason) {
      helpHtml = '<p class="ps-bio-help">' + escapeHtml(blockReason) + '</p>';
    } else if (required) {
      helpHtml =
        '<p class="ps-bio-help">Attendance clock in/out requires an enrolled phone for this company. Use Face ID, fingerprint, or your phone passcode if biometrics are not available.</p>';
    } else {
      helpHtml =
        '<p class="ps-bio-help">Optional: enroll a phone so attendance can verify it is really you. Face ID, fingerprint, or phone passcode all work.</p>';
    }
    if (state.prefetchError) {
      helpHtml +=
        '<p class="ps-bio-help" style="color:#b91c1c;">' +
        escapeHtml(state.prefetchError) +
        '</p>';
    }

    var actionHtml = '';
    if (canEnrollMore) {
      var ready = supported && !!state.prefetchedPublicKey && !state.prefetching;
      actionHtml =
        '<button type="button" class="btn-save ps-bio-enroll" id="psBioEnrollBtn"' +
        (state.busy || !ready ? ' disabled' : '') +
        '>' +
        '<i class="fas fa-fingerprint" aria-hidden="true"></i> ' +
        (state.busy
          ? 'Waiting for unlock...'
          : state.prefetching
            ? 'Preparing...'
            : devices.length
              ? 'Enroll another phone'
              : 'Enroll this phone') +
        '</button>';
      if (supported && !state.prefetchedPublicKey && !state.prefetching && !state.prefetchError) {
        actionHtml +=
          '<button type="button" class="ps-bio-retry" id="psBioPrefetchBtn">Retry prepare</button>';
      }
    } else {
      actionHtml =
        '<p class="ps-bio-help">Enrollment limit reached (' +
        max +
        '). Remove a phone to add a new one.</p>';
    }

    root.innerHTML =
      '<div class="ps-bio-head">' +
      '<div><strong class="ps-bio-title"><i class="fas fa-fingerprint" aria-hidden="true"></i> Smartphone unlock</strong>' +
      '<p class="ps-bio-sub">Verify with Face ID, fingerprint, or your phone passcode when clocking in or out.</p></div>' +
      statusHtml +
      '</div>' +
      helpHtml +
      devicesHtml +
      actionHtml;
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
      prefetchError: '',
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

      return postAction(cfg.apiUrl, { action: 'webauthn_register_options' })
        .then(function (options) {
          if (!options.success || !options.publicKey) {
            throw new Error(options.message || 'Could not prepare enrollment.');
          }
          state.prefetchedPublicKey = options.publicKey;
          state.prefetchError = '';
        })
        .catch(function (err) {
          state.prefetchedPublicKey = null;
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
        enroll();
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
      }
    });

    function enroll() {
      var blockReason = supportBlockReason();
      if (blockReason) {
        toast('error', blockReason);
        return;
      }
      var publicKey = state.prefetchedPublicKey;
      if (!publicKey) {
        toast('error', 'Still preparing phone unlock. Wait a moment, then tap Enroll again.');
        prefetchOptions();
        return;
      }

      // Critical for iPhone: call credentials.create() synchronously in the tap handler.
      // Do not await/fetch/re-render before this, or Safari drops user activation.
      var createPromise;
      try {
        createPromise = navigator.credentials.create({
          publicKey: prepareCreateOptions(publicKey),
        });
      } catch (err) {
        toast('error', formatWebAuthnError(err));
        prefetchOptions();
        return;
      }

      state.busy = true;
      // Update button label without wiping the gesture context mid-flight more than needed.
      var btn = document.getElementById('psBioEnrollBtn');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-fingerprint" aria-hidden="true"></i> Waiting for unlock...';
      }

      createPromise
        .then(function (credential) {
          if (!credential) throw new Error('Phone unlock enrollment was cancelled.');
          state.prefetchedPublicKey = null;
          return postAction(cfg.apiUrl, {
            action: 'webauthn_register',
            device_label: guessDeviceLabel(),
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
          toast('success', result.message || 'Phone unlock enrolled.');
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

    paint();
    prefetchOptions();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
