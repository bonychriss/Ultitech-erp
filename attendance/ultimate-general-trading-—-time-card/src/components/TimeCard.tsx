import { Calendar, CheckCircle2, Clock, Coffee, LogIn, LogOut, PauseCircle, Play } from 'lucide-react';
import { useState } from 'react';
import type { EmployeeProfile, ShiftStatus, TimeSettings } from '../types/attendance';
import {
  formatDurationCompact,
  formatFullDate,
  formatShortTimestamp,
  formatTimeParts,
  getTimeGreeting,
} from '../utils/timeUtils';

interface TimeCardProps {
  now: Date;
  profile: EmployeeProfile;
  status: ShiftStatus;
  clockInMs: number | null;
  clockOutMs: number | null;
  breakStartMs: number | null;
  breakTotalMs: number;
  settings: TimeSettings;
  onToggle24Hour: (use24Hour: boolean) => void;
  onClockIn: () => void;
  onClockOut: () => void;
  onToggleBreak: () => void;
}

const SKYLINE_IMAGE_PATH = '/src/assets/images/skyline_backdrop_1795594215760.jpg';

export function TimeCard({
  now,
  profile,
  status,
  clockInMs,
  clockOutMs,
  breakStartMs,
  breakTotalMs,
  settings,
  onToggle24Hour,
  onClockIn,
  onClockOut,
  onToggleBreak,
}: TimeCardProps) {
  const [imageFailed, setImageFailed] = useState(false);

  const timeParts = formatTimeParts(now, settings.use24Hour);
  const fullDate = formatFullDate(now);
  const greeting = getTimeGreeting(now.getHours());
  const elapsedLabel = formatDurationCompact(
    clockInMs,
    clockOutMs,
    now.getTime(),
    breakTotalMs,
    breakStartMs
  );

  const statusMeta = (() => {
    switch (status) {
      case 'Clocked In':
        return {
          icon: CheckCircle2,
          colorClass: 'text-emerald-300',
          label: `Clocked In · Since ${formatShortTimestamp(clockInMs, settings.use24Hour)}`,
        };
      case 'On Break':
        return {
          icon: Coffee,
          colorClass: 'text-amber-300',
          label: `On Break · Started ${formatShortTimestamp(breakStartMs, settings.use24Hour)}`,
        };
      case 'Clocked Out':
        return {
          icon: CheckCircle2,
          colorClass: 'text-sky-300',
          label: `Shift Complete · Out ${formatShortTimestamp(clockOutMs, settings.use24Hour)}`,
        };
      default:
        return {
          icon: Clock,
          colorClass: 'text-slate-300',
          label: 'Standby · Ready to clock in',
        };
    }
  })();

  const StatusIcon = statusMeta.icon;

  return (
    <section
      aria-label="Time Card"
      data-testid="time-card"
      className="relative overflow-hidden rounded-2xl bg-slate-900 shadow-sm ring-1 ring-slate-900/10 dark:ring-white/10"
    >
      {/* Architectural Skyline Backdrop + Measured Contrast Scrim */}
      {!imageFailed && (
        <img
          src={SKYLINE_IMAGE_PATH}
          alt="City financial district skyline at twilight"
          referrerPolicy="no-referrer"
          onError={() => setImageFailed(true)}
          className="pointer-events-none absolute inset-0 h-full w-full object-cover object-center"
        />
      )}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 bg-gradient-to-r from-slate-950/92 via-slate-950/75 to-slate-950/88"
      />

      {/* Balanced Horizontal Split: Left Context & Actions | Right Temporal Focal Module */}
      <div className="relative z-10 flex flex-col justify-between gap-6 p-6 sm:p-8 lg:flex-row lg:items-center lg:gap-10">
        {/* Left Zone: Identity, Shift Context, and Primary Shift Action */}
        <div className="flex max-w-xl flex-col justify-between">
          <div className="flex flex-wrap items-center gap-2 text-xs font-medium text-slate-300">
            <span>{profile.department}</span>
            <span aria-hidden="true" className="text-slate-500">·</span>
            <span>Shift {profile.shiftWindow}</span>
            <span aria-hidden="true" className="text-slate-500">·</span>
            <span>{profile.role}</span>
          </div>

          <h1 className="mt-2.5 text-2xl font-semibold tracking-tight text-white sm:text-[1.85rem] text-balance">
            {greeting}, {profile.name}
          </h1>
          <p className="mt-1 text-sm text-slate-300">
            Welcome back. Review today&apos;s live time and manage your shift in one place.
          </p>

          {/* Primary Actions + Unboxed Semantic Status */}
          <div className="mt-6 flex flex-wrap items-center gap-3">
            {status === 'Standby' || status === 'Clocked Out' ? (
              <button
                type="button"
                onClick={onClockIn}
                className="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-slate-950 shadow-xs transition-colors hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white whitespace-nowrap shrink-0"
              >
                <LogIn className="h-4 w-4 shrink-0" />
                <span>{status === 'Clocked Out' ? 'Start New Shift' : 'Clock In'}</span>
              </button>
            ) : (
              <>
                <button
                  type="button"
                  onClick={onClockOut}
                  className="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-slate-950 shadow-xs transition-colors hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white whitespace-nowrap shrink-0"
                >
                  <LogOut className="h-4 w-4 shrink-0" />
                  <span>Clock Out</span>
                </button>
                <button
                  type="button"
                  onClick={onToggleBreak}
                  className="inline-flex items-center gap-2 rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-medium text-white backdrop-blur-xs transition-colors hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white whitespace-nowrap shrink-0"
                >
                  {status === 'On Break' ? (
                    <>
                      <Play className="h-4 w-4 shrink-0" />
                      <span>Resume Shift</span>
                    </>
                  ) : (
                    <>
                      <PauseCircle className="h-4 w-4 shrink-0" />
                      <span>Take Break</span>
                    </>
                  )}
                </button>
              </>
            )}

            <div className="flex items-center gap-2 pl-1 text-sm font-medium text-slate-200">
              <StatusIcon className={`h-4 w-4 shrink-0 ${statusMeta.colorClass}`} />
              <span>{statusMeta.label}</span>
            </div>
          </div>
        </div>

        {/* Right Zone: Dedicated High-Contrast Temporal Anchor */}
        <div
          data-testid="time-display-module"
          className="w-full shrink-0 rounded-xl border border-white/15 bg-slate-950/65 px-5 py-4 backdrop-blur-md sm:px-6 sm:py-5 lg:w-[400px]"
        >
          {/* Top Micro-Header: Timezone + 12H/24H Quick Switch */}
          <div className="flex items-center justify-between gap-4">
            <span className="text-xs font-medium tracking-wide text-slate-300 whitespace-nowrap">
              Local Time · {settings.timezoneLabel}
            </span>

            <div
              role="group"
              aria-label="Time format"
              className="inline-flex items-center rounded-lg border border-white/15 bg-white/5 p-0.5"
            >
              <button
                type="button"
                onClick={() => onToggle24Hour(false)}
                aria-pressed={!settings.use24Hour}
                className={`rounded-md px-2 py-0.5 text-[11px] font-semibold transition-colors whitespace-nowrap shrink-0 ${
                  !settings.use24Hour
                    ? 'bg-white text-slate-950'
                    : 'text-slate-300 hover:text-white'
                }`}
              >
                12H
              </button>
              <button
                type="button"
                onClick={() => onToggle24Hour(true)}
                aria-pressed={settings.use24Hour}
                className={`rounded-md px-2 py-0.5 text-[11px] font-semibold transition-colors whitespace-nowrap shrink-0 ${
                  settings.use24Hour
                    ? 'bg-white text-slate-950'
                    : 'text-slate-300 hover:text-white'
                }`}
              >
                24H
              </button>
            </div>
          </div>

          {/* Primary Live Clock Readout */}
          <div className="mt-3 flex items-baseline justify-start lg:justify-end">
            <time
              dateTime={now.toISOString()}
              data-testid="live-clock"
              className="font-mono text-4xl font-semibold tracking-tight text-white tabular-nums sm:text-[3.25rem] leading-none"
            >
              {timeParts.hours}:{timeParts.minutes}
              {settings.showSeconds && (
                <span className="ml-0.5 text-2xl font-normal text-slate-300 sm:text-3xl">
                  :{timeParts.seconds}
                </span>
              )}
            </time>
            {timeParts.period && (
              <span className="ml-2.5 self-start pt-1 font-mono text-xs font-semibold tracking-widest text-sky-300">
                {timeParts.period}
              </span>
            )}
          </div>

          {/* Date & Shift Duration Footer Paired Directly with Clock */}
          <div className="mt-3.5 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-white/12 pt-3 text-xs">
            <span className="inline-flex items-center gap-1.5 font-medium text-slate-200 whitespace-nowrap">
              <Calendar className="h-3.5 w-3.5 shrink-0 text-slate-400" />
              <span>{fullDate}</span>
            </span>
            <span className="font-mono text-slate-300 tabular-nums whitespace-nowrap shrink-0">
              {clockInMs !== null ? `Elapsed ${elapsedLabel}` : `Target ${settings.dailyTargetHours.toFixed(1)}h`}
            </span>
          </div>
        </div>
      </div>
    </section>
  );
}
