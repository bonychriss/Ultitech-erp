/**
 * Auto-update server configuration.
 *
 * Change `updateServerUrl` to the HTTPS folder where you host:
 *   - latest.yml
 *   - BCUT Setup.exe
 *   - BCUT Setup.exe.blockmap (optional, enables smaller delta updates)
 *
 * Override at build time:
 *   set BCUT_UPDATE_URL=https://your-domain.com/bcut/updates/
 *   npm run desktop:build
 *
 * The URL must end with a trailing slash.
 */
module.exports = {
  updateServerUrl:
    process.env.BCUT_UPDATE_URL || 'https://YOUR-DOMAIN.com/bcut/updates/'
};
