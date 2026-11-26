import {
  Bell,
  CalendarDays,
  ChevronDown,
  Clock,
  LayoutGrid,
  Menu,
  Moon,
  Settings,
  Sun,
  X,
} from 'lucide-react';
import { useState } from 'react';
import type { EmployeeProfile, NavView } from '../types/attendance';

interface SidebarProps {
  activeView: NavView;
  onNavigate: (view: NavView) => void;
  profiles: EmployeeProfile[];
  activeProfile: EmployeeProfile;
  onSelectProfile: (profile: EmployeeProfile) => void;
  darkMode: boolean;
  onToggleDarkMode: () => void;
  unreadNotifications: number;
  onOpenNotifications: () => void;
  mobileOpen: boolean;
  onCloseMobile: () => void;
  collapsed: boolean;
  onToggleCollapsed: () => void;
}

const NAV_ITEMS: { id: NavView; label: string; icon: typeof LayoutGrid }[] = [
  { id: 'overview', label: 'All Modules', icon: LayoutGrid },
  { id: 'clock', label: 'Clock In/Out', icon: Clock },
  { id: 'records', label: 'View Records', icon: CalendarDays },
  { id: 'settings', label: 'Settings', icon: Settings },
];

export function Sidebar({
  activeView,
  onNavigate,
  profiles,
  activeProfile,
  onSelectProfile,
  darkMode,
  onToggleDarkMode,
  unreadNotifications,
  onOpenNotifications,
  mobileOpen,
  onCloseMobile,
  collapsed,
  onToggleCollapsed,
}: SidebarProps) {
  const [profileMenuOpen, setProfileMenuOpen] = useState(false);

  const sidebarBody = (
    <div className="flex h-full flex-col justify-between bg-white px-3.5 py-5 text-slate-700 dark:bg-slate-900 dark:text-slate-300">
      <div>
        {/* Brand Header */}
        <div className="mb-6 flex items-center justify-between gap-2 px-1.5">
          {!collapsed && (
            <div className="flex items-center gap-2.5 min-w-0">
              <div
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-400 text-slate-950 font-bold text-xs tracking-tight shadow-xs"
                aria-hidden="true"
              >
                UGT
              </div>
              <div className="min-w-0">
                <span className="block whitespace-nowrap text-[13px] font-bold tracking-tight text-slate-900 dark:text-white">
                  Ultimate General
                </span>
                <span className="block whitespace-nowrap text-[11px] font-medium text-slate-500 dark:text-slate-400">
                  Trading · Time Portal
                </span>
              </div>
            </div>
          )}
          <button
            type="button"
            onClick={mobileOpen ? onCloseMobile : onToggleCollapsed}
            aria-label={mobileOpen ? 'Close navigation menu' : collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
          >
            {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </button>
        </div>

        {/* Profile Switcher */}
        <div className="relative mb-6">
          <button
            type="button"
            onClick={() => setProfileMenuOpen((prev) => !prev)}
            aria-expanded={profileMenuOpen}
            aria-label={`Switch active profile, currently ${activeProfile.name}`}
            className={`flex w-full items-center gap-3 rounded-xl border border-slate-200/80 bg-slate-50/70 p-2.5 text-left transition-colors hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:border-slate-800 dark:bg-slate-800/60 dark:hover:bg-slate-800 ${
              collapsed ? 'justify-center px-2' : ''
            }`}
          >
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-100">
              {activeProfile.initials}
            </span>
            {!collapsed && (
              <>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white">
                    {activeProfile.name}
                  </span>
                  <span className="block truncate text-xs font-medium text-blue-600 dark:text-sky-400">
                    {activeProfile.role}
                  </span>
                </span>
                <ChevronDown
                  className={`h-4 w-4 shrink-0 text-slate-400 transition-transform ${
                    profileMenuOpen ? 'rotate-180' : ''
                  }`}
                />
              </>
            )}
          </button>

          {profileMenuOpen && !collapsed && (
            <div
              role="menu"
              aria-label="Employee profiles"
              className="absolute left-0 right-0 z-30 mt-1.5 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-900"
            >
              {profiles.map((profile) => {
                const isSelected = profile.id === activeProfile.id;
                return (
                  <button
                    key={profile.id}
                    type="button"
                    role="menuitem"
                    onClick={() => {
                      onSelectProfile(profile);
                      setProfileMenuOpen(false);
                    }}
                    className={`flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-xs transition-colors ${
                      isSelected
                        ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-sky-300'
                        : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
                    }`}
                  >
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-200 text-[10px] font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200">
                      {profile.initials}
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate font-semibold">{profile.name}</span>
                      <span className="block truncate text-[11px] opacity-75">{profile.department}</span>
                    </span>
                  </button>
                );
              })}
            </div>
          )}
        </div>

        {/* Main Navigation */}
        <div className="mb-6">
          {!collapsed && (
            <p className="mb-2 px-3 text-[11px] font-semibold tracking-wider text-slate-400 uppercase dark:text-slate-500">
              Main
            </p>
          )}
          <nav aria-label="Main navigation" className="space-y-1">
            {NAV_ITEMS.map(({ id, label, icon: Icon }) => {
              const isActive = activeView === id;
              return (
                <button
                  key={id}
                  type="button"
                  onClick={() => {
                    onNavigate(id);
                    onCloseMobile();
                  }}
                  aria-current={isActive ? 'page' : undefined}
                  title={collapsed ? label : undefined}
                  className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors whitespace-nowrap shrink-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 ${
                    collapsed ? 'justify-center px-2' : ''
                  } ${
                    isActive
                      ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-sky-300'
                      : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white'
                  }`}
                >
                  <Icon className="h-4.5 w-4.5 shrink-0" />
                  {!collapsed && <span>{label}</span>}
                </button>
              );
            })}
          </nav>
        </div>

        {/* Quick Actions */}
        <div>
          {!collapsed && (
            <p className="mb-2 px-3 text-[11px] font-semibold tracking-wider text-slate-400 uppercase dark:text-slate-500">
              Quick
            </p>
          )}
          <div className="space-y-1">
            <button
              type="button"
              onClick={onOpenNotifications}
              title={collapsed ? 'Notifications' : undefined}
              className={`flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100 hover:text-slate-900 whitespace-nowrap shrink-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white ${
                collapsed ? 'justify-center px-2' : ''
              }`}
            >
              <span className="flex items-center gap-3">
                <Bell className="h-4.5 w-4.5 shrink-0" />
                {!collapsed && <span>Notifications</span>}
              </span>
              {!collapsed && unreadNotifications > 0 && (
                <span className="text-xs font-mono font-semibold text-blue-600 dark:text-sky-400 tabular-nums">
                  {unreadNotifications}
                </span>
              )}
            </button>

            <button
              type="button"
              onClick={onToggleDarkMode}
              title={collapsed ? (darkMode ? 'Light Mode' : 'Dark Mode') : undefined}
              className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100 hover:text-slate-900 whitespace-nowrap shrink-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white ${
                collapsed ? 'justify-center px-2' : ''
              }`}
            >
              {darkMode ? (
                <Sun className="h-4.5 w-4.5 shrink-0 text-amber-400" />
              ) : (
                <Moon className="h-4.5 w-4.5 shrink-0" />
              )}
              {!collapsed && <span>{darkMode ? 'Light Mode' : 'Dark Mode'}</span>}
            </button>
          </div>
        </div>
      </div>

      {!collapsed && (
        <div className="border-t border-slate-200/80 pt-3 px-2 dark:border-slate-800">
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Shift Target: <span className="font-mono font-medium text-slate-700 dark:text-slate-200 tabular-nums">8.0h</span>
          </p>
        </div>
      )}
    </div>
  );

  return (
    <>
      {/* Desktop Sidebar */}
      <aside
        className={`hidden lg:block shrink-0 border-r border-slate-200/80 transition-[width] duration-200 dark:border-slate-800 ${
          collapsed ? 'w-[76px]' : 'w-[256px]'
        }`}
      >
        <div className="sticky top-0 h-screen">{sidebarBody}</div>
      </aside>

      {/* Mobile Drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 flex lg:hidden">
          <button
            type="button"
            aria-label="Close navigation backdrop"
            onClick={onCloseMobile}
            className="fixed inset-0 bg-slate-950/50 backdrop-blur-xs"
          />
          <aside className="relative z-10 w-[264px] max-w-[85vw] border-r border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900">
            {sidebarBody}
          </aside>
        </div>
      )}
    </>
  );
}
