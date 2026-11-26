import { CheckCircle2, Clock, Coffee, LogIn, UserPlus } from 'lucide-react';
import type { AttendanceEntry, TimeSettings } from '../types/attendance';
import {
  calculateWorkedHours,
  formatHoursDecimal,
  formatShortTimestamp,
} from '../utils/timeUtils';

interface AttendanceTableProps {
  entries: AttendanceEntry[];
  nowMs: number;
  settings: TimeSettings;
  onClockInSelf: () => void;
  onClockOutEntry: (entryId: string) => void;
  onLoadSampleTeam: () => void;
}

function StatusLabel({ status }: { status: AttendanceEntry['status'] }) {
  switch (status) {
    case 'Clocked In':
      return (
        <span className="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">
          <CheckCircle2 className="h-3.5 w-3.5 shrink-0" />
          <span>Clocked In</span>
        </span>
      );
    case 'On Break':
      return (
        <span className="inline-flex items-center gap-1.5 text-xs font-medium text-amber-700 dark:text-amber-400">
          <Coffee className="h-3.5 w-3.5 shrink-0" />
          <span>On Break</span>
        </span>
      );
    case 'Clocked Out':
      return (
        <span className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-400">
          <Clock className="h-3.5 w-3.5 shrink-0" />
          <span>Clocked Out</span>
        </span>
      );
    default:
      return (
        <span className="text-xs font-medium text-slate-500 dark:text-slate-400">
          {status}
        </span>
      );
  }
}

export function AttendanceTable({
  entries,
  nowMs,
  settings,
  onClockInSelf,
  onClockOutEntry,
  onLoadSampleTeam,
}: AttendanceTableProps) {
  return (
    <section aria-label="Logged in today" className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-base font-semibold tracking-tight text-slate-900 dark:text-white">
          Logged in today
        </h2>
        <div className="flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
          <span className="font-mono tabular-nums">
            {entries.length === 0
              ? 'No one yet'
              : `${entries.length} ${entries.length === 1 ? 'person' : 'people'}`}
          </span>
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200/90 bg-white dark:border-slate-800 dark:bg-slate-900">
        {entries.length === 0 ? (
          <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
              <Clock className="h-6 w-6" />
            </div>
            <h3 className="mt-4 text-sm font-semibold text-slate-900 dark:text-white">
              No one has clocked in yet today
            </h3>
            <p className="mt-1 max-w-sm text-xs text-slate-500 dark:text-slate-400">
              People who clock in will appear here for today with live shift status and hours.
            </p>
            <div className="mt-5 flex flex-wrap items-center justify-center gap-3">
              <button
                type="button"
                onClick={onClockInSelf}
                className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-100 whitespace-nowrap shrink-0"
              >
                <LogIn className="h-3.5 w-3.5" />
                <span>Clock In Now</span>
              </button>
              <button
                type="button"
                onClick={onLoadSampleTeam}
                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-4 py-2 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800 whitespace-nowrap shrink-0"
              >
                <UserPlus className="h-3.5 w-3.5" />
                <span>Load Sample Team</span>
              </button>
            </div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[600px] border-collapse text-left text-sm">
              <thead>
                <tr className="border-b border-slate-200/80 bg-slate-50/70 text-xs font-medium text-slate-500 dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400">
                  <th className="px-5 py-3 font-medium">Employee</th>
                  <th className="px-5 py-3 font-medium">Status</th>
                  <th className="px-5 py-3 font-medium">In</th>
                  <th className="px-5 py-3 font-medium">Out</th>
                  <th className="px-5 py-3 text-right font-medium">Hours</th>
                  <th className="px-5 py-3 text-right font-medium">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800/80">
                {entries.map((entry) => {
                  const breakdown = calculateWorkedHours(
                    entry.clockInMs,
                    entry.clockOutMs,
                    nowMs,
                    entry.breakTotalMs,
                    entry.breakStartMs,
                    settings.dailyTargetHours
                  );

                  return (
                    <tr
                      key={entry.id}
                      className="transition-colors hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                    >
                      <td className="px-5 py-3">
                        <div className="font-medium text-slate-900 dark:text-white">
                          {entry.employeeName}
                        </div>
                        <div className="text-xs text-slate-500 dark:text-slate-400">
                          {entry.role} · {entry.department}
                        </div>
                      </td>
                      <td className="px-5 py-3 whitespace-nowrap">
                        <StatusLabel status={entry.status} />
                      </td>
                      <td className="px-5 py-3 font-mono text-xs text-slate-700 dark:text-slate-300 tabular-nums whitespace-nowrap">
                        {formatShortTimestamp(entry.clockInMs, settings.use24Hour)}
                      </td>
                      <td className="px-5 py-3 font-mono text-xs text-slate-700 dark:text-slate-300 tabular-nums whitespace-nowrap">
                        {formatShortTimestamp(entry.clockOutMs, settings.use24Hour)}
                      </td>
                      <td className="px-5 py-3 text-right font-mono text-xs font-semibold text-slate-900 dark:text-white tabular-nums whitespace-nowrap">
                        {formatHoursDecimal(breakdown.totalHours)}
                      </td>
                      <td className="px-5 py-3 text-right whitespace-nowrap">
                        {entry.status !== 'Clocked Out' ? (
                          <button
                            type="button"
                            onClick={() => onClockOutEntry(entry.id)}
                            aria-label={`Clock out ${entry.employeeName}`}
                            className="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                          >
                            Clock Out
                          </button>
                        ) : (
                          <span className="text-xs text-slate-400 dark:text-slate-500">
                            Completed
                          </span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  );
}
