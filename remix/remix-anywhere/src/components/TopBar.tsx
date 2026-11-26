import { Menu } from 'lucide-react';
import { SettingsTab } from '../types/erpProfile';

interface TopBarProps {
  activeTab: SettingsTab;
  onSelectTab: (tab: SettingsTab) => void;
  hasUnsavedChanges: boolean;
  onSave: () => void;
  onOpenMobileMenu: () => void;
}

const NAV_ITEMS: { id: SettingsTab; label: string }[] = [
  { id: 'profile', label: 'Profile' },
  { id: 'security', label: 'Security' },
  { id: 'approvals', label: 'Approvals' },
  { id: 'preferences', label: 'Preferences' },
  { id: 'activity', label: 'Activity Log' },
];

export default function TopBar({
  activeTab,
  onSelectTab,
  hasUnsavedChanges,
  onSave,
  onOpenMobileMenu,
}: TopBarProps) {
  return (
    <header className="sticky top-0 z-30 h-16 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-4 sm:px-6 lg:px-8 flex items-center justify-between gap-8">
      {/* Zone 1: Single text element wordmark */}
      <div className="flex items-center gap-3 shrink-0">
        <button
          type="button"
          onClick={onOpenMobileMenu}
          aria-label="Open settings navigation"
          className="lg:hidden p-2 -ml-2 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-blue-600 transition-colors"
        >
          <Menu className="w-5 h-5" />
        </button>
        <a
          href="#profile"
          onClick={(e) => {
            e.preventDefault();
            onSelectTab('profile');
          }}
          className="text-base font-bold tracking-tight text-slate-900 dark:text-slate-100 whitespace-nowrap shrink-0 font-[var(--font-display)]"
        >
          Meridian ERP
        </a>
      </div>

      {/* Zone 2: 5 concise single-line text navigation links */}
      <nav aria-label="Settings sections" className="hidden md:flex items-center gap-6 h-full">
        {NAV_ITEMS.map((item) => {
          const isActive = activeTab === item.id;
          return (
            <button
              key={item.id}
              type="button"
              onClick={() => onSelectTab(item.id)}
              className={`relative h-full inline-flex items-center text-sm font-medium whitespace-nowrap shrink-0 transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-blue-600 ${
                isActive
                  ? 'text-blue-700 dark:text-blue-400'
                  : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100'
              }`}
            >
              <span>{item.label}</span>
              {isActive && (
                <span
                  aria-hidden="true"
                  className="absolute bottom-0 left-0 right-0 h-0.5 bg-blue-700 dark:bg-blue-400"
                />
              )}
            </button>
          );
        })}
      </nav>

      {/* Zone 3: 1 primary action */}
      <div className="flex items-center shrink-0">
        <button
          type="button"
          onClick={onSave}
          disabled={!hasUnsavedChanges}
          className={`px-4 py-2 text-sm font-medium rounded-lg whitespace-nowrap shrink-0 transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 ${
            hasUnsavedChanges
              ? 'bg-blue-700 text-white hover:bg-blue-800 dark:bg-blue-600 dark:hover:bg-blue-500 cursor-pointer'
              : 'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500 cursor-not-allowed'
          }`}
        >
          Save Changes
        </button>
      </div>
    </header>
  );
}
