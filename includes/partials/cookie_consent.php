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

        $cookieIcon = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3.2c.4 1.7 1.7 3 3.4 3.4.7 1.8 2.3 3.1 4.2 3.4-.1 4.8-4 8.7-8.8 8.8A8.8 8.8 0 0 1 7.4 4.6c1.5.7 3.2.4 4.6-.8Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="9.2" cy="10.2" r="1" fill="currentColor"/><circle cx="13.6" cy="9.4" r="1" fill="currentColor"/><circle cx="11.2" cy="14.2" r="1" fill="currentColor"/><circle cx="15" cy="13.2" r="0.9" fill="currentColor"/></svg>';
        $gearIcon = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z" stroke="currentColor" stroke-width="1.7"/><path d="M19.4 13.1v-2.2l-1.7-.3a6.7 6.7 0 0 0-.5-1.2l1-1.4-1.6-1.6-1.4 1a6.7 6.7 0 0 0-1.2-.5l-.3-1.7h-2.2l-.3 1.7a6.7 6.7 0 0 0-1.2.5l-1.4-1-1.6 1.6 1 1.4a6.7 6.7 0 0 0-.5 1.2l-1.7.3v2.2l1.7.3c.1.4.3.8.5 1.2l-1 1.4 1.6 1.6 1.4-1c.4.2.8.4 1.2.5l.3 1.7h2.2l.3-1.7c.4-.1.8-.3 1.2-.5l1.4 1 1.6-1.6-1-1.4c.2-.4.4-.8.5-1.2l1.7-.3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>';
        $closeIcon = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

        $cached = <<<HTML
<link rel="stylesheet" id="erp-cookie-consent-css" href="{$cssHref}">
<div id="erp-cookie-consent" class="erp-cc-root" data-erp-cc-root>
  <div id="erp-cc-banner" class="erp-cc-banner is-open" role="dialog" aria-live="polite" aria-label="Cookie consent">
    <div class="erp-cc-banner__inner">
      <button type="button" class="erp-cc-banner__dismiss" data-erp-cc="dismiss" aria-label="Close">{$closeIcon}</button>
      <div class="erp-cc-banner__main">
        <span class="erp-cc-banner__icon" aria-hidden="true">{$cookieIcon}</span>
        <div class="erp-cc-banner__text">
          <h2 class="erp-cc-banner__title">We use cookies</h2>
          <p class="erp-cc-banner__copy">
            By clicking <strong>&ldquo;Accept All Cookies&rdquo;</strong>, you agree to the storing of cookies on your device to enhance site navigation, analyze site usage, and assist in our marketing efforts.
          </p>
        </div>
      </div>
      <div class="erp-cc-banner__actions">
        <button type="button" class="erp-cc-btn erp-cc-btn--settings" data-erp-cc="settings">{$gearIcon}<span>Cookies Settings</span></button>
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
