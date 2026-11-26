import {
  User,
  Shield,
  FileCheck2,
  SlidersHorizontal,
  History,
  Search,
  X,
} from 'lucide-react';
import Avatar from './Avatar';
import { ErpProfileState, SettingsTab } from '../types/erpProfile';

interface SettingsSidebarProps {
  profile: ErpProfileState;
  activeTab: SettingsTab;
  onSelectTab: (tab: SettingsTab) => void;
  searchQuery: string;
  onSearchChange: (q: string) => void;
  mobileOpen: boolean;
  onCloseMobile: () => void;
}

const SIDEBAR_SECTIONS: {
  id: SettingsTab;
  label: string;
  subtitle: string;
  icon: typeof User;
  keywords: string[];
}[] = [
  {
    id: 'profile',
    label: 'Profile & Identity',
    subtitle: 'Directory, contact, cost center',
    icon: User,
    keywords: ['name', 'email', 'phone', 'avatar', 'photo', 'role', 'cost center', 'department', 'manager', 'entity'],
  },
  {
    id: 'security',
    label: 'Security & Access',
    subtitle: 'Password, MFA, sessions, tokens',
    icon: Shield,
    keywords: ['password', 'mfa', '2fa', 'authenticator', 'yubikey', 'hardware', 'session', 'device', 'api', 'token', 'backup'],
  },
  {
    id: 'approvals',
    label: 'Approvals & Delegation',
    subtitle: 'Limits, out-of-office routing',
    icon: FileCheck2,
    keywords: ['approval', 'limit', 'purchase order', 'delegation', 'out of office', 'ooo', 'escalation', 'sla'],
  },
  {
    id: 'preferences',
    label: 'Regional & Notifications',
    subtitle: 'Fiscal calendar, theme, alerts',
    icon: SlidersHorizontal,
    keywords: ['language', 'timezone', 'date', 'currency', 'fiscal', 'theme', 'dark', 'light', 'density', 'notification', 'email', 'digest'],
  },
  {
    id: 'activity',
    label: 'Activity Log',
    subtitle: 'Profile & security audit trail',
    icon: History,
    keywords: ['audit', 'log', 'history', 'ip', 'export', 'csv'],
  },
];

