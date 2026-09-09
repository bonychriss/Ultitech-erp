/**
 * Mobile (Capacitor/Android) helpers. Safe in web/Electron builds — no-ops when not native.
 */

import { Capacitor } from '@capacitor/core';

export function isMobileApp() {
  return Capacitor.isNativePlatform();
}

async function blobToBase64(blob) {
  const buffer = await blob.arrayBuffer();
  let binary = '';
  const bytes = new Uint8Array(buffer);
  const chunkSize = 0x8000;

  for (let i = 0; i < bytes.length; i += chunkSize) {
    binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
  }

  return btoa(binary);
}

/**
 * Save export on Android using Capacitor Filesystem + Share sheet.
 * @returns {true} saved/shared, {false} failed, {null} user canceled
 */
export async function saveExportedBlob(blob, defaultName) {
  if (!isMobileApp()) return false;

  try {
    const [{ Filesystem, Directory }, { Share }] = await Promise.all([
      import('@capacitor/filesystem'),
      import('@capacitor/share')
    ]);

    const base64 = await blobToBase64(blob);
    const folder = 'BCUT';
    const filePath = `${folder}/${defaultName}`;

    await Filesystem.writeFile({
      path: filePath,
      data: base64,
      directory: Directory.Documents,
      recursive: true
    });

    const { uri } = await Filesystem.getUri({
      path: filePath,
      directory: Directory.Documents
    });

    await Share.share({
      title: 'BCUT export',
      text: defaultName,
      url: uri,
      dialogTitle: 'Save or share image'
    });

    return true;
  } catch (err) {
    const message = err?.message || String(err);
    if (/cancel/i.test(message)) {
      return null;
    }
    console.error('Mobile save failed:', err);
    return false;
  }
}

/** @returns {Promise<string|null>} */
export async function getMobileAppVersion() {
  if (!isMobileApp()) return null;

  try {
    const { App } = await import('@capacitor/app');
    const info = await App.getInfo();
    return info.version || null;
  } catch {
    return null;
  }
}
