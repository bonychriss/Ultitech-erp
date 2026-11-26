import { Download, Search } from 'lucide-react';
import { useState } from 'react';
import type { AttendanceEntry, TimeSettings } from '../types/attendance';
import {
  calculateWorkedHours,
  filterAttendanceRecords,
  formatHoursDecimal,
  formatShortTimestamp,
} from '../utils/timeUtils';

interface RecordsViewProps {
  records: AttendanceEntry[];
  nowMs: number;
  settings: TimeSettings;
}

type RecordFilter = 'All' | 'Clocked In' | 'On Break' | 'Clocked Out' | 'Overtime';

const FILTER_TABS: RecordFilter[] = ['All', 'Clocked In', 'On Break', 'Clocked Out', 'Overtime'];

export function RecordsView({ records, nowMs, settings }: RecordsViewProps) {
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<RecordFilter>('All');

  const filtered = filterAttendanceRecords(
    records,
    query,
    statusFilter,
    nowMs,
    settings.dailyTargetHours
  );

  const handleExportCsv = () => {
    const header = ['Employee', 'Role', 'Department', 'Date', 'Status', 'In', 'Out', 'Hours'];
    const rows = filtered.map((entry) => {
      const breakdown = calculateWorkedHours(
        entry.clockInMs,
        entry.clockOutMs,
        nowMs,
        entry.breakTotalMs,
        entry.breakStartMs,
        settings.dailyTargetHours
      );
      return [
        entry.employeeName,
        entry.role,
        entry.department,
        entry.dateLabel,
        entry.status,
        formatShortTimestamp(entry.clockInMs, settings.use24Hour),
        formatShortTimestamp(entry.clockOutMs, settings.use24Hour),
        formatHoursDecimal(breakdown.totalHours),
      ];
    });
    const csv = [header, ...rows]
      .map((cols) => cols.map((c) => `"${String(c).replaceAll('"', '""')}"`).join(','))
      .join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'attendance-records.csv';
    link.click();
    URL.revokeObjectURL(url);
  };

  return (
    <section aria-label="Attendance records" className="space-y-5">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-slate-900 dark:text-white">
            Attendance Records
          </h1>
          <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
            Review recent shifts, check-in timestamps, and overtime totals.
          </p>
        </div>

        <button
          type="button"
          onClick={handleExportCsv}
          className="inline-flex items-center gap-2 self-start rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 whitespace-nowrap shrink-0"
        >
          <Download className="h-4 w-4" />
          <span>Export CSV</span>
        </button>
      </div>

      {/* Filter & Search Toolbar */}
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div
          role="tablist"
          aria-label="Filter records by status"
          className="inline-flex flex-wrap items-center gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-900"
        >
          {FILTER_TABS.map((tab) => {
            const active = statusFilter === tab;
            return (
              <button
                key={tab}
                type="button"
                role="tab"
                aria-selected={active}
                onClick={() => setStatusFilter(tab)}
                className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-colors whitespace-nowrap shrink-0 ${
                  active
                    ? 'bg-white text-slate-900 shadow-xs dark:bg-slate-800 dark:text-white'
                    : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'
                }`}
              >
                {tab}
              </button>
            );
          })}
        </div>

        <div className="relative w-full sm:w-64">
          <label htmlFor="records-search" className="sr-only">
            Search records
          </label>
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            id="records-search"
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search employee or date..."
            className="w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-3 text-xs text-slate-900 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none dark:border-slate-800 dark:bg-slate-900 dark:text-white"
          />
        </div>
      </div>

      {/* Records Grid */}
      <div className="overflow-hidden rounded-xl border border-slate-200/90 bg-white dark:border-slate-800 dark:bg-slate-900">
        {filtered.length === 0 ? (
          <div className="px-6 py-12 text-center">
            <p className="text-sm font-medium text-slate-900 dark:text-white">
              No matching records found
            </p>
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
              Try clearing your search or choosing a different status filter.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] border-collapse text-left text-sm">
              <thead>
                <tr className="border-b border-slate-200/80 bg-slate-50/70 text-xs font-medium text-slate-500 dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400">
                  <th className="px-5 py-3 font-medium">Employee</th>
                  <th className="px-5 py-3 font-medium">Date</th>
                  <th className="px-5 py-3 font-medium">Status</th>
                  <th className="px-5 py-3 font-medium">In</th>
                  <th className="px-5 py-3 font-medium">Out</th>
                  <th className="px-5 py-3 text-right font-medium">Total</th>
                  <th className="px-5 py-3 text-right font-medium">Overtime</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800/80">
                {filtered.map((entry) => {
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
                          {entry.department}
                        </div>
                      </td>
                      <td className="px-5 py-3 text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap">
                        {entry.dateLabel}
                      </td>
                      <td className="px-5 py-3 text-xs font-medium text-slate-700 dark:text-slate-300 whitespace-nowrap">
                        {entry.status}
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
                      <td className="px-5 py-3 text-right font-mono text-xs font-medium text-amber-600 dark:text-amber-400 tabular-nums whitespace-nowrap">
                        {formatHoursDecimal(breakdown.overtimeHours)}
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