export default function SettingsSidebar({
  profile,
  activeTab,
  onSelectTab,
  searchQuery,
  onSearchChange,
  mobileOpen,
  onCloseMobile,
}: SettingsSidebarProps) {
  const normalized = searchQuery.trim().toLowerCase();

  const matchingSections = normalized
    ? SIDEBAR_SECTIONS.filter(
        (sec) =>
          sec.label.toLowerCase().includes(normalized) ||
          sec.subtitle.toLowerCase().includes(normalized) ||
          sec.keywords.some((k) => k.includes(normalized))
      )
    : SIDEBAR_SECTIONS;

  const sidebarBody = (
    <div className="flex flex-col h-full">
      {/* Compact User Context Block */}
      <div className="p-5 border-b border-slate-200 dark:border-slate-800">
        <div className="flex items-center gap-3">
          <Avatar
            src={profile.identity.avatarUrl}
            firstName={profile.identity.firstName}
            lastName={profile.identity.lastName}
            size="md"
          />
          <div className="min-w-0">
            <p className="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">
              {profile.identity.firstName} {profile.identity.lastName}
            </p>
            <p className="text-xs text-slate-500 dark:text-slate-400 truncate">
              {profile.identity.jobTitle}
            </p>
          </div>
        </div>
        <div className="mt-3 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400 font-mono tabular-nums truncate">
          <span>{profile.organization.employeeId}</span>
          <span aria-hidden="true">·</span>
          <span>{profile.organization.costCenterCode}</span>
        </div>
      </div>

      {/* Quick Settings Filter */}
      <div className="px-4 pt-4 pb-2">
        <label htmlFor="settings-jump-search" className="sr-only">
          Filter settings sections
        </label>
        <div className="relative">
          <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input
            id="settings-jump-search"
            type="text"
            value={searchQuery}
            onChange={(e) => onSearchChange(e.target.value)}
            placeholder="Find a setting..."
            className="w-full pl-9 pr-8 py-2 text-sm bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none focus:border-blue-600 dark:focus:border-blue-500"
          />
          {searchQuery && (
            <button
              type="button"
              onClick={() => onSearchChange('')}
              aria-label="Clear search"
              className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
            >
              <X className="w-3.5 h-3.5" />
            </button>
          )}
        </div>
      </div>

      {/* Navigation Links */}
      <nav aria-label="Account settings navigation" className="flex-1 px-3 py-2 space-y-1 overflow-y-auto">
        {matchingSections.length === 0 ? (
          <div className="px-3 py-6 text-center">
            <p className="text-xs text-slate-500 dark:text-slate-400">
              No setting sections match &ldquo;{searchQuery}&rdquo;.
            </p>
            <button
              type="button"
              onClick={() => onSearchChange('')}
              className="mt-2 text-xs font-medium text-blue-700 dark:text-blue-400 hover:underline"
            >
              Reset filter
            </button>
          </div>
        ) : (
          matchingSections.map((sec) => {
            const Icon = sec.icon;
            const isActive = activeTab === sec.id;
            return (
              <button
                key={sec.id}
                type="button"
                onClick={() => {
                  onSelectTab(sec.id);
                  onCloseMobile();
                }}
                className={`w-full flex items-start gap-3 px-3 py-2.5 rounded-lg text-left transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-blue-600 ${
                  isActive
                    ? 'bg-blue-50/80 dark:bg-blue-950/50 text-blue-800 dark:text-blue-300'
                    : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800/60'
                }`}
              >
                <Icon
                  className={`w-4 h-4 mt-0.5 shrink-0 ${
                    isActive
                      ? 'text-blue-700 dark:text-blue-400'
                      : 'text-slate-400 dark:text-slate-500'
                  }`}
                />
                <div className="min-w-0">
                  <div className="text-sm font-medium leading-tight whitespace-nowrap truncate">
                    {sec.label}
                  </div>
                  <div
                    className={`text-xs mt-0.5 truncate ${
                      isActive
                        ? 'text-blue-700/80 dark:text-blue-300/75'
                        : 'text-slate-500 dark:text-slate-400'
                    }`}
                  >
                    {sec.subtitle}
                  </div>
                </div>
              </button>
            );
          })
        )}
      </nav>

      {/* Quiet Workspace Context Footer */}
      <div className="p-4 border-t border-slate-200 dark:border-slate-800 text-xs text-slate-500 dark:text-slate-400">
        <div className="font-medium text-slate-700 dark:text-slate-300 truncate">
          {profile.organization.legalEntity}
        </div>
        <div className="mt-0.5 truncate">{profile.organization.siteLocation}</div>
      </div>
    </div>
  );

  return (
    <>
      {/* Desktop Fixed Width Sidebar */}
      <aside className="hidden lg:block w-64 shrink-0 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800">
        <div className="sticky top-16 h-[calc(100vh-4rem)]">{sidebarBody}</div>
      </aside>

      {/* Mobile Slide-Over Drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden flex">
          <div
            className="fixed inset-0 bg-slate-900/40 backdrop-blur-[1px]"
            onClick={onCloseMobile}
            aria-hidden="true"
          />
          <aside className="relative w-72 max-w-[85vw] bg-white dark:bg-slate-900 h-full border-r border-slate-200 dark:border-slate-800 flex flex-col">
            <div className="h-14 px-4 flex items-center justify-between border-b border-slate-200 dark:border-slate-800">
              <span className="text-sm font-semibold text-slate-900 dark:text-slate-100">
                Account Settings
              </span>
              <button
                type="button"
                onClick={onCloseMobile}
                aria-label="Close navigation"
                className="p-2 rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="flex-1 overflow-hidden">{sidebarBody}</div>
          </aside>
        </div>
      )}
    </>
  );
}
