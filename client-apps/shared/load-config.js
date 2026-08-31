import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Load client wrapper config from env, then config.json, then example defaults.
 */
export function loadClientConfig(configPath) {
  const examplePath = path.join(__dirname, 'config.example.json');
  const defaults = JSON.parse(fs.readFileSync(examplePath, 'utf8'));

  let fileConfig = {};
  if (configPath && fs.existsSync(configPath)) {
    fileConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));
  }

  const merged = { ...defaults, ...fileConfig };

  const envUrl = process.env.ULTITECH_APP_URL || process.env.ULTITECH_START_URL;
  if (envUrl) {
    merged.startUrl = envUrl;
    merged.useLocalDev = false;
  }

  if (process.env.ULTITECH_USE_LOCAL_DEV === '1' || process.env.ULTITECH_USE_LOCAL_DEV === 'true') {
    merged.useLocalDev = true;
  }

  const activeUrl = merged.useLocalDev ? merged.localDevUrl : merged.startUrl;
  merged.activeUrl = activeUrl;

  let hostname = '';
  try {
    hostname = new URL(activeUrl).hostname.toLowerCase();
  } catch {
    throw new Error(`Invalid start URL: ${activeUrl}`);
  }

  merged.allowedHostSet = new Set(
    (merged.allowedHosts || []).map((host) => String(host).toLowerCase())
  );
  merged.allowedHostSet.add(hostname);

  return merged;
}

export function isAllowedNavigation(urlString, config) {
  try {
    const url = new URL(urlString);
    const host = url.hostname.toLowerCase();

    if (config.allowedHostSet.has(host)) {
      return true;
    }

    // Allow same-LAN IPs during local development (mobile testing on Wi?Fi).
    if (config.useLocalDev && /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/.test(host)) {
      return true;
    }

    return false;
  } catch {
    return false;
  }
}
