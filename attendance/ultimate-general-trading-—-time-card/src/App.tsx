import { Menu } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AttendanceTable } from './components/AttendanceTable';
import { KpiStrip } from './components/KpiStrip';
import { NotificationsDrawer } from './components/NotificationsDrawer';
import { RecordsView } from './components/RecordsView';
import { SettingsView } from './components/SettingsView';
import { Sidebar } from './components/Sidebar';
import { TimeCard } from './components/TimeCard';
import {
  createSampleTeamEntries,
  DEFAULT_TIME_SETTINGS,
  EMPLOYEE_PROFILES,
  INITIAL_NOTIFICATIONS,
  INITIAL_RECORDS,
} from './data/seedData';
import type {
  AttendanceEntry,
  EmployeeProfile,
  NavView,
  NotificationItem,
  TimeSettings,
} from './types/attendance';
import { calculateWorkedHours, formatShortTimestamp } from './utils/timeUtils';

export default function App() {
  const [now, setNow] = useState<Date>(() => new Date());
  const [activeView, setActiveView] = useState<NavView>('overview');
  const [activeProfile, setActiveProfile] = useState<EmployeeProfile>(EMPLOYEE_PROFILES[0]);
  const [settings, setSettings] = useState<TimeSettings>(DEFAULT_TIME_SETTINGS);
  const [darkMode, setDarkMode] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [notificationsOpen, setNotificationsOpen] = useState(false);

  const [todayEntries, setTodayEntries] = useState<AttendanceEntry[]>([]);
  const [historicalRecords, setHistoricalRecords] = useState<AttendanceEntry[]>(INITIAL_RECORDS);
  const [notifications, setNotifications] = useState<NotificationItem[]>(INITIAL_NOTIFICATIONS);

  // Live 1-second clock tick
  useEffect(() => {
    const timer = window.setInterval(() => {
      setNow(new Date());
    }, 1000);
    return () => window.clearInterval(timer);
  }, []);

  // Sync dark mode class on <html>
  useEffect(() => {
    const root = document.documentElement;
    if (darkMode) {
      root.classList.add('dark');
    } else {
      root.classList.remove('dark');
    }
  }, [darkMode]);

  const nowMs = now.getTime();
  const activeEntry = todayEntries.find((e) => e.employeeId === activeProfile.id) ?? null;
  const activeStatus = activeEntry?.status ?? 'Standby';

  const activeHours = calculateWorkedHours(
    activeEntry?.clockInMs ?? null,
    activeEntry?.clockOutMs ?? null,
    nowMs,
    activeEntry?.breakTotalMs ?? 0,
    activeEntry?.breakStartMs ?? null,
    settings.dailyTargetHours
  );

  const pushNotification = (title: string, detail: string) => {
    setNotifications((prev) => [
      {
        id: `notif-${Date.now()}`,
        title,
        detail,
        timeLabel: formatShortTimestamp(Date.now(), settings.use24Hour),
        read: false,
      },
      ...prev,
    ]);
  };

  const handleClockIn = () => {
    const timestamp = Date.now();
    const dateLabel = new Date(timestamp).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });

    setTodayEntries((prev) => {
      const existingIndex = prev.findIndex((e) => e.employeeId === activeProfile.id);
      const nextEntry: AttendanceEntry = {
        id: `today-${activeProfile.id}`,
        employeeId: activeProfile.id,
        employeeName: activeProfile.name,
        role: activeProfile.role,
        department: activeProfile.department,
        dateLabel,
        status: 'Clocked In',
        clockInMs: timestamp,
        clockOutMs: null,
        breakStartMs: null,
        breakTotalMs: 0,
      };
      if (existingIndex >= 0) {
        const copy = [...prev];
        copy[existingIndex] = nextEntry;
        return copy;
      }
      return [nextEntry, ...prev];
    });

    pushNotification(
      `${activeProfile.name} Clocked In`,
      `Shift started at ${formatShortTimestamp(timestamp, settings.use24Hour)}.`
    );
  };

  const handleClockOutEntry = (entryId: string) => {
    const timestamp = Date.now();
    let clockedOutTarget: AttendanceEntry | null = null;

    setTodayEntries((prev) =>
      prev.map((entry) => {
        if (entry.id !== entryId) return entry;
        const extraBreak =
          entry.breakStartMs !== null ? Math.max(0, timestamp - entry.breakStartMs) : 0;
        const updated: AttendanceEntry = {
          ...entry,
          status: 'Clocked Out',
          clockOutMs: timestamp,
          breakStartMs: null,
          breakTotalMs: entry.breakTotalMs + extraBreak,
        };
        clockedOutTarget = updated;
        return updated;
      })
    );

    if (clockedOutTarget) {
      const finalized = clockedOutTarget as AttendanceEntry;
      setHistoricalRecords((prev) => [
        { ...finalized, id: `hist-${Date.now()}` },
        ...prev,
      ]);
      pushNotification(
        `${finalized.employeeName} Clocked Out`,
        `Shift completed at ${formatShortTimestamp(timestamp, settings.use24Hour)}.`
      );
    }
  };

  const handleClockOutSelf = () => {
    if (activeEntry) {
      handleClockOutEntry(activeEntry.id);
    }
  };

  const handleToggleBreak = () => {
    if (!activeEntry || activeEntry.status === 'Clocked Out') return;
    const timestamp = Date.now();

    setTodayEntries((prev) =>
      prev.map((entry) => {
        if (entry.id !== activeEntry.id) return entry;
        if (entry.status === 'On Break') {
          const addedBreak =
            entry.breakStartMs !== null ? Math.max(0, timestamp - entry.breakStartMs) : 0;
          return {
            ...entry,
            status: 'Clocked In',
            breakStartMs: null,
            breakTotalMs: entry.breakTotalMs + addedBreak,
          };
        }
        return {
          ...entry,
          status: 'On Break',
          breakStartMs: timestamp,
        };
      })
    );
  };

  const handleLoadSampleTeam = () => {
    const sample = createSampleTeamEntries(Date.now());
    setTodayEntries((prev) => {
      const existingIds = new Set(prev.map((e) => e.employeeId));
      const newEntries = sample.filter((s) => !existingIds.has(s.employeeId));
      return [...prev, ...newEntries];
    });
  };

  const activeTeamCount = todayEntries.filter(
    (e) => e.status === 'Clocked In' || e.status === 'On Break'
  ).length;

  const unreadCount = notifications.filter((n) => !n.read).length;

  return (
    <div className="flex min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
      <Sidebar
        activeView={activeView}
        onNavigate={setActiveView}
        profiles={EMPLOYEE_PROFILES}
        activeProfile={activeProfile}
        onSelectProfile={setActiveProfile}
        darkMode={darkMode}
        onToggleDarkMode={() => setDarkMode((d) => !d)}
        unreadNotifications={unreadCount}
        onOpenNotifications={() => setNotificationsOpen(true)}
        mobileOpen={mobileMenuOpen}
        onCloseMobile={() => setMobileMenuOpen(false)}
        collapsed={sidebarCollapsed}
        onToggleCollapsed={() => setSidebarCollapsed((c) => !c)}
      />

      <div className="flex min-w-0 flex-1 flex-col">
        {/* Mobile Header Bar */}
        <header className="flex items-center justify-between border-b border-slate-200/80 bg-white px-4 py-3 lg:hidden dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center gap-2.5">
            <div
              className="flex h-7 w-7 items-center justify-center rounded-md bg-amber-400 text-[10px] font-bold text-slate-950"
              aria-hidden="true"
            >
              UGT
            </div>
            <span className="text-xs font-bold tracking-wide text-slate-900 uppercase dark:text-white">
              Ultimate General Trading
            </span>
          </div>
          <button
            type="button"
            onClick={() => setMobileMenuOpen(true)}
            aria-label="Open navigation menu"
            className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
          >
            <Menu className="h-5 w-5" />
          </button>
        </header>

        <main className="mx-auto w-full max-w-[1220px] flex-1 space-y-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
          {activeView === 'overview' && (
            <>
              <TimeCard
                now={now}
                profile={activeProfile}
                status={activeStatus}
                clockInMs={activeEntry?.clockInMs ?? null}
                clockOutMs={activeEntry?.clockOutMs ?? null}
                breakStartMs={activeEntry?.breakStartMs ?? null}
                breakTotalMs={activeEntry?.breakTotalMs ?? 0}
                settings={settings}
                onToggle24Hour={(use24Hour) => setSettings((s) => ({ ...s, use24Hour }))}
                onClockIn={handleClockIn}
                onClockOut={handleClockOutSelf}
                onToggleBreak={handleToggleBreak}
              />

              <KpiStrip
                hoursWorked={activeHours.totalHours}
                overtimeHours={activeHours.overtimeHours}
                dailyTargetHours={settings.dailyTargetHours}
                progressPercent={activeHours.progressPercent}
                activeTeamCount={activeTeamCount}
                totalTeamToday={todayEntries.length}
              />

              <AttendanceTable
                entries={todayEntries}
                nowMs={nowMs}
                settings={settings}
                onClockInSelf={handleClockIn}
                onClockOutEntry={handleClockOutEntry}
                onLoadSampleTeam={handleLoadSampleTeam}
              />
            </>
          )}

          {activeView === 'clock' && (
            <>
              <TimeCard
                now={now}
                profile={activeProfile}
                status={activeStatus}
                clockInMs={activeEntry?.clockInMs ?? null}
                clockOutMs={activeEntry?.clockOutMs ?? null}
                breakStartMs={activeEntry?.breakStartMs ?? null}
                breakTotalMs={activeEntry?.breakTotalMs ?? 0}
                settings={settings}
                onToggle24Hour={(use24Hour) => setSettings((s) => ({ ...s, use24Hour }))}
                onClockIn={handleClockIn}
                onClockOut={handleClockOutSelf}
                onToggleBreak={handleToggleBreak}
              />

              <KpiStrip
                hoursWorked={activeHours.totalHours}
                overtimeHours={activeHours.overtimeHours}
                dailyTargetHours={settings.dailyTargetHours}
                progressPercent={activeHours.progressPercent}
                activeTeamCount={activeTeamCount}
                totalTeamToday={todayEntries.length}
              />

              <AttendanceTable
                entries={todayEntries}
                nowMs={nowMs}
                settings={settings}
                onClockInSelf={handleClockIn}
                onClockOutEntry={handleClockOutEntry}
                onLoadSampleTeam={handleLoadSampleTeam}
              />
            </>
          )}

          {activeView === 'records' && (
            <RecordsView
              records={[...todayEntries, ...historicalRecords]}
              nowMs={nowMs}
              settings={settings}
            />
          )}

          {activeView === 'settings' && (
            <SettingsView
              settings={settings}
              onUpdateSettings={(patch) => setSettings((s) => ({ ...s, ...patch }))}
              darkMode={darkMode}
              onToggleDarkMode={() => setDarkMode((d) => !d)}
            />
          )}
        </main>
      </div>

      <NotificationsDrawer
        open={notificationsOpen}
        notifications={notifications}
        onClose={() => setNotificationsOpen(false)}
        onMarkAllRead={() =>
          setNotifications((prev) => prev.map((n) => ({ ...n, read: true })))
        }
      />
    </div>
  );
}
