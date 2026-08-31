import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Inbox,
  Loader2,
  Plus,
  Search,
  SlidersHorizontal,
  Users,
  Wallet,
  CircleAlert,
  ListFilter,
  X,
} from 'lucide-react';
import {
  deskPageUrl,
  fetchSalariesInit,
  formatAmount,
} from '../api/payrollDesk';
import EmployeeAvatar from '../components/EmployeeAvatar.jsx';
import SalaryEditModal from '../components/SalaryEditModal.jsx';
import editIcon from '../assets/edit-icon.png';

function readEditIdFromUrl() {
  if (typeof window === 'undefined') return 0;
  const params = new URLSearchParams(window.location.search);
  return Number(params.get('edit') || params.get('id') || 0) || 0;
}

function clearEditIdFromUrl() {
  if (typeof window === 'undefined') return;
  const url = new URL(window.location.href);
  if (!url.searchParams.has('edit') && !url.searchParams.has('id')) return;
  url.searchParams.delete('edit');
  url.searchParams.delete('id');
  if (!url.searchParams.has('module')) {
    url.searchParams.set('module', 'payroll');
  }
  window.history.replaceState({}, '', `${url.pathname}?${url.searchParams.toString()}`);
}

function setEditIdInUrl(employeeId) {
  if (typeof window === 'undefined') return;
  const url = new URL(window.location.href);
  url.searchParams.set('module', 'payroll');
  url.searchParams.set('edit', String(employeeId));
  url.searchParams.delete('id');
  window.history.replaceState({}, '', `${url.pathname}?${url.searchParams.toString()}`);
}

function matchesEmployee(emp, query) {
  const q = String(query || '').trim().toLowerCase();
  if (!q) return true;
  return (
    String(emp.fullName || '').toLowerCase().includes(q)
    || String(emp.email || '').toLowerCase().includes(q)
    || String(emp.department || '').toLowerCase().includes(q)
    || String(emp.bankName || '').toLowerCase().includes(q)
  );
}

