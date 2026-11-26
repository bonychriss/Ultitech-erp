import { Briefcase, Clock, Users } from 'lucide-react';
import { formatHoursDecimal } from '../utils/timeUtils';

interface KpiStripProps {
  hoursWorked: number;
  overtimeHours: number;
  dailyTargetHours: number;
  progressPercent: number;
  activeTeamCount: number;
  totalTeamToday: number;
}

export function KpiStrip({
  hoursWorked,
  overtimeHours,
  dailyTargetHours,
  progressPercent,
  activeTeamCount,
  totalTeamToday,
}: KpiStripProps) {
  return (
    <section aria-label="Today's shift metrics" className="grid grid-cols-1 gap-4 md:grid-cols-3">
      {/* Hours Worked */}
      <div className="rounded-xl border border-slate-200/90 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-center gap-3.5">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400">
              <Clock className="h-5 w-5" />
            </div>
            <div>
              <p className="text-xs font-medium text-slate-500 dark:text-slate-400">
                Hours Worked
              </p>
              <p
                data-testid="kpi-hours-worked"
                className="mt-0.5 font-mono text-2xl font-semibold tracking-tight text-slate-900 dark:text-white tabular-nums"
              >
                {formatHoursDecimal(hoursWorked)}
              </p>
            </div>
          </div>
          <span className="font-mono text-xs text-slate-500 dark:text-slate-400 tabular-nums">
            {progressPercent}% of {dailyTargetHours.toFixed(1)}h
          </span>
        </div>

        <div className="mt-3.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
          <div
            className="h-full rounded-full bg-sky-600 transition-[width] duration-300 dark:bg-sky-400"
            style={{ width: `${progressPercent}%` }}
          />
        </div>
      </div>

      {/* Overtime */}
      <div className="rounded-xl border border-slate-200/90 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-center gap-3.5">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
              <Briefcase className="h-5 w-5" />
            </div>
            <div>
              <p className="text-xs font-medium text-slate-500 dark:text-slate-400">
                Overtime
              </p>
              <p
                data-testid="kpi-overtime"
                className="mt-0.5 font-mono text-2xl font-semibold tracking-tight text-amber-600 dark:text-amber-400 tabular-nums"
              >
                {formatHoursDecimal(overtimeHours)}
              </p>
            </div>
          </div>
          <span className="text-xs text-slate-500 dark:text-slate-400">
            {overtimeHours > 0 ? 'Extra shift time' : 'Within regular shift'}
          </span>
        </div>

        <p className="mt-3.5 text-xs text-slate-500 dark:text-slate-400">
          Accrues automatically after {dailyTargetHours.toFixed(1)} net hours
        </p>
      </div>

      {/* Active Staff Today */}
      <div className="rounded-xl border border-slate-200/90 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-center gap-3.5">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
              <Users className="h-5 w-5" />
            </div>
            <div>
              <p className="text-xs font-medium text-slate-500 dark:text-slate-400">
                Team Logged Today
              </p>
              <p
                data-testid="kpi-team-count"
                className="mt-0.5 font-mono text-2xl font-semibold tracking-tight text-slate-900 dark:text-white tabular-nums"
              >
                {totalTeamToday}
              </p>
            </div>
          </div>
          <span className="font-mono text-xs text-emerald-600 dark:text-emerald-400 tabular-nums">
            {activeTeamCount} active now
          </span>
        </div>

        <p className="mt-3.5 text-xs text-slate-500 dark:text-slate-400">
          Live shift check-ins across all desks
        </p>
      </div>
    </section>
  );
}
