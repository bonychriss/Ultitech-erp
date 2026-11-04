import { useEffect, useState } from 'react';
import { ArrowLeft, Loader2, Sparkles } from 'lucide-react';
import { fetchKpiAiConfirmation } from '../api/revenueDesk';
import { formatDateDigits, formatVoucherRef } from '../utils/formatDisplay';
import { formatKpiHeadline } from '../utils/kpiTrace';
import '../tailwind.css';
import '../revenue-kpi-page.css';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from './ui/table';

function formatCurrency(value) {
  const amount = Number(value) || 0;
  return `TZS ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function cellText(value) {
  const text = String(value ?? '').trim();
  return text || '-';
}

function formatDaysRemaining(value) {
  if (value == null || Number.isNaN(Number(value))) return '-';
  const days = Number(value);
  if (days < 0) return `Overdue ${Math.abs(days)}`;
  if (days === 0) return 'Due today';
  return `${days}`;
}

export default function RevenueKpiTracePage({ trace, traceKey, onBack }) {
  const items = Array.isArray(trace.items) ? trace.items : [];
  const [confirmation, setConfirmation] = useState(trace.confirmation || '');
  const [viaAi, setViaAi] = useState(Boolean(trace.viaAi));
  const [aiLoading, setAiLoading] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setConfirmation(trace.confirmation || '');
    setViaAi(Boolean(trace.viaAi));
    setAiLoading(true);
    fetchKpiAiConfirmation(traceKey, { trace })
      .then((result) => {
        if (cancelled) return;
        setConfirmation(result.confirmation || trace.confirmation || '');
        setViaAi(Boolean(result.viaAi));
      })
      .catch(() => {})
      .finally(() => {
        if (!cancelled) setAiLoading(false);
      });
    return () => { cancelled = true; };
  }, [traceKey, trace]);

  useEffect(() => {
    function onKey(event) {
      if (event.key === 'Escape') onBack();
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onBack]);

  const total = items.reduce((sum, item) => sum + (Number(item.contribution) || 0), 0);
  const showOverdue = traceKey === 'overdueInvoices';
  const showRemaining = traceKey === 'outstandingInvoices';
  const start = 0;
  const rows = items;

  return (
    <div className="rev-kpis">
      <button type="button" className="rev-kpis-back" onClick={onBack}>
        <ArrowLeft size={16} aria-hidden="true" />
        Back to revenues
      </button>

      <div className="rev-kpis-card">
        <div className="rev-kpis-head">
          <div>
            <h2 className="rev-kpis-title">{trace.title}</h2>
            <p className="rev-kpis-sub">{formatKpiHeadline(trace)}</p>
          </div>
          <div className="rev-kpis-total">
            <span>Total</span>
            <strong>{formatCurrency(total)}</strong>
          </div>
        </div>

        <div className="rev-kpis-confirm">
          <span className="rev-kpis-confirm-label">
            <Sparkles size={14} aria-hidden="true" />
            AI insight
            {aiLoading ? <Loader2 size={12} className="rev-kpis-spin" aria-hidden="true" /> : null}
            {!aiLoading && viaAi ? <span className="rev-kpis-badge">AI verified</span> : null}
          </span>
                      <p>{confirmation || 'Generating insight...'}</p>
        </div>

        {items.length === 0 ? (
          <p className="rev-kpis-empty">No records contributed to this KPI.</p>
        ) : (
          <>
            <ul className="rev-kpis-cards">
              {rows.map((item, index) => {
                const statusClass = String(item.status || '').toLowerCase().replace(/[^a-z]+/g, '-');
                return (
                  <li key={`card-${item.id}-${item.voucherNumber}-${item.date}`} className="rev-kpis-item">
                    <div className="rev-kpis-item-top">
                      <span className={`rev-kpis-item-no rev-kpis-item-no--${(start + index) % 6}`}>{start + index + 1}</span>
                      <div className="rev-kpis-item-main">
                        <strong className="rev-kpis-item-voucher">{formatVoucherRef(item.voucherNumber, cellText(item.voucherNumber))}</strong>
                        <span className="rev-kpis-item-customer">{cellText(item.customer)}</span>
                      </div>
                      <div className="rev-kpis-item-side">
                        <strong className="rev-kpis-item-amt">{formatCurrency(item.contribution)}</strong>
                        <span className={`rev-kpis-status rev-kpis-status--${statusClass}`}>{cellText(item.status)}</span>
                      </div>
                    </div>
                    <div className="rev-kpis-item-meta">
                      <span><em>Date</em>{item.date ? formatDateDigits(item.date) : '-'}</span>
                      <span><em>Type</em>{cellText(item.type)}</span>
                      {showOverdue ? <span><em>Days overdue</em>{item.daysOverdue != null ? item.daysOverdue : '-'}</span> : null}
                      {showRemaining ? <span><em>Days left</em>{formatDaysRemaining(item.daysRemaining)}</span> : null}
                    </div>
                  </li>
                );
              })}
            </ul>

            <div className="rev-kpis-table-wrap">
              <Table className="rev-kpis-table">
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-10 text-muted-foreground">#</TableHead>
                    <TableHead>Voucher</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Status</TableHead>
                    {showOverdue ? <TableHead>Days Overdue</TableHead> : null}
                    {showRemaining ? <TableHead>Days Remaining</TableHead> : null}
                    <TableHead className="text-right">Amount</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((item, index) => (
                    <TableRow key={`${item.id}-${item.voucherNumber}-${item.date}`}>
                      <TableCell className="w-10 text-muted-foreground">{start + index + 1}</TableCell>
                      <TableCell className="font-medium">{formatVoucherRef(item.voucherNumber, cellText(item.voucherNumber))}</TableCell>
                      <TableCell>{item.date ? formatDateDigits(item.date) : '-'}</TableCell>
                      <TableCell>{cellText(item.customer)}</TableCell>
                      <TableCell>{cellText(item.type)}</TableCell>
                      <TableCell>
                        <span className={`rev-kpis-status rev-kpis-status--${String(item.status || '').toLowerCase().replace(/[^a-z]+/g, '-')}`}>
                          {cellText(item.status)}
                        </span>
                      </TableCell>
                      {showOverdue ? <TableCell>{item.daysOverdue != null ? item.daysOverdue : '-'}</TableCell> : null}
                      {showRemaining ? <TableCell>{formatDaysRemaining(item.daysRemaining)}</TableCell> : null}
                      <TableCell className="text-right font-medium">{formatCurrency(item.contribution)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
                <TableFooter>
                  <TableRow>
                    <TableCell colSpan={6 + (showOverdue ? 1 : 0) + (showRemaining ? 1 : 0) + 1} className="rev-kpis-count">
                      {items.length} {items.length === 1 ? 'record' : 'records'}
                    </TableCell>
                  </TableRow>
                </TableFooter>
              </Table>
            </div>

            
          </>
        )}

        {trace.footnote ? <p className="rev-kpis-footnote">{trace.footnote}</p> : null}
      </div>
    </div>
  );
}
