import type { TimeSettings } from '../types/attendance';

interface SettingsViewProps {
  settings: TimeSettings;
  onUpdateSettings: (patch: Partial<TimeSettings>) => void;
  darkMode: boolean;
  onToggleDarkMode: () => void;
}

export function SettingsView({
  settings,
  onUpdateSettings,
  darkMode,
  onToggleDarkMode,
}: SettingsViewProps) {
  return (
    <section aria-label="Time card settings" className="max-w-2xl space-y-6">
      <div>
        <h1 className="text-xl font-semibold tracking-tight text-slate-900 dark:text-white">
          Time Card Settings
        </h1>
        <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
          Customize how time, shift targets, and workspace contrast are displayed.
        </p>
      </div>

      <div className="divide-y divide-slate-200/80 rounded-xl border border-slate-200/90 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
        {/* 12h / 24h Format */}
        <div className="flex flex-wrap items-center justify-between gap-4 p-5">
          <div>
            <h2 className="text-sm font-semibold text-slate-900 dark:text-white">
              Clock Format
            </h2>
            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
              Choose between 12-hour AM/PM or 24-hour military notation.
            </p>
          </div>
          <div className="inline-flex rounded-lg bg-slate-100 p-1 dark:bg-slate-800">
            <button
              type="button"
              onClick={() => onUpdateSettings({ use24Hour: false })}
              className={`rounded-md px-3 py-1.5 text-xs font-semibold transition-colors whitespace-nowrap shrink-0 ${
                !settings.use24Hour
                  ? 'bg-white text-slate-900 shadow-xs dark:bg-slate-700 dark:text-white'
                  : 'text-slate-600 dark:text-slate-300'
              }`}
            >
              12-Hour
            </button>
            <button
              type="button"
              onClick={() => onUpdateSettings({ use24Hour: true })}
              className={`rounded-md px-3 py-1.5 text-xs font-semibold transition-colors whitespace-nowrap shrink-0 ${
                settings.use24Hour
                  ? 'bg-white text-slate-900 shadow-xs dark:bg-slate-700 dark:text-white'
                  : 'text-slate-600 dark:text-slate-300'
              }`}
            >
              24-Hour
            </button>
          </div>
        </div>

        {/* Show Seconds */}
        <div className="flex flex-wrap items-center justify-between gap-4 p-5">
          <div>
            <h2 className="text-sm font-semibold text-slate-900 dark:text-white">
              Display Live Seconds
            </h2>
            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
              Show ticking seconds beside hours and minutes on the time card.
            </p>
          </div>
          <button
            type="button"
            role="switch"
            aria-checked={settings.showSeconds}
            onClick={() => onUpdateSettings({ showSeconds: !settings.showSeconds })}
            className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 ${
              settings.showSeconds ? 'bg-blue-600' : 'bg-slate-300 dark:bg-slate-700'
            }`}
          >
            <span
              className={`inline-block h-4.5 w-4.5 transform rounded-full bg-white transition-transform ${
                settings.showSeconds ? 'translate-x-5.5' : 'translate-x-1'
              }`}
            />
          </button>
        </div>

        {/* Daily Shift Target */}
        <div className="flex flex-wrap items-center justify-between gap-4 p-5">
          <div>
            <label
              htmlFor="daily-target-hours"
              className="block text-sm font-semibold text-slate-900 dark:text-white"
            >
              Daily Shift Target
            </label>
            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
              Hours beyond this daily target are automatically counted as overtime.
            </p>
          </div>
          <select
            id="daily-target-hours"
            value={settings.dailyTargetHours}
            onChange={(e) => onUpdateSettings({ dailyTargetHours: Number(e.target.value) })}
            className="rounded-lg border border-slate-200 bg-white px-3 py-2 font-mono text-xs font-medium text-slate-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          >
            <option value={6}>6.0 hours</option>
            <option value={7.5}>7.5 hours</option>
            <option value={8}>8.0 hours</option>
            <option value={9}>9.0 hours</option>
            <option value={10}>10.0 hours</option>
          </select>
        </div>

        {/* Appearance */}
        <div className="flex flex-wrap items-center justify-between gap-4 p-5">
          <div>
            <h2 className="text-sm font-semibold text-slate-900 dark:text-white">
              Workspace Appearance
            </h2>
            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
              Switch between crisp daylight surfaces and dark slate contrast.
            </p>
          </div>
          <button
            type="button"
            onClick={onToggleDarkMode}
            className="rounded-lg border border-slate-200 px-3.5 py-2 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800 whitespace-nowrap shrink-0"
          >
            {darkMode ? 'Switch to Light Mode' : 'Switch to Dark Mode'}
          </button>
        </div>
      </div>
    </section>
  );
}
