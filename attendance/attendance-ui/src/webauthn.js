function b64urlToBuffer(value) {
  const str = String(value || '')
    .replace(/-/g, '+')
    .replace(/_/g, '/');
  const pad = str.length % 4 === 0 ? '' : '='.repeat(4 - (str.length % 4));
  const binary = window.atob(str + pad);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer;
}

function bufferToB64url(buffer) {
  const bytes = new Uint8Array(buffer);
  let binary = '';
  for (let i = 0; i < bytes.length; i += 1) {
    binary += String.fromCharCode(bytes[i]);
  }
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
    const m = value.match(/^=\?BINARY\?B\?(.+)\?=$/);
    if (m) {
      const binary = window.atob(m[1]);
      const bytes = new Uint8Array(binary.length);
      for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
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

export function isWebAuthnSupported() {
  return typeof window !== 'undefined'
    && window.isSecureContext
    && typeof window.PublicKeyCredential !== 'undefined'
    && typeof navigator.credentials?.create === 'function'
    && typeof navigator.credentials?.get === 'function';
}

export function guessDeviceLabel() {
  const ua = String(navigator.userAgent || '');
  if (/iPhone/i.test(ua)) return 'iPhone';
  if (/iPad/i.test(ua)) return 'iPad';
  if (/Android/i.test(ua)) return 'Android phone';
  if (/Windows/i.test(ua)) return 'Windows device';
  if (/Mac OS|Macintosh/i.test(ua)) return 'Mac';
  return 'Smartphone';
}

/**
 * @param {object} publicKey
 * @param {'platform'|'passkey'} [mode]
 */
function prepareCreateOptions(publicKey, mode = 'platform') {
  const pk = { ...(publicKey || {}) };
  pk.challenge = coerceToBuffer(pk.challenge);
  if (pk.user) {
    pk.user = {
      ...pk.user,
      id: coerceToBuffer(pk.user.id),
    };
  }
  if (Array.isArray(pk.excludeCredentials)) {
    pk.excludeCredentials = pk.excludeCredentials
      .map((cred) => ({
        ...cred,
        id: coerceToBuffer(cred.id),
        transports: Array.isArray(cred.transports) && cred.transports.length
          ? cred.transports
          : ['internal', 'hybrid', 'usb', 'nfc', 'ble'],
      }))
      .filter((cred) => cred.id && cred.id.byteLength > 0);
  }
  if (pk.extensions) delete pk.extensions;

  const usePasskey = mode === 'passkey';
  const selection = {
    ...(pk.authenticatorSelection || {}),
    userVerification: 'preferred',
    residentKey: usePasskey ? 'preferred' : 'discouraged',
    requireResidentKey: false,
  };
  if (usePasskey) {
    // Do not force cross-platform — that only shows QR / NFC security keys.
    delete selection.authenticatorAttachment;
  } else {
    selection.authenticatorAttachment = 'platform';
  }
  pk.authenticatorSelection = selection;
  // Dead Face ID credentials in exclude can block Safari after a screen repair.
  if (!usePasskey && Array.isArray(pk.excludeCredentials) && pk.excludeCredentials.length) {
    pk.excludeCredentials = [];
  }
  return pk;
}

export function prepareGetOptions(publicKey) {
  const pk = { ...(publicKey || {}) };
  pk.challenge = coerceToBuffer(pk.challenge);
  if (Array.isArray(pk.allowCredentials)) {
    pk.allowCredentials = pk.allowCredentials
      .map((cred) => ({
        ...cred,
        id: coerceToBuffer(cred.id),
        transports: Array.isArray(cred.transports) && cred.transports.length
          ? cred.transports
          : ['internal', 'hybrid', 'usb', 'nfc', 'ble'],
      }))
      .filter((cred) => cred.id && cred.id.byteLength > 0);
  }
  if (pk.extensions) delete pk.extensions;
  pk.userVerification = pk.userVerification || 'preferred';
  return pk;
}

export function beginGetAssertion(publicKey) {
  if (!isWebAuthnSupported()) {
    throw new Error('This browser/device does not support phone unlock. Use your smartphone.');
  }
  return navigator.credentials.get({
    publicKey: prepareGetOptions(publicKey),
  });
}

export function serializeAssertion(assertion) {
  if (!assertion) {
    throw new Error('Phone unlock was cancelled.');
  }
  return {
    id: bufferToB64url(assertion.rawId),
    rawId: bufferToB64url(assertion.rawId),
    type: assertion.type,
    clientDataJSON: bufferToB64url(assertion.response.clientDataJSON),
    authenticatorData: bufferToB64url(assertion.response.authenticatorData),
    signature: bufferToB64url(assertion.response.signature),
    userHandle: assertion.response.userHandle
      ? bufferToB64url(assertion.response.userHandle)
      : null,
  };
}

/** Start create() in the same user gesture (required on iPhone Safari). */
export function beginCreateCredential(publicKey, mode = 'platform') {
  if (!isWebAuthnSupported()) {
    throw new Error('This browser/device does not support phone unlock. Use your smartphone.');
  }
  return navigator.credentials.create({
    publicKey: prepareCreateOptions(publicKey, mode),
  });
}

export function serializeAttestation(credential) {
  if (!credential) {
    throw new Error('Phone unlock enrollment was cancelled.');
  }
  return {
    id: bufferToB64url(credential.rawId),
    rawId: bufferToB64url(credential.rawId),
    type: credential.type,
    clientDataJSON: bufferToB64url(credential.response.clientDataJSON),
    attestationObject: bufferToB64url(credential.response.attestationObject),
  };
}

export async function createCredential(publicKey, mode = 'platform') {
  const credential = await beginCreateCredential(publicKey, mode);
  return serializeAttestation(credential);
}

export async function getAssertion(publicKey) {
  const assertion = await beginGetAssertion(publicKey);
  return serializeAssertion(assertion);
}
