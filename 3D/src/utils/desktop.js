/**
 * Desktop (Electron) helpers. Safe to import in the web build — no-ops when not in Electron.
 */

export function isDesktopApp() {
  return typeof window !== 'undefined' && window.bcut?.isDesktop === true;
}

/**
 * Save a blob using the native desktop Save dialog.
 * @returns {true} saved, {false} failed, {null} user canceled
 */
export async function saveExportedBlob(blob, defaultName) {
  if (!isDesktopApp() || !window.bcut?.saveFile) return false;

  try {
    const data = new Uint8Array(await blob.arrayBuffer());
    const result = await window.bcut.saveFile({ data, defaultName });
    if (result?.canceled) return null;
    return result?.success === true;
  } catch (err) {
    console.error('Desktop save failed:', err);
    return false;
  }
}

/** @returns {Promise<string|null>} */
export async function getDesktopAppVersion() {
  if (!isDesktopApp() || !window.bcut?.getVersion) return null;

  try {
    return await window.bcut.getVersion();
  } catch {
    return null;
  }
}

/** Manual update check (desktop only). Returns false in web/dev mode. */
export async function checkDesktopForUpdates() {
  if (!isDesktopApp() || !window.bcut?.checkForUpdates) return false;

  try {
    const result = await window.bcut.checkForUpdates();
    return result?.success === true;
  } catch (err) {
    console.error('Desktop update check failed:', err);
    return false;
  }
}

/** Subscribe to desktop update status events. Returns an unsubscribe function. */
export function onDesktopUpdateStatus(callback) {
  if (!isDesktopApp() || !window.bcut?.onUpdateStatus) {
    return () => {};
  }

  return window.bcut.onUpdateStatus(callback);
}
