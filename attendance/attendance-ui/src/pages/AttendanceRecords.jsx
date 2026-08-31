import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import '../attendance-records.css';

function formatDate(value) {
  if (!value) return '-';
  const d = new Date(`${value}T12:00:00`);
  if (Number.isNaN(d.getTime())) return value;
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return `${dd}/${mm}/${d.getFullYear()}`;
}

function formatTime(value) {
  if (!value) return null;
  const str = String(value).trim();
  const timePart = str.includes(' ') ? str.split(' ').pop() : str.includes('T') ? str.split('T').pop() : str;
  const parts = String(timePart).split(':');
  if (parts.length >= 2) return `${parts[0].padStart(2, '0')}:${parts[1].padStart(2, '0')}`;
  return str;
}

function statusClass(status) {
  const s = String(status || '').toLowerCase();
  if (s.includes('late')) return 'att-desk-status--late';
  if (s.includes('early')) return 'att-desk-status--early';
  return 'att-desk-status--on-time';
}

function exportHref(base, type, userId) {
  if (!base) return '#';
  const sep = base.includes('?') ? '&' : '?';
  return `${base}${sep}type=${encodeURIComponent(type)}&user_id=${encodeURIComponent(userId || 0)}`;
}

function hasActiveFilters(date, userId) {
  return Number(userId || 0) > 0 || String(date || '').trim() !== '';
}