export default function SalariesDeskPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [department, setDepartment] = useState('');
  const [salaryStatus, setSalaryStatus] = useState('');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [suggestionsOpen, setSuggestionsOpen] = useState(false);
  const [activeSuggestion, setActiveSuggestion] = useState(-1);
  const [editingEmployeeId, setEditingEmployeeId] = useState(() => readEditIdFromUrl());
  const [notice, setNotice] = useState('');
  const [glowEmployeeId, setGlowEmployeeId] = useState(0);
  const filterWrapRef = useRef(null);
  const searchWrapRef = useRef(null);
  const glowRowRef = useRef(null);

  const loadData = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const data = await fetchSalariesInit();
      setInit(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load salaries.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useEffect(() => {
    if (!filtersOpen) return undefined;
    const onDown = (event) => {
      if (!filterWrapRef.current?.contains(event.target)) setFiltersOpen(false);
    };
    const onKey = (event) => {
      if (event.key === 'Escape') setFiltersOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [filtersOpen]);

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

  function openEditor(employeeId) {
    const id = Number(employeeId) || 0;
    if (id <= 0) return;
    setSuggestionsOpen(false);
    setEditingEmployeeId(id);
    setEditIdInUrl(id);
  }

  function closeEditor() {
    setEditingEmployeeId(0);
    clearEditIdFromUrl();
  }

  async function handleSaved(result) {
    const savedId = Number(result?.employeeId || result?.data?.employee?.id || editingEmployeeId) || 0;
    setNotice(result?.message || 'Salary details updated.');
    closeEditor();
    await loadData();
    if (savedId > 0) {
      setGlowEmployeeId(savedId);
    }
  }

  useEffect(() => {
    if (!notice) return undefined;
    const timer = window.setTimeout(() => setNotice(''), 4500);
    return () => window.clearTimeout(timer);
  }, [notice]);

  useEffect(() => {
    if (!glowEmployeeId) return undefined;

    const row = glowRowRef.current;
    if (row) {
      row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    const clearGlow = () => setGlowEmployeeId(0);
    const timer = window.setTimeout(clearGlow, 6000);
    document.addEventListener('click', clearGlow, { once: true });

    return () => {
      window.clearTimeout(timer);
      document.removeEventListener('click', clearGlow);
    };
  }, [glowEmployeeId, init]);

  const links = init?.links || {};
  const stats = init?.stats || {};
  const allEmployees = init?.employees || [];

  const departments = useMemo(() => {
    const set = new Set();
    allEmployees.forEach((emp) => {
      const dept = String(emp.department || '').trim();
      if (dept) set.add(dept);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
  }, [allEmployees]);

  const filtersActive = Boolean(department || salaryStatus);

  const employees = useMemo(() => {
    return allEmployees.filter((emp) => {
      if (!matchesEmployee(emp, search)) return false;
      if (department && String(emp.department || '').toLowerCase() !== department.toLowerCase()) {
        return false;
      }
      if (salaryStatus === 'configured' && !emp.hasSalary) return false;
      if (salaryStatus === 'missing' && emp.hasSalary) return false;
      return true;
    });
  }, [allEmployees, search, department, salaryStatus]);

  const suggestions = useMemo(() => {
    const q = search.trim();
    if (q.length < 1) return [];
    return allEmployees.filter((emp) => matchesEmployee(emp, q)).slice(0, 8);
  }, [allEmployees, search]);

  function clearFilters() {
    setDepartment('');
    setSalaryStatus('');
    setFiltersOpen(false);
  }

  function onSearchKeyDown(event) {
    if (!suggestionsOpen || suggestions.length === 0) {
      if (event.key === 'Escape') setSuggestionsOpen(false);
      return;
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActiveSuggestion((i) => (i + 1) % suggestions.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActiveSuggestion((i) => (i <= 0 ? suggestions.length - 1 : i - 1));
    } else if (event.key === 'Enter' && activeSuggestion >= 0 && suggestions[activeSuggestion]) {
      event.preventDefault();
      openEditor(suggestions[activeSuggestion].id);
    } else if (event.key === 'Escape') {
      setSuggestionsOpen(false);
      setActiveSuggestion(-1);
    }
  }

  if (loading && !init) {
    return (
      <div className="pay-desk-page pay-desk-boot-loading" role="status" aria-live="polite">
        <Loader2 className="pay-desk-boot-spinner" aria-hidden="true" />
        <span>Loading salaries...</span>
      </div>
    );
  }

  return (
    <div className="pay-desk-page">
      <div className="pay-desk-page-header pay-desk-page-header--desk">
        <div className="pay-desk-page-header-search" ref={searchWrapRef}>
          <div className={`pay-desk-search-field${suggestionsOpen && search.trim() ? ' has-suggestions' : ''}`}>
            <Search className="pay-desk-search-icon" aria-hidden="true" size={16} />
            <input
              type="search"
              className="pay-desk-search-input"
              placeholder="Search employees by name, email, or department..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setSuggestionsOpen(true);
                setActiveSuggestion(0);
              }}
              onFocus={() => {
                if (search.trim().length >= 1) setSuggestionsOpen(true);
              }}
              onKeyDown={onSearchKeyDown}
              aria-label="Search employees"
              aria-autocomplete="list"
              aria-expanded={suggestionsOpen}
              aria-controls="pay-desk-search-suggestions"
            />
            {suggestionsOpen && search.trim().length >= 1 && (
              <div
                id="pay-desk-search-suggestions"
                className="pay-desk-suggestions"
                role="listbox"
                aria-label="Employee suggestions"
              >
                {suggestions.length === 0 ? (
                  <div className="pay-desk-suggestion-empty">No employees found</div>
                ) : (
                  suggestions.map((emp, index) => {
                    const isActive = index === activeSuggestion;
                    return (
                      <button
                        key={emp.id}
                        type="button"
                        role="option"
                        aria-selected={isActive}
                        className={`pay-desk-suggestion${isActive ? ' is-active' : ''}`}
                        onMouseEnter={() => setActiveSuggestion(index)}
                        onClick={() => openEditor(emp.id)}
                      >
                        <EmployeeAvatar name={emp.fullName} id={emp.id} />
                        <div className="pay-desk-suggestion-meta">
                          <div className="pay-desk-suggestion-name">{emp.fullName}</div>
                          <div className="pay-desk-suggestion-code">
                            {String(emp.department || 'N/A').toUpperCase()}
                            {emp.email ? ` | ${emp.email}` : ''}
                          </div>
                        </div>
                        <div className="pay-desk-suggestion-price">
                          {formatAmount(emp.grossPay)}
                          <span> TZS</span>
                        </div>
                      </button>
                    );
                  })
                )}
              </div>
            )}
          </div>
        </div>

        <div className="pay-desk-page-header-actions">
          <div className="pay-desk-filter-wrap" ref={filterWrapRef}>
            <button
              type="button"
              className={`pay-desk-filter-btn${filtersOpen ? ' is-active' : ''}`}
              onClick={() => setFiltersOpen((v) => !v)}
              aria-expanded={filtersOpen}
              title="Filters"
            >
              <SlidersHorizontal size={18} aria-hidden="true" />
              {filtersActive && <span className="pay-desk-filter-dot" aria-hidden="true" />}
            </button>
            {filtersOpen && (
              <div className="pay-desk-filter-panel" role="dialog" aria-label="Salary filters">
                <div className="pay-desk-filter-grid">
                  <div>
                    <label htmlFor="pay-filter-department">Department</label>
                    <select
                      id="pay-filter-department"
                      value={department}
                      onChange={(e) => setDepartment(e.target.value)}
                    >
                      <option value="">All</option>
                      {departments.map((dept) => (
                        <option key={dept} value={dept}>{dept}</option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label htmlFor="pay-filter-salary">Salary status</label>
                    <select
                      id="pay-filter-salary"
                      value={salaryStatus}
                      onChange={(e) => setSalaryStatus(e.target.value)}
                    >
                      <option value="">All</option>
                      <option value="configured">Configured</option>
                      <option value="missing">Missing salary</option>
                    </select>
                  </div>
                </div>
                <div className="pay-desk-filter-actions">
                  <button type="button" className="pay-desk-btn pay-desk-btn-secondary" onClick={clearFilters}>
                    Clear
                  </button>
                  <button
                    type="button"
                    className="pay-desk-btn pay-desk-btn-primary"
                    onClick={() => setFiltersOpen(false)}
                  >
                    Apply
                  </button>
                </div>
              </div>
            )}
          </div>

          <a
            href={links.runPayroll || deskPageUrl('run_payroll.php')}
            className="pay-desk-btn pay-desk-btn-primary"
          >
            <Plus size={16} aria-hidden="true" />
            <span className="pay-desk-btn-label-desktop">Run payroll</span>
            <span className="pay-desk-btn-label-mobile">Run</span>
          </a>
        </div>
      </div>

      {error && <div className="pay-desk-flash-error" role="alert">{error}</div>}

      {notice && (
        <div className="pay-desk-flash-ok" role="status">
          <span>{notice}</span>
          <button type="button" className="pay-desk-flash-dismiss" onClick={() => setNotice('')} aria-label="Dismiss">
            <X size={14} aria-hidden="true" />
          </button>
        </div>
      )}

      <section className="pay-desk-kpi-grid" aria-label="Summary">
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi">
            <div className="pay-desk-kpi-icon pay-desk-kpi-icon--violet">
              <Users size={20} aria-hidden="true" />
            </div>
            <div className="pay-desk-kpi-body">
              <div className="pay-desk-kpi-label">total employees</div>
              <div className="pay-desk-kpi-value">{stats.total_employees ?? allEmployees.length}</div>
            </div>
          </div>
        </div>
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi">
            <div className="pay-desk-kpi-icon pay-desk-kpi-icon--teal">
              <Wallet size={20} aria-hidden="true" />
            </div>
            <div className="pay-desk-kpi-body">
              <div className="pay-desk-kpi-label">with salary</div>
              <div className="pay-desk-kpi-value">{stats.with_salary ?? 0}</div>
            </div>
          </div>
        </div>
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi">
            <div className="pay-desk-kpi-icon pay-desk-kpi-icon--amber">
              <CircleAlert size={20} aria-hidden="true" />
            </div>
            <div className="pay-desk-kpi-body">
              <div className="pay-desk-kpi-label">missing salary</div>
              <div className="pay-desk-kpi-value">{stats.without_salary ?? 0}</div>
            </div>
          </div>
        </div>
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi">
            <div className="pay-desk-kpi-icon pay-desk-kpi-icon--indigo">
              <ListFilter size={20} aria-hidden="true" />
            </div>
            <div className="pay-desk-kpi-body">
              <div className="pay-desk-kpi-label">listed now</div>
              <div className="pay-desk-kpi-value">{employees.length}</div>
              <div className="pay-desk-kpi-helper">matching current filters</div>
            </div>
          </div>
        </div>
      </section>

      <section className="pay-desk-results">
        <div className="pay-desk-results-head">
          <span className="pay-desk-results-count">
            {employees.length} {employees.length === 1 ? 'result' : 'results'}
          </span>
        </div>

        {employees.length === 0 ? (
          <div className="pay-desk-empty">
            <Inbox className="pay-desk-empty-icon" aria-hidden="true" />
            <p className="pay-desk-empty-title">No employees found</p>
            <p className="pay-desk-empty-sub">
              {search || filtersActive
                ? 'Try adjusting your search or filters.'
                : 'Add active employees to manage salary structures.'}
            </p>
          </div>
        ) : (
          <div className="pay-desk-table-wrap">
            <table className="pay-desk-table">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Department</th>
                  <th className="pay-desk-hide-lg">Basic</th>
                  <th className="pay-desk-hide-lg">Allowances</th>
                  <th>Gross pay</th>
                  <th className="pay-desk-hide-md">Bank</th>
                  <th style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {employees.map((emp) => {
                  const glowing = glowEmployeeId === emp.id;
                  return (
                  <tr
                    key={emp.id}
                    ref={glowing ? glowRowRef : null}
                    className={glowing ? 'is-glow' : undefined}
                  >
                    <td>
                      <div className="pay-desk-employee-cell">
                        <EmployeeAvatar name={emp.fullName} id={emp.id} />
                        <div className="pay-desk-employee-meta">
                          <button
                            type="button"
                            className="pay-desk-name"
                            onClick={() => openEditor(emp.id)}
                          >
                            {emp.fullName}
                          </button>
                          <div className="pay-desk-cell-sub">{emp.email || '-'}</div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <span className="pay-desk-dept">{String(emp.department || '-').toUpperCase()}</span>
                    </td>
                    <td className="pay-desk-hide-lg">{formatAmount(emp.basicSalary)}</td>
                    <td className="pay-desk-hide-lg">{formatAmount(emp.allowances)}</td>
                    <td className="pay-desk-amt">{formatAmount(emp.grossPay)}</td>
                    <td className="pay-desk-hide-md">
                      {emp.bankName ? (
                        <>
                          <div className="pay-desk-cell-main">{emp.bankName}</div>
                          <div className="pay-desk-cell-sub">{emp.accountNumber}</div>
                        </>
                      ) : (
                        <span className="pay-desk-cell-sub pay-desk-empty-cell">-</span>
                      )}
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <div className="pay-desk-actions">
                        <button
                          type="button"
                          className="pay-desk-icon-btn pay-desk-icon-btn--edit"
                          title="Edit salary"
                          onClick={() => openEditor(emp.id)}
                        >
                          <img src={editIcon} alt="" className="pay-desk-edit-icon" aria-hidden="true" />
                        </button>
                      </div>
                    </td>
                  </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {editingEmployeeId > 0 && (
        <SalaryEditModal
          employeeId={editingEmployeeId}
          onClose={closeEditor}
          onSaved={handleSaved}
        />
      )}
    </div>
  );
}
