import { useState } from 'react';
import { Download, Search, X } from 'lucide-react';
import { AuditLogEntry } from '../../types/erpProfile';

interface ActivityLogSectionProps {
  entries: AuditLogEntry[];
  tableDensity: 'compact' | 'comfortable';
}

const CATEGORIES: ('All' | AuditLogEntry['category'])[] = [
  'All',
  'Profile',
  'Security',
  'Approvals',
  'Preferences',
];

export default function ActivityLogSection({
  entries,
  tableDensity,
}: ActivityLogSectionProps) {
  const [categoryFilter, setCategoryFilter] = useState<'All' | AuditLogEntry['category']>('All');
  const [searchTerm, setSearchTerm] = useState('');

  const normalizedQuery = searchTerm.trim().toLowerCase();

  const filtered = entries.filter((entry) => {
    const matchesCat = categoryFilter === 'All' || entry.category === categoryFilter;
    const matchesQuery =
      !normalizedQuery ||
      entry.action.toLowerCase().includes(normalizedQuery) ||
      entry.target.toLowerCase().includes(normalizedQuery) ||
      entry.ipAddress.toLowerCase().includes(normalizedQuery) ||
      entry.category.toLowerCase().includes(normalizedQuery);
    return matchesCat && matchesQuery;
  });

  const handleExportCsv = () => {
    const header = ['Timestamp', 'Category', 'Action', 'Target', 'IP Address'];
    const rows = filtered.map((e) => [
      e.timestamp,
      e.category,
      `"${e.action.replace(/"/g, '""')}"`,
      `"${e.target.replace(/"/g, '""')}"`,
      e.ipAddress,
    ]);
    const csv = [header.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `meridian-erp-account-activity-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  };

  const rowHeightClass = tableDensity === 'compact' ? 'py-2.5' : 'py-3.5';

  return (
    <div className="space-y-8">
      <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
            Account Activity Log
          </h1>
          <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
            Immutable audit trail of profile updates, credential rotations, delegation changes, and preference edits.
          </p>
        </div>

        <button
          type="button"
          onClick={handleExportCsv}
          disabled={filtered.length === 0}
          className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors whitespace-nowrap shrink-0 cursor-pointer self-start sm:self-auto"
        >
          <Download className="w-4 h-4" />
          <span>Export CSV</span>
        </button>
      </div>

      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden">
        {/* Filter Toolbar */}
        <div className="p-4 sm:px-6 border-b border-slate-200 dark:border-slate-800 flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div className="flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 overflow-x-auto">
            {CATEGORIES.map((cat) => (
              <button
                key={cat}
                type="button"
                onClick={() => setCategoryFilter(cat)}
                className={`px-3 py-1.5 text-xs font-medium rounded-md transition-colors whitespace-nowrap shrink-0 cursor-pointer ${
                  categoryFilter === cat
                    ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 shadow-xs'
                    : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100'
                }`}
              >
                {cat}
              </button>
            ))}
          </div>

          <div className="relative w-full md:w-72">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              type="text"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="Filter events or IP..."
              className="w-full pl-9 pr-8 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none focus:border-blue-600"
            />
            {searchTerm && (
              <button
                type="button"
                onClick={() => setSearchTerm('')}
                aria-label="Clear search"
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>
        </div>

        {/* Table or Empty State */}
        {filtered.length === 0 ? (
          <div className="p-10 text-center">
            <p className="text-sm font-medium text-slate-900 dark:text-slate-100">
              No matching audit events
            </p>
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
              Try clearing your search query or selecting a broader category filter.
            </p>
            <button
              type="button"
              onClick={() => {
                setCategoryFilter('All');
                setSearchTerm('');
              }}
              className="mt-3 px-3.5 py-1.5 text-xs font-medium rounded-lg border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer"
            >
              Reset Filters
            </button>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="border-b border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/50 text-xs font-medium text-slate-500 dark:text-slate-400">
                  <th className="py-2.5 pl-6 pr-4">Timestamp</th>
                  <th className="py-2.5 px-4">Category</th>
                  <th className="py-2.5 px-4">Action</th>
                  <th className="py-2.5 px-4">Target</th>
                  <th className="py-2.5 pl-4 pr-6 text-right">IP Address</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 dark:divide-slate-800 text-sm">
                {filtered.map((entry) => (
                  <tr key={entry.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors">
                    <td className={`${rowHeightClass} pl-6 pr-4 font-mono text-xs tabular-nums text-slate-600 dark:text-slate-400 whitespace-nowrap`}>
                      {entry.timestamp}
                    </td>
                    <td className={`${rowHeightClass} px-4 text-xs font-medium text-slate-700 dark:text-slate-300 whitespace-nowrap`}>
                      {entry.category}
                    </td>
                    <td className={`${rowHeightClass} px-4 text-slate-900 dark:text-slate-100`}>
                      {entry.action}
                    </td>
                    <td className={`${rowHeightClass} px-4 text-slate-600 dark:text-slate-400 whitespace-nowrap`}>
                      {entry.target}
                    </td>
                    <td className={`${rowHeightClass} pl-4 pr-6 text-right font-mono text-xs tabular-nums text-slate-500 dark:text-slate-400 whitespace-nowrap`}>
                      {entry.ipAddress}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