export default function AttendanceRecords({ data }) {
  const initial = data || {};
  const [date, setDate] = useState(() => String(initial.filters?.date ?? ''));
  const [userId, setUserId] = useState(Number(initial.filters?.user_id || 0));
  const [draftDate, setDraftDate] = useState(() => String(initial.filters?.date ?? ''));
  const [draftUserId, setDraftUserId] = useState(Number(initial.filters?.user_id || 0));
  const [records, setRecords] = useState(initial.records || []);
  const [users, setUsers] = useState(initial.users || []);
  const [stats, setStats] = useState(initial.stats || {});
  const [links, setLinks] = useState(initial.links || {});
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [dlOpen, setDlOpen] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [filterPanelStyle, setFilterPanelStyle] = useState(null);
  const [sigUrl, setSigUrl] = useState('');
  const dlWrapRef = useRef(null);
  const filterBtnRef = useRef(null);
  const filterPanelRef = useRef(null);

  const moduleKey = initial.filters?.module || 'attendance';
  const apiUrl = links.api || initial.links?.api || '';
  const filtersActive = useMemo(() => hasActiveFilters(date, userId), [date, userId]);

  useEffect(() => {
    function onDocClick(e) {
      if (dlWrapRef.current?.contains(e.target)) return;
      setDlOpen(false);
      if (filterBtnRef.current?.contains(e.target)) return;
      if (filterPanelRef.current?.contains(e.target)) return;
      setFiltersOpen(false);
    }
    function onKey(e) {
      if (e.key === 'Escape') {
        setFiltersOpen(false);
        setDlOpen(false);
      }
    }
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDocClick);
      document.removeEventListener('keydown', onKey);
    };
  }, []);

  useLayoutEffect(() => {
    if (!filtersOpen) {
      setFilterPanelStyle(null);
      return undefined;
    }
    function syncPosition() {
      const btn = filterBtnRef.current;
      if (!btn) return;
      const margin = 12;
      const rect = btn.getBoundingClientRect();
      const top = Math.round(rect.bottom + 6);
      const isMobile = window.matchMedia('(max-width: 767.98px)').matches;
      const maxHeight = Math.max(220, window.innerHeight - top - margin);
      if (isMobile) {
        setFilterPanelStyle({
          top: `${top}px`,
          left: `${margin}px`,
          right: `${margin}px`,
          width: 'auto',
          maxHeight: `${maxHeight}px`,
        });
        return;
      }
      const panelWidth = Math.min(360, window.innerWidth - margin * 2);
      let left = rect.right - panelWidth;
      left = Math.max(margin, Math.min(left, window.innerWidth - panelWidth - margin));
      setFilterPanelStyle({
        top: `${top}px`,
        left: `${left}px`,
        width: `${panelWidth}px`,
        maxHeight: `${maxHeight}px`,
      });
    }
    syncPosition();
    window.addEventListener('resize', syncPosition);
    window.addEventListener('scroll', syncPosition, true);
    return () => {
      window.removeEventListener('resize', syncPosition);
      window.removeEventListener('scroll', syncPosition, true);
    };
  }, [filtersOpen]);

  async function load(nextDate, nextUserId) {
    if (!apiUrl) return;
    setLoading(true);
    setError('');
    try {
      const qs = new URLSearchParams();
      qs.set('module', moduleKey);
      qs.set('date', nextDate || '');
      qs.set('user_id', String(nextUserId || 0));
      const res = await fetch(`${apiUrl}?${qs.toString()}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await res.json();
      if (!res.ok || !json.success) {
        throw new Error(json.message || `Request failed (${res.status})`);
      }
      const payload = json.data || {};
      setRecords(payload.records || []);
      setUsers(payload.users || []);
      setStats(payload.stats || {});
      setLinks(payload.links || links);
      const appliedDate = String(payload.filters?.date ?? nextDate ?? '');
      const appliedUser = Number(payload.filters?.user_id || 0);
      setDate(appliedDate);
      setUserId(appliedUser);
      setDraftDate(appliedDate);
      setDraftUserId(appliedUser);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load records.');
    } finally {
      setLoading(false);
    }
  }

  function openFilters() {
    setDraftDate(String(date || ''));
    setDraftUserId(userId);
    setFiltersOpen(true);
    setDlOpen(false);
  }

  function toggleFilters() {
    if (filtersOpen) {
      setFiltersOpen(false);
      return;
    }
    openFilters();
  }

  function applyFilters(e) {
    e?.preventDefault?.();
    setFiltersOpen(false);
    load(draftDate, draftUserId);
  }

  function clearDraftFilters() {
    setDraftDate('');
    setDraftUserId(0);
  }

  function resetAndClose() {
    setFiltersOpen(false);
    setDraftDate('');
    setDraftUserId(0);
    load('', 0);
  }

  const filterPanel =
    filtersOpen && filterPanelStyle
      ? createPortal(
          <div
            ref={filterPanelRef}
            className="att-desk-filters-panel"
            style={filterPanelStyle}
            role="dialog"
            aria-label="Filter options"
          >
            <div className="att-desk-filters-head">
              <div>
                <h2 className="att-desk-filters-title">Filters</h2>
                <p className="att-desk-filters-sub">Narrow records by date and employee.</p>
              </div>
              <button
                type="button"
                className="att-desk-filters-close"
                onClick={() => setFiltersOpen(false)}
                aria-label="Close filters"
              >
                <i className="fas fa-times" aria-hidden="true" />
              </button>
            </div>
            <form onSubmit={applyFilters}>
              <div className="att-desk-filters-body">
                <div className="att-desk-filters-section">
                  <div className="att-desk-filters-section-label">Date</div>
                  <div className="att-desk-field att-desk-field--full">
                    <label htmlFor="attDeskDate">Day (blank = all)</label>
                    <input
                      id="attDeskDate"
                      type="date"
                      value={draftDate}
                      onChange={(e) => setDraftDate(e.target.value)}
                    />
                  </div>
                  <button
                    type="button"
                    className="att-desk-btn att-desk-btn-secondary"
                    style={{ marginTop: '0.55rem' }}
                    onClick={() => setDraftDate('')}
                  >
                    View all dates
                  </button>
                </div>
                <div className="att-desk-filters-section">
                  <div className="att-desk-filters-section-label">Employee</div>
                  <div className="att-desk-field att-desk-field--full">
                    <label htmlFor="attDeskUser">Person</label>
                    <select
                      id="attDeskUser"
                      value={draftUserId}
                      onChange={(e) => setDraftUserId(Number(e.target.value))}
                    >
                      <option value={0}>All employees</option>
                      {users.map((u) => (
                        <option key={u.id} value={u.id}>
                          {u.full_name} ({u.username})
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
              </div>
              <div className="att-desk-filters-footer">
                <button type="button" className="att-desk-btn att-desk-btn-secondary" onClick={clearDraftFilters}>
                  Clear
                </button>
                <button type="submit" className="att-desk-btn att-desk-btn-primary" disabled={loading}>
                  Apply filters
                </button>
              </div>
            </form>
          </div>,
          document.body
        )
      : null;

  return (
    <div className="att-desk-page">
      {filterPanel}

      <div className="att-desk-page-header">
        <div className="att-desk-toolbar-actions">
          <button
            ref={filterBtnRef}
            type="button"
            className={`att-desk-filter-btn${filtersOpen ? ' is-active' : ''}`}
            onClick={toggleFilters}
            aria-expanded={filtersOpen}
            aria-haspopup="dialog"
            title="Filters"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <line x1="4" y1="21" x2="4" y2="14" />
              <line x1="4" y1="10" x2="4" y2="3" />
              <line x1="12" y1="21" x2="12" y2="12" />
              <line x1="12" y1="8" x2="12" y2="3" />
              <line x1="20" y1="21" x2="20" y2="16" />
              <line x1="20" y1="12" x2="20" y2="3" />
              <line x1="1" y1="14" x2="7" y2="14" />
              <line x1="9" y1="8" x2="15" y2="8" />
              <line x1="17" y1="16" x2="23" y2="16" />
            </svg>
            {filtersActive ? <span className="att-desk-filter-dot" aria-hidden="true" /> : null}
          </button>

          {filtersActive ? (
            <button type="button" className="att-desk-btn att-desk-btn-secondary" onClick={resetAndClose} disabled={loading}>
              Clear filters
            </button>
          ) : null}

          <div className="att-desk-dl-wrap" ref={dlWrapRef}>
            <button
              type="button"
              className="att-desk-btn att-desk-btn-secondary"
              aria-expanded={dlOpen}
              aria-haspopup="true"
              onClick={() => {
                setDlOpen((v) => !v);
                setFiltersOpen(false);
              }}
            >
              <i className="fas fa-download" aria-hidden="true" /> Export
            </button>
            <div className={`att-desk-dl-menu${dlOpen ? ' is-open' : ''}`} role="menu">
              <a href={exportHref(links.export, 'weekly', userId)} role="menuitem">
                Weekly report
              </a>
              <a href={exportHref(links.export, 'monthly', userId)} role="menuitem">
                Monthly report
              </a>
            </div>
          </div>
        </div>
      </div>

      <section className="att-desk-kpi-grid" aria-label="Summary">
        <div className="att-desk-kpi-card">
          <div className="att-desk-kpi-icon att-desk-kpi-icon--green" aria-hidden="true">
            <i className="fas fa-user-check" />
          </div>
          <div className="att-desk-kpi-body">
            <div className="att-desk-kpi-label">Present</div>
            <div className="att-desk-kpi-value">{Number(stats.total_present || 0)}</div>
          </div>
        </div>
        <div className="att-desk-kpi-card">
          <div className="att-desk-kpi-icon att-desk-kpi-icon--cyan" aria-hidden="true">
            <i className="fas fa-clock" />
          </div>
          <div className="att-desk-kpi-body">
            <div className="att-desk-kpi-label">Early</div>
            <div className="att-desk-kpi-value">{Number(stats.total_early || 0)}</div>
          </div>
        </div>
        <div className="att-desk-kpi-card">
          <div className="att-desk-kpi-icon att-desk-kpi-icon--amber" aria-hidden="true">
            <i className="fas fa-exclamation-circle" />
          </div>
          <div className="att-desk-kpi-body">
            <div className="att-desk-kpi-label">Late</div>
            <div className="att-desk-kpi-value">{Number(stats.total_late || 0)}</div>
          </div>
        </div>
        <div className="att-desk-kpi-card">
          <div className="att-desk-kpi-icon att-desk-kpi-icon--indigo" aria-hidden="true">
            <i className="fas fa-business-time" />
          </div>
          <div className="att-desk-kpi-body">
            <div className="att-desk-kpi-label">Overtime</div>
            <div className="att-desk-kpi-value">{Number(stats.total_overtime || 0)}</div>
          </div>
        </div>
        <div className="att-desk-kpi-card">
          <div className="att-desk-kpi-icon att-desk-kpi-icon--rose" aria-hidden="true">
            <i className="fas fa-user-times" />
          </div>
          <div className="att-desk-kpi-body">
            <div className="att-desk-kpi-label">Absent</div>
            <div className="att-desk-kpi-value">{Number(stats.not_arrived || 0)}</div>
          </div>
        </div>
      </section>

      {error ? <div className="att-desk-error">{error}</div> : null}

      <section className="att-desk-results">
        <div className="att-desk-results-head">
          <span className="att-desk-results-count">
            {loading ? 'Loading...' : `${records.length} record${records.length === 1 ? '' : 's'}`}
          </span>
        </div>
        <div className="att-desk-table-wrap">
          {loading ? (
            <div className="att-desk-loading">
              <i className="fas fa-spinner att-desk-spin" aria-hidden="true" />
              Loading records...
            </div>
          ) : (
            <table className="att-desk-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Employee</th>
                  <th>Department</th>
                  <th>Time In</th>
                  <th>Time Out</th>
                  <th>Status</th>
                  <th>Total Hrs</th>
                  <th>Overtime</th>
                  <th>IP Address</th>
                  <th>Signature</th>
                </tr>
              </thead>
              <tbody>
                {records.length === 0 ? (
                  <tr>
                    <td colSpan={10}>
                      <div className="att-desk-empty">
                        <div className="att-desk-empty-icon" aria-hidden="true">
                          <i className="fas fa-clipboard" />
                        </div>
                        <p className="att-desk-empty-title">No attendance records found</p>
                        <p className="att-desk-empty-sub">Try another date or employee filter.</p>
                      </div>
                    </td>
                  </tr>
                ) : (
                  records.map((row) => {
                    const timeIn = formatTime(row.time_in);
                    const timeOut = formatTime(row.time_out);
                    return (
                      <tr key={row.id || `${row.user_id}-${row.date}-${row.time_in}`}>
                        <td>{formatDate(row.date)}</td>
                        <td>
                          <div className="att-desk-emp-name">{row.full_name || '-'}</div>
                          <div className="att-desk-emp-user">{row.username || ''}</div>
                        </td>
                        <td>{row.department || '-'}</td>
                        <td>{timeIn || '-'}</td>
                        <td>{timeOut || <span className="att-desk-muted">--:--</span>}</td>
                        <td>
                          <span className={`att-desk-status ${statusClass(row.status)}`}>
                            {row.status || '-'}
                          </span>
                        </td>
                        <td>{row.total_hours != null ? row.total_hours : '-'}</td>
                        <td>
                          {row.overtime_hours != null && Number(row.overtime_hours) > 0
                            ? `+${row.overtime_hours}`
                            : '-'}
                        </td>
                        <td>{row.ip_address || '-'}</td>
                        <td>
                          {row.signature_url ? (
                            <button
                              type="button"
                              className="att-desk-btn"
                              style={{ padding: 0, border: 'none', background: 'transparent' }}
                              onClick={() => setSigUrl(row.signature_url)}
                              title="View signature"
                            >
                              <img src={row.signature_url} alt="Signature" className="att-desk-sig" />
                            </button>
                          ) : (
                            <span className="att-desk-muted">-</span>
                          )}
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          )}
        </div>
      </section>

      {sigUrl ? (
        <div
          className="att-desk-sig-modal"
          role="dialog"
          aria-modal="true"
          aria-label="Signature"
          onClick={() => setSigUrl('')}
        >
          <div className="att-desk-sig-modal-card" onClick={(e) => e.stopPropagation()}>
            <h3 className="att-desk-sig-modal-title">Signature</h3>
            <img src={sigUrl} alt="Signature" />
            <div className="att-desk-sig-modal-actions">
              <button type="button" className="att-desk-btn att-desk-btn-secondary" onClick={() => setSigUrl('')}>
                Close
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
