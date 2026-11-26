import { Moon, Sun } from 'lucide-react';
import { RegionalPreferences } from '../../types/erpProfile';

interface PreferencesSectionProps {
  preferences: RegionalPreferences;
  onUpdatePreferences: (patch: Partial<RegionalPreferences>) => void;
}

function formatPreviewDate(format: RegionalPreferences['dateFormat']): string {
  switch (format) {
    case 'DD/MM/YYYY':
      return '25/11/2026';
    case 'MM/DD/YYYY':
      return '11/25/2026';
    default:
      return '2026-11-25';
  }
}

function formatPreviewAmount(
  numberFormat: RegionalPreferences['numberFormat'],
  currency: RegionalPreferences['currency']
): string {
  const symbols: Record<RegionalPreferences['currency'], string> = {
    USD: '$',
    GBP: '£',
    EUR: '€',
    JPY: '¥',
  };
  const sym = symbols[currency];
  if (currency === 'JPY') {
    if (numberFormat === '1.234,56') return `${sym}142.850`;
    if (numberFormat === '1 234.56') return `${sym}142 850`;
    return `${sym}142,850`;
  }
  if (numberFormat === '1.234,56') return `${sym}142.850,75`;
  if (numberFormat === '1 234.56') return `${sym}142 850.75`;
  return `${sym}142,850.75`;
}

function getFiscalQuarterLabel(startMonth: RegionalPreferences['fiscalYearStart']): string {
  switch (startMonth) {
    case 'April':
      return 'FY26 · Q3';
    case 'July':
      return 'FY27 · Q2';
    case 'October':
      return 'FY27 · Q1';
    default:
      return 'FY26 · Q4';
  }
}

