const fs = require('node:fs');
const path = require('node:path');

const PRIVATE_LAN_HOST =
  /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/;

/**
 * Load desktop client config from config.json, shared example, env overrides.
 */
function loadConfig() {
  const configPath = path.join(__dirname, 'config.json');
  const examplePath = path.join(__dirname, '..', 'shared', 'config.example.json');
  const sourcePath = fs.existsSync(configPath) ? configPath : examplePath;
  const config = JSON.parse(fs.readFileSync(sourcePath, 'utf8'));

  const envUrl = process.env.ULTITECH_APP_URL || process.env.ULTITECH_START_URL;
  if (envUrl) {
    config.startUrl = envUrl;
    config.useLocalDev = false;
  }

  if (process.env.ULTITECH_USE_LOCAL_DEV === '1' || process.env.ULTITECH_USE_LOCAL_DEV === 'true') {
    config.useLocalDev = true;
  }

  config.activeUrl = config.useLocalDev ? config.localDevUrl : config.startUrl;

  config.allowedHostSet = new Set(
    (config.allowedHosts || []).map((host) => String(host).toLowerCase())
  );

  try {
    config.allowedHostSet.add(new URL(config.activeUrl).hostname.toLowerCase());
  } catch (error) {
    throw new Error(`Invalid start URL: ${config.activeUrl}`);
  }

  return config;
}

function isAllowedHost(hostname, config) {
  const host = String(hostname || '').toLowerCase();
  if (!host) {
    return false;
  }
  if (config.allowedHostSet.has(host)) {
    return true;
  }
  if (config.useLocalDev && PRIVATE_LAN_HOST.test(host)) {
    return true;
  }
  return false;
}

function isAllowedNavigation(urlString, config) {
  try {
    const url = new URL(urlString);
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
      return false;
    }
    return isAllowedHost(url.hostname, config);
  } catch {
    return false;
  }
}

function isErpOrigin(urlString, config) {
  return isAllowedNavigation(urlString, config);
}

module.exports = {
  loadConfig,
  isAllowedHost,
  isAllowedNavigation,
  isErpOrigin,
};
