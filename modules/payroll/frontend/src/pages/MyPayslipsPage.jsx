import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Download,
  Eye,
  FileText,
  Inbox,
  Loader2,
  Search,
  Wallet,
  X,
} from 'lucide-react';
import {
  buildPayslipUrl,
  fetchMyPayslipsInit,
  formatAmount,
} from '../api/payrollDesk';

function StatusBadge({ status }) {
  const cls = status === 'paid' ? 'pay-desk-badge--paid' : 'pay-desk-badge--approved';
  const label = status === 'paid' ? 'Paid' : 'Approved';
  return <span className={`pay-desk-badge ${cls}`}>{label}</span>;
}

function matchesSlip(slip, query) {
  const q = String(query || '').trim().toLowerCase();
  if (!q) return true;
  return (
    String(slip.periodLabel || '').toLowerCase().includes(q)
    || String(slip.runDateLabel || '').toLowerCase().includes(q)
    || String(slip.idLabel || '').toLowerCase().includes(q)
    || String(slip.statusLabel || '').toLowerCase().includes(q)
  );
}

function isRowActionTarget(target) {
  if (!(target instanceof Element)) return false;
  return Boolean(target.closest('a, button, input, select, textarea, label, [data-pay-row-ignore]'));
}

export default function MyPayslipsPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [suggestionsOpen, setSuggestionsOpen] = useState(false);
  const [activeSuggestion, setActiveSuggestion] = useState(-1);
  const [previewSlip, setPreviewSlip] = useState(null);
  const [iframeLoading, setIframeLoading] = useState(false);
  const searchWrapRef = useRef(null);

  const loadData = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const data = await fetchMyPayslipsInit();
      setInit(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load payslips.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useEffect(() => {
    const onDown = (event) => {
      if (!searchWrapRef.current?.contains(event.target)) {
        setSuggestionsOpen(false);
        setActiveSuggestion(-1);
      }
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, []);

  useEffect(() => {
    if (!previewSlip) return undefined;
    const onKey = (event) => {
      if (event.key === 'Escape') setPreviewSlip(null);
    };
    document.addEventListener('keydown', onKey);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = previousOverflow;
    };
  }, [previewSlip]);

  const links = init?.links || {};
  const stats = init?.stats || {};
  const allSlips = init?.payslips || [];

  const slips = useMemo(
    () => allSlips.filter((slip) => matchesSlip(slip, search)),
    [allSlips, search],
  );

  const suggestions = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return [];
    return allSlips.filter((slip) => matchesSlip(slip, q)).slice(0, 6);
  }, [allSlips, search]);

  function openPayslipPreview(slip) {
    if (!slip?.id) return;
    setSuggestionsOpen(false);
    setIframeLoading(true);
    setPreviewSlip(slip);
  }

  function closePayslipPreview() {
    setPreviewSlip(null);
    setIframeLoading(false);
  }

  const previewUrl = previewSlip
    ? buildPayslipUrl(previewSlip.id, links, { embed: 1 })
    : '';
  const previewDownloadUrl = previewSlip
    ? buildPayslipUrl(previewSlip.id, links, { download: 1 })
    : '';

  if (loading && !init) {
    return (
      <div className="pay-desk-page pay-desk-boot-loading" role="status" aria-live="polite">
        <Loader2 className="pay-desk-boot-spinner" aria-hidden="true" />
        <span>Loading your payslips...</span>
      </div>
    );
  }

  return (
    <div className="pay-desk-page">
      <div className="pay-desk-page-header pay-desk-page-header--desk">
        <div className="pay-desk-page-header-search" ref={searchWrapRef}>
          <div className={`pay-desk-search-field${suggestionsOpen && search.trim() ? ' has-suggestions' : ''}`}>
            <Search className="pay-desk-search-icon" size={16} aria-hidden="true" />
            <input
              type="search"
              className="pay-desk-search-input"
              placeholder="Search period, date, or status..."
              value={search}
              onChange={(event) => {
                setSearch(event.target.value);
                setSuggestionsOpen(true);
                setActiveSuggestion(0);
              }}
              onFocus={() => {
                if (search.trim().length >= 1) setSuggestionsOpen(true);
              }}
              onKeyDown={(event) => {
                if (!suggestionsOpen || suggestions.length === 0) return;
                if (event.key === 'ArrowDown') {
                  event.preventDefault();
                  setActiveSuggestion((prev) => Math.min(prev + 1, suggestions.length - 1));
                } else if (event.key === 'ArrowUp') {
                  event.preventDefault();
                  setActiveSuggestion((prev) => Math.max(prev - 1, 0));
                } else if (event.key === 'Enter' && activeSuggestion >= 0) {
                  event.preventDefault();
                  const slip = suggestions[activeSuggestion];
                  if (slip) {
                    setSearch(slip.periodLabel || '');
                    openPayslipPreview(slip);
                  }
                } else if (event.key === 'Escape') {
                  setSuggestionsOpen(false);
                }
              }}
              aria-label="Search payslips"
              aria-autocomplete="list"
              aria-expanded={suggestionsOpen}
              aria-controls="pay-my-slips-suggestions"
            />
            {suggestionsOpen && search.trim().length >= 1 && (
              <div
                id="pay-my-slips-suggestions"
                className="pay-desk-suggestions"
                role="listbox"
                aria-label="Payslip suggestions"
              >
                {suggestions.length === 0 ? (
                  <div className="pay-desk-suggestion-empty">No payslips found</div>
                ) : (
                  suggestions.map((slip, index) => {
                    const isActive = index === activeSuggestion;
                    return (
                      <button
                        key={slip.id}
                        type="button"
                        role="option"
                        aria-selected={isActive}
                        className={`pay-desk-suggestion${isActive ? ' is-active' : ''}`}
                        onMouseEnter={() => setActiveSuggestion(index)}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => {
                          setSearch(slip.periodLabel || '');
                          openPayslipPreview(slip);
                        }}
                      >
                        <div className="pay-desk-suggestion-meta">
                          <div className="pay-desk-suggestion-name">{slip.periodLabel}</div>
                          <div className="pay-desk-suggestion-code">
                            {slip.runDateLabel || '—'}
                            {' | '}
                            {slip.statusLabel}
                          </div>
                        </div>
                        <div className="pay-desk-suggestion-price">
                          {formatAmount(slip.netSalary)}
                        </div>
                      </button>
                    );
                  })
                )}
              </div>
            )}
          </div>
        </div>
        <div className="pay-desk-page-header-actions" />
      </div>

      {error && <div className="pay-desk-flash-error" role="alert">{error}</div>}

      <div className="pay-desk-kpi-grid">
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi-icon pay-desk-kpi-icon--indigo">
            <FileText size={18} aria-hidden="true" />
          </div>
          <div className="pay-desk-kpi-body">
            <div className="pay-desk-kpi-label">Published slips</div>
            <div className="pay-desk-kpi-value">{Number(stats.total || 0)}</div>
          </div>
        </div>
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi-icon pay-desk-kpi-icon--teal">
            <Wallet size={18} aria-hidden="true" />
          </div>
          <div className="pay-desk-kpi-body">
            <div className="pay-desk-kpi-label">Paid slips</div>
            <div className="pay-desk-kpi-value">{Number(stats.paid || 0)}</div>
          </div>
        </div>
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi-icon pay-desk-kpi-icon--violet">
            <Wallet size={18} aria-hidden="true" />
          </div>
          <div className="pay-desk-kpi-body">
            <div className="pay-desk-kpi-label">Latest net</div>
            <div className="pay-desk-kpi-value pay-desk-kpi-value--money">{formatAmount(stats.latestNet || 0)}</div>
            {stats.latestPeriod && (
              <div className="pay-desk-kpi-helper">{stats.latestPeriod}</div>
            )}
          </div>
        </div>
      </div>

      <section className="pay-desk-results">
        <div className="pay-desk-results-head">
          <span className="pay-desk-results-count">
            {slips.length} {slips.length === 1 ? 'payslip' : 'payslips'}
          </span>
        </div>

        {slips.length === 0 ? (
          <div className="pay-desk-empty">
            <Inbox className="pay-desk-empty-icon" aria-hidden="true" />
            <p className="pay-desk-empty-title">No payslips found</p>
            <p className="pay-desk-empty-sub">
              {search
                ? 'Try a different search.'
                : 'No published payslips are available in your record yet.'}
            </p>
          </div>
        ) : (
          <div className="pay-desk-table-wrap">
            <table className="pay-desk-table">
              <thead>
                <tr>
                  <th>Period</th>
                  <th className="pay-desk-hide-md">Run date</th>
                  <th className="pay-desk-hide-lg">Basic salary</th>
                  <th>Net pay</th>
                  <th>Status</th>
                  <th style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {slips.map((slip) => (
                  <tr
                    key={slip.id}
                    className="pay-desk-row-clickable"
                    tabIndex={0}
                    onClick={(event) => {
                      if (isRowActionTarget(event.target)) return;
                      openPayslipPreview(slip);
                    }}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        openPayslipPreview(slip);
                      }
                    }}
                  >
                    <td>
                      <div className="pay-desk-cell-main">{slip.periodLabel}</div>
                      <div className="pay-desk-cell-sub">{slip.idLabel}</div>
                    </td>
                    <td className="pay-desk-hide-md">{slip.runDateLabel || '—'}</td>
                    <td className="pay-desk-hide-lg">{formatAmount(slip.basicSalary)}</td>
                    <td className="pay-desk-amt">{formatAmount(slip.netSalary)}</td>
                    <td>
                      <StatusBadge status={slip.status} />
                    </td>
                    <td style={{ textAlign: 'right' }} data-pay-row-ignore>
                      <div className="pay-desk-actions">
                        <button
                          type="button"
                          className="pay-desk-icon-btn"
                          title="View payslip"
                          onClick={() => openPayslipPreview(slip)}
                        >
                          <Eye size={15} aria-hidden="true" />
                        </button>
                        <a
                          href={buildPayslipUrl(slip.id, links, { download: 1 })}
                          className="pay-desk-icon-btn pay-desk-icon-btn--approve"
                          title="Download PDF"
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          <Download size={15} aria-hidden="true" />
                        </a>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <div className="pay-my-slips-note">
        Issues with your payslip? Please contact the Finance or HR department for clarification.
      </div>

      {previewSlip && (
        <div
          className="pay-desk-modal-backdrop pay-slip-preview-backdrop"
          role="presentation"
          onClick={closePayslipPreview}
        >
          <div
            className="pay-desk-modal pay-slip-preview-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="pay-slip-preview-title"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="pay-salary-edit-modal-head">
              <div>
                <h2 id="pay-slip-preview-title" className="pay-salary-edit-modal-title">
                  {previewSlip.periodLabel || 'Payslip'}
                </h2>
                <div className="pay-slip-preview-sub">
                  {previewSlip.idLabel}
                  {previewSlip.statusLabel ? ` · ${previewSlip.statusLabel}` : ''}
                </div>
              </div>
              <div className="pay-slip-preview-actions">
                <a
                  href={previewDownloadUrl}
                  className="pay-desk-btn pay-desk-btn-secondary"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  <Download size={14} aria-hidden="true" />
                  PDF
                </a>
                <button
                  type="button"
                  className="pay-salary-edit-modal-close"
                  onClick={closePayslipPreview}
                  aria-label="Close payslip preview"
                >
                  <X size={18} aria-hidden="true" />
                </button>
              </div>
            </div>
            <div className="pay-slip-preview-body">
              {iframeLoading && (
                <div className="pay-slip-preview-loading" role="status" aria-live="polite">
                  <Loader2 className="pay-desk-boot-spinner" aria-hidden="true" />
                  <span>Loading payslip...</span>
                </div>
              )}
              <iframe
                key={previewSlip.id}
                title={`Payslip ${previewSlip.periodLabel || previewSlip.id}`}
                src={previewUrl}
                className="pay-slip-preview-frame"
                onLoad={() => setIframeLoading(false)}
              />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