export default function PreferencesSection({
  preferences,
  onUpdatePreferences,
}: PreferencesSectionProps) {
  const toggleNotificationChannel = (
    id: string,
    channel: 'inApp' | 'email' | 'digest'
  ) => {
    const nextNotifications = preferences.notifications.map((n) =>
      n.id === id ? { ...n, [channel]: !n[channel] } : n
    );
    onUpdatePreferences({ notifications: nextNotifications });
  };

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
          Regional, Fiscal &amp; Notification Preferences
        </h1>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Customize how dates, currencies, fiscal periods, workspace appearance, and operational alerts render across ERP modules.
        </p>
      </div>

      {/* Localization & Fiscal Formatting */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
          <div className="lg:col-span-4">
            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
              Localization &amp; Fiscal Formats
            </h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
              Controls display formatting in General Ledger, Purchase Orders, and CSV exports. Underlying accounting records remain stored in ISO-8601 and base entity currency.
            </p>
          </div>

          <div className="lg:col-span-8 space-y-6">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
              <div>
                <label
                  htmlFor="pref-lang"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Display Language
                </label>
                <select
                  id="pref-lang"
                  value={preferences.language}
                  onChange={(e) => onUpdatePreferences({ language: e.target.value })}
                  className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                >
                  <option value="English (UK)">English (UK)</option>
                  <option value="English (US)">English (US)</option>
                  <option value="Deutsch (DE)">Deutsch (DE)</option>
                  <option value="Français (FR)">Français (FR)</option>
                </select>
              </div>

              <div>
                <label
                  htmlFor="pref-tz"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Time Zone
                </label>
                <select
                  id="pref-tz"
                  value={preferences.timezone}
                  onChange={(e) => onUpdatePreferences({ timezone: e.target.value })}
                  className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                >
                  <option value="Europe/London (UTC+00:00)">Europe/London (UTC+00:00)</option>
                  <option value="Europe/Berlin (UTC+01:00)">Europe/Berlin (UTC+01:00)</option>
                  <option value="America/New_York (UTC-05:00)">America/New_York (UTC-05:00)</option>
                  <option value="America/Los_Angeles (UTC-08:00)">America/Los_Angeles (UTC-08:00)</option>
                  <option value="Asia/Tokyo (UTC+09:00)">Asia/Tokyo (UTC+09:00)</option>
                </select>
              </div>

              <div>
                <label
                  htmlFor="pref-date"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Date Format
                </label>
                <select
                  id="pref-date"
                  value={preferences.dateFormat}
                  onChange={(e) =>
                    onUpdatePreferences({
                      dateFormat: e.target.value as RegionalPreferences['dateFormat'],
                    })
                  }
                  className="w-full px-3.5 py-2 text-sm font-mono bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                >
                  <option value="YYYY-MM-DD">YYYY-MM-DD (2026-11-25)</option>
                  <option value="DD/MM/YYYY">DD/MM/YYYY (25/11/2026)</option>
                  <option value="MM/DD/YYYY">MM/DD/YYYY (11/25/2026)</option>
                </select>
              </div>

              <div>
                <label
                  htmlFor="pref-num"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Number &amp; Decimal Format
                </label>
                <select
                  id="pref-num"
                  value={preferences.numberFormat}
                  onChange={(e) =>
                    onUpdatePreferences({
                      numberFormat: e.target.value as RegionalPreferences['numberFormat'],
                    })
                  }
                  className="w-full px-3.5 py-2 text-sm font-mono bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                >
                  <option value="1,234.56">1,234.56 (Comma / Dot)</option>
                  <option value="1.234,56">1.234,56 (Dot / Comma)</option>
                  <option value="1 234.56">1 234.56 (Space / Dot)</option>
                </select>
              </div>

              <div>
                <label
                  htmlFor="pref-curr"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Reporting Currency
                </label>
                <select
                  id="pref-curr"
                  value={preferences.currency}
                  onChange={(e) =>
                    onUpdatePreferences({
                      currency: e.target.value as RegionalPreferences['currency'],
                    })
                  }
                  className="w-full px-3.5 py-2 text-sm font-mono bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                >
                  <option value="USD">USD ($) — US Dollar</option>
                  <option value="GBP">GBP (£) — British Pound</option>
                  <option value="EUR">EUR (€) — Euro</option>
                  <option value="JPY">JPY (¥) — Japanese Yen</option>
                </select>
              </div>

              <div>
                <label
                  htmlFor="pref-fy"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Fiscal Year Start
                </label>
                <select
                  id="pref-fy"
                  value={preferences.fiscalYearStart}
                  onChange={(e) =>
                    onUpdatePreferences({
                      fiscalYearStart: e.target.value as RegionalPreferences['fiscalYearStart'],
                    })
                  }
                  className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                >
                  <option value="January">January (Calendar Year)</option>
                  <option value="April">April (UK / APAC Standard)</option>
                  <option value="July">July</option>
                  <option value="October">October</option>
                </select>
              </div>
            </div>

            {/* Live Ledger Format Preview */}
            <div className="p-4 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-950/60">
              <div className="text-xs font-medium text-slate-500 dark:text-slate-400 mb-2">
                Live Ledger Row Preview
              </div>
              <div className="flex flex-wrap items-center justify-between gap-4 text-sm">
                <div className="flex items-center gap-3">
                  <span className="font-mono tabular-nums font-medium text-slate-900 dark:text-slate-100">
                    PO-2026-8841
                  </span>
                  <span className="text-slate-400" aria-hidden="true">·</span>
                  <span className="text-slate-700 dark:text-slate-300">Kestrel Precision Alloys</span>
                </div>
                <div className="flex items-center gap-4 font-mono text-xs tabular-nums">
                  <span className="text-slate-600 dark:text-slate-400">
                    {getFiscalQuarterLabel(preferences.fiscalYearStart)}
                  </span>
                  <span className="text-slate-600 dark:text-slate-400">
                    {formatPreviewDate(preferences.dateFormat)}
                  </span>
                  <span className="text-sm font-semibold text-slate-900 dark:text-slate-100">
                    {formatPreviewAmount(preferences.numberFormat, preferences.currency)}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Workspace Appearance & Density */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
          <div className="lg:col-span-4">
            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
              Interface &amp; Table Density
            </h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
              Tune visual contrast and row spacing across data tables, session lists, and ledger grids.
            </p>
          </div>

          <div className="lg:col-span-8 divide-y divide-slate-200 dark:divide-slate-800">
            <div className="pb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div>
                <div className="text-sm font-medium text-slate-900 dark:text-slate-100">
                  Color Theme
                </div>
                <div className="text-xs text-slate-500 dark:text-slate-400">
                  Switch between high-contrast daylight and low-glare slate mode.
                </div>
              </div>
              <div className="inline-flex p-1 bg-slate-100 dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 self-start sm:self-center">
                <button
                  type="button"
                  onClick={() => onUpdatePreferences({ theme: 'light' })}
                  className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md transition-colors whitespace-nowrap shrink-0 cursor-pointer ${
                    preferences.theme === 'light'
                      ? 'bg-white text-slate-900 shadow-xs'
                      : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100'
                  }`}
                >
                  <Sun className="w-3.5 h-3.5" />
                  <span>Light</span>
                </button>
                <button
                  type="button"
                  onClick={() => onUpdatePreferences({ theme: 'dark' })}
                  className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md transition-colors whitespace-nowrap shrink-0 cursor-pointer ${
                    preferences.theme === 'dark'
                      ? 'bg-slate-900 text-slate-100 shadow-xs'
                      : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100'
                  }`}
                >
                  <Moon className="w-3.5 h-3.5" />
                  <span>Dark</span>
                </button>
              </div>
            </div>

            <div className="py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div>
                <div className="text-sm font-medium text-slate-900 dark:text-slate-100">
                  Data Grid Row Density
                </div>
                <div className="text-xs text-slate-500 dark:text-slate-400">
                  Compact fits more line items per screen; comfortable adds extra breathing room.
                </div>
              </div>
              <div className="inline-flex p-1 bg-slate-100 dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 self-start sm:self-center">
                <button
                  type="button"
                  onClick={() => onUpdatePreferences({ tableDensity: 'compact' })}
                  className={`px-3 py-1.5 text-xs font-medium rounded-md transition-colors whitespace-nowrap shrink-0 cursor-pointer ${
                    preferences.tableDensity === 'compact'
                      ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 shadow-xs'
                      : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100'
                  }`}
                >
                  Compact (36px)
                </button>
                <button
                  type="button"
                  onClick={() => onUpdatePreferences({ tableDensity: 'comfortable' })}
                  className={`px-3 py-1.5 text-xs font-medium rounded-md transition-colors whitespace-nowrap shrink-0 cursor-pointer ${
                    preferences.tableDensity === 'comfortable'
                      ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 shadow-xs'
                      : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100'
                  }`}
                >
                  Comfortable (44px)
                </button>
              </div>
            </div>

            <div className="pt-4 flex items-center justify-between gap-4">
              <div>
                <div className="text-sm font-medium text-slate-900 dark:text-slate-100">
                  Keyboard Navigation Shortcuts
                </div>
                <div className="text-xs text-slate-500 dark:text-slate-400">
                  Enable rapid ledger shortcuts (J/K row focus, Cmd+K quick command).
                </div>
              </div>
              <button
                type="button"
                role="switch"
                aria-checked={preferences.keyboardShortcuts}
                onClick={() =>
                  onUpdatePreferences({ keyboardShortcuts: !preferences.keyboardShortcuts })
                }
                className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-150 ${
                  preferences.keyboardShortcuts
                    ? 'bg-blue-700 dark:bg-blue-600'
                    : 'bg-slate-300 dark:bg-slate-700'
                }`}
              >
                <span
                  className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-150 ${
                    preferences.keyboardShortcuts ? 'translate-x-5' : 'translate-x-0'
                  }`}
                />
              </button>
            </div>
          </div>
        </div>
      </section>

      {/* Notification Delivery Matrix */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden">
        <div className="p-6 border-b border-slate-200 dark:border-slate-800">
          <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
            Operational Alert Delivery
          </h2>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Choose how Meridian ERP routes workflow approvals, exceptions, and security notices.
          </p>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="border-b border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/50 text-xs font-medium text-slate-500 dark:text-slate-400">
                <th className="py-2.5 pl-6 pr-4">Event Category</th>
                <th className="py-2.5 px-4 text-center w-24">In-App</th>
                <th className="py-2.5 px-4 text-center w-24">Email</th>
                <th className="py-2.5 pl-4 pr-6 text-center w-28">Daily Digest</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200 dark:divide-slate-800 text-sm">
              {preferences.notifications.map((item) => (
                <tr key={item.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                  <td className="py-3.5 pl-6 pr-4">
                    <div className="font-medium text-slate-900 dark:text-slate-100">
                      {item.category}
                    </div>
                    <div className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                      {item.description}
                    </div>
                  </td>
                  {(['inApp', 'email', 'digest'] as const).map((channel) => (
                    <td key={channel} className="py-3.5 px-4 text-center">
                      <input
                        type="checkbox"
                        aria-label={`${item.category} ${channel}`}
                        checked={item[channel]}
                        onChange={() => toggleNotificationChannel(item.id, channel)}
                        className="h-4 w-4 rounded border-slate-300 text-blue-700 focus:ring-blue-600 cursor-pointer"
                      />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
