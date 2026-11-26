import { AlertCircle, CheckCircle2 } from 'lucide-react';
import { APPROVAL_LIMITS, DIRECTORY_COLLEAGUES } from '../../data/erpDefaults';
import { DelegationSettings } from '../../types/erpProfile';

interface ApprovalsSectionProps {
  delegation: DelegationSettings;
  onUpdateDelegation: (patch: Partial<DelegationSettings>) => void;
}

export default function ApprovalsSection({
  delegation,
  onUpdateDelegation,
}: ApprovalsSectionProps) {
  const selectedDelegate = DIRECTORY_COLLEAGUES.find((c) => c.id === delegation.delegateId);
  const selectedBackup = DIRECTORY_COLLEAGUES.find((c) => c.id === delegation.escalationBackupId);

  const dateInvalid =
    Boolean(delegation.startDate && delegation.endDate) &&
    delegation.endDate < delegation.startDate;

  const activeScopeNames = [
    delegation.scopes.purchaseOrders && 'Purchase Orders',
    delegation.scopes.vendorOnboarding && 'Vendor Onboarding',
    delegation.scopes.invoiceApprovals && 'Invoice Approvals',
    delegation.scopes.capexRequests && 'CapEx Requests',
  ].filter(Boolean) as string[];

  const toggleScope = (key: keyof DelegationSettings['scopes']) => {
    onUpdateDelegation({
      scopes: {
        ...delegation.scopes,
        [key]: !delegation.scopes[key],
      },
    });
  };

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
          Approvals &amp; Delegation
        </h1>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Review your delegated financial signing thresholds and configure temporary out-of-office routing.
        </p>
      </div>

      {/* Signing Authority Summary Strip */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden">
        <div className="px-6 py-4 border-b border-slate-200 dark:border-slate-800">
          <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
            Assigned Signing Authority
          </h2>
          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            Controlled by Corporate Treasury policy for Procurement Approver L3.
          </p>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-slate-200 dark:divide-slate-800">
          {APPROVAL_LIMITS.map((limit) => (
            <div key={limit.label} className="p-5">
              <div className="text-xs font-medium text-slate-500 dark:text-slate-400">
                {limit.label}
              </div>
              <div className="mt-1.5 text-xl font-semibold font-mono tabular-nums text-slate-900 dark:text-slate-100">
                {limit.value}
              </div>
              <div className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {limit.scope}
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Out-of-Office Delegation */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
          <div className="lg:col-span-4">
            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
              Out-of-Office Delegation
            </h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
              Temporarily route incoming approval queues to an authorized colleague during planned leave or travel.
            </p>

            <div className="mt-5 flex items-center justify-between p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/50">
              <div>
                <div className="text-sm font-medium text-slate-900 dark:text-slate-100">
                  Delegation Active
                </div>
                <div className="text-xs text-slate-500 dark:text-slate-400">
                  {delegation.active ? 'Routing to delegate' : 'Standard queue'}
                </div>
              </div>
              <button
                type="button"
                role="switch"
                aria-checked={delegation.active}
                onClick={() => onUpdateDelegation({ active: !delegation.active })}
                className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-150 ${
                  delegation.active ? 'bg-blue-700 dark:bg-blue-600' : 'bg-slate-300 dark:bg-slate-700'
                }`}
              >
                <span
                  className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-150 ${
                    delegation.active ? 'translate-x-5' : 'translate-x-0'
                  }`}
                />
              </button>
            </div>
          </div>

          <div className="lg:col-span-8 space-y-5">
            <div>
              <label
                htmlFor="delegate-person"
                className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
              >
                Authorized Delegate
              </label>
              <select
                id="delegate-person"
                value={delegation.delegateId}
                onChange={(e) => onUpdateDelegation({ delegateId: e.target.value })}
                className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
              >
                {DIRECTORY_COLLEAGUES.map((col) => (
                  <option key={col.id} value={col.id}>
                    {col.name} — {col.title} ({col.costCenter})
                  </option>
                ))}
              </select>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label
                  htmlFor="del-start"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Effective From
                </label>
                <input
                  id="del-start"
                  type="date"
                  value={delegation.startDate}
                  onChange={(e) => onUpdateDelegation({ startDate: e.target.value })}
                  className="w-full px-3.5 py-2 text-sm font-mono tabular-nums bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                />
              </div>

              <div>
                <label
                  htmlFor="del-end"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Effective Through
                </label>
                <input
                  id="del-end"
                  type="date"
                  value={delegation.endDate}
                  onChange={(e) => onUpdateDelegation({ endDate: e.target.value })}
                  className="w-full px-3.5 py-2 text-sm font-mono tabular-nums bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                />
              </div>
            </div>

            {dateInvalid && (
              <div className="flex items-center gap-2 text-xs font-medium text-red-600 dark:text-red-400">
                <AlertCircle className="w-4 h-4 shrink-0" />
                <span>End date must be on or after the effective start date.</span>
              </div>
            )}

            <div>
              <span className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                Workflows Included in Delegation
              </span>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {[
                  { key: 'purchaseOrders' as const, label: 'Purchase Orders (up to $250k)' },
                  { key: 'invoiceApprovals' as const, label: 'Invoice Variance Approvals' },
                  { key: 'vendorOnboarding' as const, label: 'Vendor Onboarding & Bank Changes' },
                  { key: 'capexRequests' as const, label: 'Capital Expenditure Requisitions' },
                ].map((item) => (
                  <label
                    key={item.key}
                    className="flex items-center gap-2.5 p-3 rounded-lg border border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/40 cursor-pointer"
                  >
                    <input
                      type="checkbox"
                      checked={delegation.scopes[item.key]}
                      onChange={() => toggleScope(item.key)}
                      className="h-4 w-4 rounded border-slate-300 text-blue-700 focus:ring-blue-600"
                    />
                    <span className="text-sm text-slate-800 dark:text-slate-200">{item.label}</span>
                  </label>
                ))}
              </div>
            </div>

            <div>
              <label
                htmlFor="handoff-note"
                className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
              >
                Routing &amp; Handoff Instructions
              </label>
              <textarea
                id="handoff-note"
                rows={2}
                value={delegation.handoffNote}
                onChange={(e) => onUpdateDelegation({ handoffNote: e.target.value })}
                className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
              />
            </div>

            {/* Live Routing Summary */}
            <div className="p-4 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/60 text-xs text-slate-600 dark:text-slate-300 flex items-start gap-2.5">
              <CheckCircle2
                className={`w-4 h-4 shrink-0 mt-0.5 ${
                  delegation.active ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-400'
                }`}
              />
              <div>
                {delegation.active ? (
                  <>
                    <span className="font-medium text-slate-900 dark:text-slate-100">
                      Active routing preview:
                    </span>{' '}
                    From <span className="font-mono tabular-nums">{delegation.startDate || '—'}</span> to{' '}
                    <span className="font-mono tabular-nums">{delegation.endDate || '—'}</span>,{' '}
                    {activeScopeNames.length > 0 ? activeScopeNames.join(', ') : 'no selected workflows'}{' '}
                    will route to{' '}
                    <span className="font-medium text-slate-900 dark:text-slate-100">
                      {selectedDelegate?.name || 'selected delegate'}
                    </span>
                    .
                  </>
                ) : (
                  <>
                    Delegation is currently paused. When enabled,{' '}
                    {activeScopeNames.length > 0 ? activeScopeNames.join(', ') : 'selected workflows'}{' '}
                    will route to{' '}
                    <span className="font-medium text-slate-900 dark:text-slate-100">
                      {selectedDelegate?.name}
                    </span>
                    .
                  </>
                )}
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Escalation Policy */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
          <div className="lg:col-span-4">
            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
              Automatic SLA Escalation
            </h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
              Prevent procurement bottlenecks by automatically escalating unactioned requisitions after a set window.
            </p>
          </div>

          <div className="lg:col-span-8 grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label
                htmlFor="esc-backup"
                className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
              >
                Escalation Fallback Approver
              </label>
              <select
                id="esc-backup"
                value={delegation.escalationBackupId}
                onChange={(e) => onUpdateDelegation({ escalationBackupId: e.target.value })}
                className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
              >
                {DIRECTORY_COLLEAGUES.map((col) => (
                  <option key={col.id} value={col.id}>
                    {col.name} — {col.title}
                  </option>
                ))}
              </select>
              {selectedBackup && (
                <p className="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                  Cost Center {selectedBackup.costCenter} · {selectedBackup.department}
                </p>
              )}
            </div>

            <div>
              <span className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">
                Escalation Window
              </span>
              <div className="inline-flex p-1 bg-slate-100 dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700">
                {(['24', '48', '72'] as const).map((hrs) => (
                  <button
                    key={hrs}
                    type="button"
                    onClick={() => onUpdateDelegation({ escalationSlaHours: hrs })}
                    className={`px-3.5 py-1.5 text-xs font-medium font-mono tabular-nums rounded-md transition-colors whitespace-nowrap shrink-0 cursor-pointer ${
                      delegation.escalationSlaHours === hrs
                        ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 shadow-xs'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100'
                    }`}
                  >
                    {hrs} hours
                  </button>
                ))}
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
