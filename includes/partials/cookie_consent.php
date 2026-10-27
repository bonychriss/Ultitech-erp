<?php
/**
 * Cookie consent markup. Returns HTML string (no nested output buffering).
 * Used sitewide via erp_get_cookie_consent_body_html().
 */
if (!function_exists('erp_cookie_consent_markup')) {
    function erp_cookie_consent_markup(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $cssFs = dirname(__DIR__, 2) . '/assets/css/cookie-consent.css';
        $jsFs = dirname(__DIR__, 2) . '/assets/js/cookie-consent.js';
        $cssVer = is_file($cssFs) ? (string) filemtime($cssFs) : (string) time();
        $jsVer = is_file($jsFs) ? (string) filemtime($jsFs) : (string) time();
        $cssUrl = function_exists('app_url')
            ? app_url('/assets/css/cookie-consent.css')
            : '/assets/css/cookie-consent.css';
        $jsUrl = function_exists('app_url')
            ? app_url('/assets/js/cookie-consent.js')
            : '/assets/js/cookie-consent.js';

        $cssHref = htmlspecialchars($cssUrl . '?v=' . $cssVer, ENT_QUOTES, 'UTF-8');
        $jsSrc = htmlspecialchars($jsUrl . '?v=' . $jsVer, ENT_QUOTES, 'UTF-8');

        $cached = <<<HTML
<link rel="stylesheet" id="erp-cookie-consent-css" href="{$cssHref}">
<div id="erp-cookie-consent" class="erp-cc-root" data-erp-cc-root>
  <div id="erp-cc-banner" class="erp-cc-banner is-open" role="dialog" aria-live="polite" aria-label="Cookie consent">
    <div class="erp-cc-banner__inner">
      <p class="erp-cc-banner__copy">
        By clicking <strong>&ldquo;Accept All Cookies&rdquo;</strong>, you agree to the storing of cookies on your device to enhance site navigation, analyze site usage, and assist in our marketing efforts.
      </p>
      <div class="erp-cc-banner__actions">
        <button type="button" class="erp-cc-btn erp-cc-btn--settings-link" data-erp-cc="settings">Cookies Settings</button>
        <button type="button" class="erp-cc-btn erp-cc-btn--reject" data-erp-cc="reject">Reject All</button>
        <button type="button" class="erp-cc-btn erp-cc-btn--accept" data-erp-cc="accept">Accept All Cookies</button>
      </div>
    </div>
  </div>

  <div id="erp-cc-overlay" class="erp-cc-overlay" aria-hidden="true">
    <div class="erp-cc-panel" role="dialog" aria-modal="true" aria-labelledby="erp-cc-panel-title">
      <div class="erp-cc-panel__head">
        <h2 id="erp-cc-panel-title">Cookies Settings</h2>
        <button type="button" class="erp-cc-panel__close" data-erp-cc="close-settings" aria-label="Close">&times;</button>
      </div>
      <p class="erp-cc-panel__intro">
        Choose which cookies we may store. Necessary cookies are always on so the site can run securely.
      </p>

      <div class="erp-cc-row">
        <div class="erp-cc-row__text">
          <strong>Necessary</strong>
          <p>Required for login, security, and basic site features.</p>
        </div>
        <label class="erp-cc-switch">
          <input type="checkbox" checked disabled aria-label="Necessary cookies always on">
          <span></span>
        </label>
      </div>

      <div class="erp-cc-row">
        <div class="erp-cc-row__text">
          <strong>Analytics</strong>
          <p>Help us understand how the site is used so we can improve navigation.</p>
        </div>
        <label class="erp-cc-switch">
          <input type="checkbox" id="erp-cc-analytics" aria-label="Allow analytics cookies">
          <span></span>
        </label>
      </div>

      <div class="erp-cc-row">
        <div class="erp-cc-row__text">
          <strong>Marketing</strong>
          <p>Used to assist marketing efforts and measure campaign performance.</p>
        </div>
        <label class="erp-cc-switch">
          <input type="checkbox" id="erp-cc-marketing" aria-label="Allow marketing cookies">
          <span></span>
        </label>
      </div>

      <div class="erp-cc-panel__actions">
        <button type="button" class="erp-cc-btn erp-cc-btn--ghost" data-erp-cc="reject-panel">Reject All</button>
        <button type="button" class="erp-cc-btn erp-cc-btn--reject" data-erp-cc="save">Save Settings</button>
        <button type="button" class="erp-cc-btn erp-cc-btn--accept" data-erp-cc="accept-panel">Accept All Cookies</button>
      </div>
    </div>
  </div>

  <button type="button" id="erp-cc-reopen" class="erp-cc-reopen" aria-label="Open cookie settings">Cookies Settings</button>
</div>
<script src="{$jsSrc}" defer></script>

HTML;

        return $cached;
    }
}

return erp_cookie_consent_markup();
