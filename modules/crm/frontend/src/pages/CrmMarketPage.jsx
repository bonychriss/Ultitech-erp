import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { FaFilePdf, FaUsers, FaUserCheck, FaFileInvoiceDollar, FaChartLine, FaSearch, FaHistory, FaEnvelope, FaCog } from 'react-icons/fa';
import CustomerContactForm from '../components/CustomerContactForm.jsx';
import SalesDocViewModal, { buildSalesDocPreviewUrl } from '../components/SalesDocViewModal.jsx';
import '../components/customer-form.css';
import { useBottomSheet } from '../hooks/useBottomSheet.js';
import { downloadSalesDocPdf } from '../utils/downloadSalesDocPdf.js';
import {
  buildContactViewUrl,
  fetchMarketAttribution,
  fetchMarketHistory,
  fetchMarketHistoryResults,
  deleteMarketHistory,
  downloadMarketHistoryPdf,
  fetchMarketLeads,
  fetchMarketMessage,
  fetchMarketSettings,
  fetchMarketStatus,
  getBootData,
  importMarketLead,
  importMarketLeadsBulk,
  runMarketSearch,
  fetchMarketSuggest,
  saveMarketMessage,
  saveMarketSettings,
  testMarketSettings,
} from '../api';

function IconTrash() {
  return (
    <svg className="crm-hist-trash-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M3 6h18" />
      <path d="M8 6V4h8v2" />
      <path d="M19 6l-1 14H6L5 6" />
      <path d="M10 11v6M14 11v6" />
    </svg>
  );
}

function IconPdf() {
  return <FaFilePdf className="crm-hist-pdf-icon" size={18} aria-hidden="true" />;
}

function IconArrowLeft() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M19 12H5" />
      <path d="m12 19-7-7 7-7" />
    </svg>
  );
}

function IconUserPlus() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
      <path d="M19 8v6M22 11h-6" />
    </svg>
  );
}

function IconClose() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.25" strokeLinecap="round" aria-hidden="true">
      <path d="M18 6 6 18" />
      <path d="m6 6 12 12" />
    </svg>
  );
}

function matchCityOption(cityRaw, country, options) {
  const needle = String(cityRaw || '').trim().toLowerCase();
  if (!needle || !country) return '';
  const cities = options?.cities_by_country?.[country] || [];
  const hit = cities.find((opt) => {
    const value = String(opt?.value ?? opt ?? '').toLowerCase();
    const label = String(opt?.label ?? opt ?? '').toLowerCase();
    return value === needle || label === needle;
  });
  return hit ? String(hit.value ?? hit) : '';
}

function formFromMarketLead(lead, defaults = {}, options = {}) {
  const name = String(lead?.name || '').trim();
  const cityRaw = String(lead?.city || lead?.location || '').trim();
  const country = String(defaults.country || 'Tanzania');
  const city = matchCityOption(cityRaw, country, options) || String(defaults.city || '');
  const website = String(lead?.website || '').trim();
  const type = String(lead?.type || lead?.category || '').trim();
  const addressBits = [
    cityRaw && cityRaw !== city ? cityRaw : '',
    website ? `Website: ${website}` : '',
  ].filter(Boolean);

  return {
    ...defaults,
    company_name: name,
    contact_person: String(defaults.contact_person || ''),
    email: String(lead?.email || '').trim(),
    phone: String(lead?.phone || '').trim(),
    source: type || String(defaults.source || 'Client Market'),
    address: addressBits.join('\n') || String(defaults.address || ''),
    country,
    city,
    status: 'lead',
    notes: lead?.id ? `market_id:${lead.id}` : String(defaults.notes || ''),
  };
}

function MarketCustomerModal({
  open,
  title,
  form,
  options,
  statuses,
  saving,
  error,
  onClose,
  onSave,
  onFieldChange,
}) {
  useEffect(() => {
    if (!open) return undefined;

    function onKeyDown(e) {
      if (e.key === 'Escape') onClose();
    }

    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKeyDown);

    return () => {
      document.body.style.overflow = '';
      window.removeEventListener('keydown', onKeyDown);
    };
  }, [open, onClose]);

  const { isMobileSheet, sheetStyle, sheetClassName, grabProps } = useBottomSheet({
    open,
    onClose,
  });

  if (!open) return null;

  return (
    <div className="crm-modal-overlay" onClick={onClose} role="presentation">
      <div
        className={`crm-modal${sheetClassName ? ` ${sheetClassName}` : ''}`}
        style={sheetStyle}
        role="dialog"
        aria-modal="true"
        aria-labelledby="crm-market-modal-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="crm-sheet-grab" {...(grabProps || {})}>
          {isMobileSheet && (
            <div className="crm-sheet-handle" aria-hidden="true">
              <span className="crm-sheet-handle-bar" />
            </div>
          )}
          <div className="crm-modal-header">
            <h3 id="crm-market-modal-title" className="crm-modal-title">{title}</h3>
            <button type="button" className="crm-modal-close" onClick={onClose} aria-label="Close">
              <IconClose />
            </button>
          </div>
        </div>

        <div className="crm-modal-form-wrap">
          <CustomerContactForm
            form={form}
            options={options}
            statuses={statuses}
            saving={saving}
            error={error}
            isNew
            onFieldChange={onFieldChange}
            onSubmit={onSave}
            onCancel={onClose}
            idPrefix="crm-market"
          />
        </div>
      </div>
    </div>
  );
}

function IconSearch() {
  return (
    <svg className="crm-desk-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="11" cy="11" r="8" />
      <path d="m21 21-4.3-4.3" />
    </svg>
  );
}

function AssignedStar() {
  return (
    <svg className="crm-desk-star" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true">
      <path fill="currentColor" d="M12 2.6 14.9 8.7l6.7.6-5.1 4.5 1.6 6.5L12 17.8 6 20.3l1.6-6.5-5.1-4.5 6.7-.6z" />
    </svg>
  );
}

function isSearchQuotaError(text) {
  const t = String(text || '').toLowerCase();
  return t.includes('monthly quota') || (t.includes('quota') && t.includes('rapidapi')) || t.includes('upgrade your plan');
}

function quotaUpgradeHref(text) {
  const match = String(text || '').match(/https?:\/\/[^\s]+/i);
  return match ? match[0].replace(/[).,]+$/, '') : 'https://rapidapi.com/letscrape-6bRBa3QguO5/api/local-business-search';
}

function ensureDotLottiePlayer() {
  if (document.getElementById('crm-dotlottie-wc')) return;
  const script = document.createElement('script');
  script.id = 'crm-dotlottie-wc';
  script.type = 'module';
  script.src = 'https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.5/dist/dotlottie-wc.js';
  document.head.appendChild(script);
}

function SearchQuotaState({ src, message }) {
  useEffect(() => {
    ensureDotLottiePlayer();
  }, []);

  const href = quotaUpgradeHref(message);

  return (
    <div className="crm-market-quota" role="status">
      <div className="crm-market-quota-anim" aria-hidden="true">
        {src ? (
          <dotlottie-wc src={src} autoplay loop speed="1" style={{ width: '220px', height: '220px' }} />
        ) : null}
      </div>
      <h2 className="crm-market-quota-title">Monthly search quota reached</h2>
      <p className="crm-market-quota-copy">
        You have used this month&apos;s RapidAPI requests on the BASIC plan. Search will work again when the quota
        resets, or after the plan is upgraded.
      </p>
      <a className="crm-desk-btn crm-desk-btn-primary" href={href} target="_blank" rel="noreferrer">
        Upgrade plan
      </a>
    </div>
  );
}

function SearchBusyState({ src }) {
  useEffect(() => {
    ensureDotLottiePlayer();
  }, []);

  return (
    <div className="crm-market-quota crm-market-searching" role="status" aria-live="polite">
      <div className="crm-market-quota-anim" aria-hidden="true">
        {src ? (
          <dotlottie-wc src={src} autoplay loop speed="1" style={{ width: '220px', height: '220px' }} />
        ) : null}
      </div>
      <p className="crm-market-searching-copy">Searching...</p>
    </div>
  );
}

function NothingAssignedState({ src, query }) {
  useEffect(() => {
    ensureDotLottiePlayer();
  }, []);

  return (
    <div className="crm-market-quota crm-market-nothing-assigned" role="status">
      <div className="crm-market-quota-anim" aria-hidden="true">
        {src ? (
          <dotlottie-wc src={src} autoplay loop speed="1" style={{ width: '220px', height: '220px' }} />
        ) : null}
      </div>
      <h2 className="crm-market-nothing-title">Nothing assigned to you</h2>
      <p className="crm-market-nothing-copy">
        No companies from{query ? ` "${query}"` : ' this search'} were assigned to you.
      </p>
    </div>
  );
}

function NothingSummaryState({ src, title, copy }) {
  useEffect(() => {
    ensureDotLottiePlayer();
  }, []);

  return (
    <div className="crm-market-quota crm-market-nothing-assigned" role="status">
      <div className="crm-market-quota-anim" aria-hidden="true">
        {src ? (
          <dotlottie-wc src={src} autoplay loop speed="1" style={{ width: '220px', height: '220px' }} />
        ) : null}
      </div>
      <h2 className="crm-market-nothing-title">{title}</h2>
      {copy ? <p className="crm-market-nothing-copy">{copy}</p> : null}
    </div>
  );
}

function marketViewHref(view, extra = {}) {
  try {
    const url = new URL(window.location.href);
    const moduleRaw = String(url.searchParams.get('module') || 'crm');
    url.searchParams.set('module', moduleRaw.split('?')[0] || 'crm');
    url.searchParams.set('view', view);
    Object.entries(extra || {}).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') {
        url.searchParams.delete(key);
      } else {
        url.searchParams.set(key, String(value));
      }
    });
    if (view !== 'summary') {
      url.searchParams.delete('type');
    }
    return url.pathname + '?' + url.searchParams.toString();
  } catch {
    const params = new URLSearchParams({ module: 'crm', view });
    Object.entries(extra || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        params.set(key, String(value));
      }
    });
    return `?${params.toString()}`;
  }
}

function websiteHref(value) {
  const text = String(value || '').trim();
  if (!text) return '';
  return /^https?:\/\//i.test(text) ? text : `https://${text}`;
}

function formatWhen(value) {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '-';
  return d.toLocaleString();
}

function historyResultToLead(row) {
  return {
    id: String(row.id || ''),
    name: String(row.name || ''),
    username: String(row.username || ''),
    category: String(row.type || row.category || ''),
    location: String(row.city || row.location || row.address || ''),
    phone: String(row.phone || ''),
    website: String(row.website || ''),
    email: String(row.email || ''),
    assigned_to: row.assignedTo ?? row.assigned_to ?? null,
    assigned_user_name: String(row.assignedToName || row.assigned_user_name || 'Unassigned'),
    imported: Boolean(row.imported),
  };
}

const COUNTRIES = [
  { code: 'tz', name: 'Tanzania' },
  { code: 'ke', name: 'Kenya' },
  { code: 'ug', name: 'Uganda' },
  { code: 'rw', name: 'Rwanda' },
  { code: 'za', name: 'South Africa' },
  { code: 'ae', name: 'United Arab Emirates' },
  { code: 'in', name: 'India' },
  { code: 'gb', name: 'United Kingdom' },
  { code: 'us', name: 'United States' },
  { code: 'cn', name: 'China' },
];

function extractRapidApiKeyFromPaste(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  const headerMatch = raw.match(/x-rapidapi-key\s*[:=]\s*['"]?([A-Za-z0-9_-]+)/i);
  if (headerMatch?.[1]) return headerMatch[1].trim();
  const compact = raw.replace(/\s+/g, '');
  if (/^[A-Za-z0-9_-]{20,}$/.test(compact)) return compact;
  const blobMatch = raw.match(/([A-Za-z0-9_-]*msh[A-Za-z0-9_-]*jsn[A-Za-z0-9_-]+)/i);
  if (blobMatch?.[1]) return blobMatch[1].trim();
  return raw;
}

function countryFlagUrl(code) {
  return `https://flagcdn.com/w40/${String(code || '').toLowerCase()}.png`;
}

function countryByName(name) {
  const n = String(name || '').trim().toLowerCase();
  return COUNTRIES.find((c) => c.name.toLowerCase() === n) || COUNTRIES[0];
}

function LocationSelect({ value, onChange, disabled }) {
  const wrapRef = useRef(null);
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const selected = countryByName(value);
  const list = useMemo(() => {
    const s = q.trim().toLowerCase();
    if (!s) return COUNTRIES;
    return COUNTRIES.filter((c) => c.name.toLowerCase().includes(s) || c.code.toLowerCase().includes(s));
  }, [q]);

  useEffect(() => {
    function onDoc(e) {
      if (!wrapRef.current?.contains(e.target)) setOpen(false);
    }
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  return (
    <div className="crm-market-country" ref={wrapRef}>
      <button
        type="button"
        className="crm-market-country-btn"
        disabled={disabled}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label="Location"
        onClick={() => setOpen((v) => !v)}
      >
        <img className="crm-market-flag" src={countryFlagUrl(selected.code)} alt="" width={20} height={14} />
        <span className="crm-market-country-name">{selected.name}</span>
      </button>
      {open ? (
        <div className="crm-market-country-menu" role="listbox">
          <input
            className="crm-market-country-search"
            type="search"
            autoFocus
            placeholder="Search country..."
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
          <ul className="crm-market-country-list">
            {list.map((c) => (
              <li key={c.code}>
                <button
                  type="button"
                  className={`crm-market-country-option${c.name === selected.name ? ' is-active' : ''}`}
                  role="option"
                  aria-selected={c.name === selected.name}
                  onClick={() => {
                    onChange(c.name);
                    setOpen(false);
                    setQ('');
                  }}
                >
                  <img className="crm-market-flag" src={countryFlagUrl(c.code)} alt="" width={20} height={14} />
                  <span className="crm-market-country-name">{c.name}</span>
                </button>
              </li>
            ))}
            {list.length === 0 ? <li className="crm-market-country-empty">No country found</li> : null}
          </ul>
        </div>
      ) : null}
    </div>
  );
}

export default function CrmMarketPage() {
  const boot = getBootData();
  const viewerId = Number(boot.user?.id || 0);
  const links = boot.links || {};
  const nothingSrc = links.nothingAnimation || '/assets/animations/nothing.lottie';
  const initial = boot.market || {};
  const formDefaults = boot.defaults || {};
  const formOptions = boot.options || {};
  const formStatuses = Array.isArray(boot.statuses) && boot.statuses.length
    ? boot.statuses
    : [
      { value: 'lead', label: 'Lead' },
      { value: 'prospect', label: 'Prospect' },
      { value: 'customer', label: 'Client' },
      { value: 'inactive', label: 'Inactive' },
    ];
  const view = String(initial.view || 'home');
  const isImport = view === 'import';
  const isSearch = view === 'search';
  const isHistory = view === 'history';
  const isNewLeads = view === 'new-leads';
  const isSummary = view === 'summary';
  const isMessage = view === 'message';
  const isSettings = view === 'settings';
  const isHome = view === 'home' || (!isImport && !isSearch && !isHistory && !isNewLeads && !isSummary && !isMessage && !isSettings);
  const historyMineOnly = isNewLeads;
  const summaryType = (() => {
    try {
      const t = String(new URLSearchParams(window.location.search).get('type') || 'leads').toLowerCase();
      return ['leads', 'crm', 'quotes', 'sales'].includes(t) ? t : 'leads';
    } catch {
      return 'leads';
    }
  })();

  const [status, setStatus] = useState(initial.status || {});
  const [leads, setLeads] = useState(Array.isArray(initial.leads) ? initial.leads : []);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState({});
  const [busyId, setBusyId] = useState('');
  const [bulkBusy, setBulkBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [searchQ, setSearchQ] = useState(() => {
    try {
      return new URLSearchParams(window.location.search).get('q') || '';
    } catch {
      return '';
    }
  });
  const [searchLocation, setSearchLocation] = useState('Tanzania');
  const [searchBusy, setSearchBusy] = useState(false);
  const [suggestions, setSuggestions] = useState([]);
  const [suggestOpen, setSuggestOpen] = useState(false);
  const [searchRows, setSearchRows] = useState([]);
  const [historyRows, setHistoryRows] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [historyOpen, setHistoryOpen] = useState(null);
  const [historyResults, setHistoryResults] = useState([]);
  const [historyBusy, setHistoryBusy] = useState(false);
  const [customerModalOpen, setCustomerModalOpen] = useState(false);
  const [customerForm, setCustomerForm] = useState(() => ({ ...formDefaults }));
  const [customerLeadId, setCustomerLeadId] = useState('');
  const [customerSaving, setCustomerSaving] = useState(false);
  const [customerError, setCustomerError] = useState('');
  const [tplSubject, setTplSubject] = useState('');
  const [tplHtml, setTplHtml] = useState('');
  const [tplSendMode, setTplSendMode] = useState('manual');
  const [tplBusy, setTplBusy] = useState(false);
  const [setKey, setSetKey] = useState('');
  const [setKeyMasked, setSetKeyMasked] = useState('');
  const [setHasKey, setSetHasKey] = useState(false);
  const [setBusy, setSetBusy] = useState(false);
  const [testBusy, setTestBusy] = useState(false);
  const [tokenStatus, setTokenStatus] = useState(''); // '', testing, ok, quota, fail
  const [tokenFocused, setTokenFocused] = useState(false);
  const [attribution, setAttribution] = useState(null);
  const [attributionLoading, setAttributionLoading] = useState(false);
  const [docViewer, setDocViewer] = useState(null);
  const [docDownloading, setDocDownloading] = useState(false);

  const customersUrl = links.customersList || links.dashboard || '#';
  const selectedIds = useMemo(
    () => Object.keys(selected).filter((id) => selected[id]),
    [selected]
  );

  const refresh = useCallback(async (q = search) => {
    setError('');
    try {
      const data = await fetchMarketLeads(q, isHome);
      setStatus(data.status || {});
      setLeads(Array.isArray(data.leads) ? data.leads : []);
    } catch (e) {
      setError(e.message || 'Failed to load market leads.');
    }
  }, [search, isHome]);

  useEffect(() => {
    if (isImport || isHome) void refresh();
  }, [isImport, isHome, refresh]);

  useEffect(() => {
    if (!isHome && !isSummary) return undefined;
    let cancelled = false;
    setAttributionLoading(true);
    fetchMarketAttribution(true)
      .then((data) => {
        if (!cancelled) setAttribution(data || null);
      })
      .catch(() => {
        if (!cancelled) setAttribution(null);
      })
      .finally(() => {
        if (!cancelled) setAttributionLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [isHome, isSummary]);

  useEffect(() => {
    if (!isSearch) return undefined;
    const q = searchQ.trim();
    if (q.length < 2) {
      setSuggestions([]);
      return undefined;
    }
    const timer = setTimeout(() => {
      fetchMarketSuggest(q, searchLocation)
        .then((data) => {
          setSuggestions(Array.isArray(data.suggestions) ? data.suggestions : []);
          setSuggestOpen(true);
        })
        .catch(() => setSuggestions([]));
    }, 280);
    return () => clearTimeout(timer);
  }, [isSearch, searchQ, searchLocation]);

  useEffect(() => {
    if (!isHistory && !isNewLeads && !isSearch) return undefined;
    let cancelled = false;
    setHistoryLoading(true);
    fetchMarketHistory()
      .then((data) => {
        if (!cancelled) setHistoryRows(Array.isArray(data.records) ? data.records : []);
      })
      .catch((e) => {
        if (!cancelled && (isHistory || isNewLeads)) setError(e.message || 'Could not load saved searches.');
      })
      .finally(() => {
        if (!cancelled) setHistoryLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [isHistory, isNewLeads, isSearch]);

  useEffect(() => {
    if (!isMessage) return undefined;
    let cancelled = false;
    fetchMarketMessage()
      .then((data) => {
        if (cancelled) return;
        setTplSubject(String(data.subject || ''));
        setTplHtml(String(data.html || ''));
        setTplSendMode(data.sendMode === 'automatic' ? 'automatic' : 'manual');
      })
      .catch((e) => {
        if (!cancelled) setError(e.message || 'Could not load message template.');
      });
    return () => {
      cancelled = true;
    };
  }, [isMessage]);

  useEffect(() => {
    if (!isSettings) return undefined;
    let cancelled = false;
    fetchMarketSettings()
      .then((data) => {
        if (cancelled) return;
        setSetKeyMasked(String(data.keyMasked || ''));
        setSetHasKey(Boolean(data.hasKey));
        setSetKey('');
        setTokenStatus('');
      })
      .catch((e) => {
        if (!cancelled) setError(e.message || 'Could not load settings.');
      });
    fetchMarketStatus()
      .then((data) => {
        if (!cancelled) setStatus(data || {});
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [isSettings]);

  useEffect(() => {
    if (!isSettings) return undefined;
    const token = extractRapidApiKeyFromPaste(setKey);
    if (token.length < 12) {
      setTokenStatus('');
      return undefined;
    }

    let cancelled = false;
    setTokenStatus('testing');
    setTestBusy(true);
    const timer = setTimeout(() => {
      testMarketSettings(token)
        .then((data) => {
          if (cancelled) return;
          if (data?.normalized_key && data.normalized_key !== setKey.trim()) {
            setSetKey(String(data.normalized_key));
          }
          const quotaHit = Boolean(data?.quota_exceeded) || Number(data?.code) === 429;
          setTokenStatus(quotaHit ? 'quota' : 'ok');
          if (quotaHit) {
            setMessage('');
            setError(data?.message || 'Token is valid, but the monthly RapidAPI quota is used up.');
          } else {
            setError('');
            setMessage(data?.message || 'API token works.');
          }
        })
        .catch((err) => {
          if (cancelled) return;
          setTokenStatus('fail');
          setMessage('');
          setError(err.message || 'API token test failed.');
        })
        .finally(() => {
          if (!cancelled) setTestBusy(false);
        });
    }, 650);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [isSettings, setKey]);

  const toggleOne = (id) => {
    setSelected((prev) => ({ ...prev, [id]: !prev[id] }));
  };

  const toggleAll = () => {
    const importable = leads.filter((l) => !l.imported);
    const allOn = importable.length > 0 && importable.every((l) => selected[l.id]);
    if (allOn) {
      setSelected({});
      return;
    }
    const next = {};
    importable.forEach((l) => {
      next[l.id] = true;
    });
    setSelected(next);
  };

  const onImportOne = async (lead) => {
    setBusyId(lead.id);
    setError('');
    setMessage('');
    try {
      const data = await importMarketLead(lead.id);
      setMessage(data?.created === false ? 'Already in My Customers.' : 'Added to My Customers.');
      setSearchRows((prev) => prev.map((r) => (r.id === lead.id ? { ...r, imported: true } : r)));
      if (isImport || isHome) await refresh();
      setSelected((prev) => {
        const next = { ...prev };
        delete next[lead.id];
        return next;
      });
    } catch (e) {
      setError(e.message || 'Import failed.');
    } finally {
      setBusyId('');
    }
  };

  const onImportSelected = async () => {
    if (selectedIds.length === 0) return;
    setBulkBusy(true);
    setError('');
    setMessage('');
    try {
      const data = await importMarketLeadsBulk(selectedIds);
      setMessage(
        `Imported ${data.created || 0} lead(s)` +
          (data.skipped ? `, ${data.skipped} already present` : '') +
          '.'
      );
      setSelected({});
      await refresh();
    } catch (e) {
      setError(e.message || 'Bulk import failed.');
    } finally {
      setBulkBusy(false);
    }
  };

  const onRunSearch = async (e) => {
    e.preventDefault();
    setSearchBusy(true);
    setError('');
    setMessage('');
    try {
      const data = await runMarketSearch(searchQ, searchLocation);
      setSearchRows(Array.isArray(data.results) ? data.results : []);
      const sales = Number(data.sales_count || 0);
      const imported = Number(data.imported || 0);
      setMessage(
        `Found ${Array.isArray(data.results) ? data.results.length : 0} companies, saved to the database` +
          (sales ? `, split across ${sales} sales ${sales === 1 ? 'person' : 'people'}` : '') +
          (imported
            ? `, sent ${imported} to Prospects for each assignee`
            : ', sent assigned companies to Prospects') +
          `. ${data.skipped || 0} already stored.`
      );
      fetchMarketHistory()
        .then((hist) => setHistoryRows(Array.isArray(hist.records) ? hist.records : []))
        .catch(() => {});
    } catch (err) {
      const text = err.message || 'Search failed.';
      setError(text);
      if (isSearchQuotaError(text)) {
        setSearchRows([]);
      }
    } finally {
      setSearchBusy(false);
    }
  };

  const openSavedSearch = async (row) => {
    const query = String(row?.query || '').trim();
    const location = String(row?.location || '').trim();
    if (query) setSearchQ(query);
    if (location) setSearchLocation(location);
    setSearchBusy(true);
    setError('');
    setMessage('');
    try {
      const data = await fetchMarketHistoryResults(row.id);
      const rows = Array.isArray(data.rows) ? data.rows.map(historyResultToLead) : [];
      setSearchRows(rows);
      if (rows.length === 0) {
        setMessage('No companies stored for this search.');
      }
    } catch (err) {
      setError(err.message || 'Could not open that search.');
    } finally {
      setSearchBusy(false);
    }
  };

  const deleteSavedSearch = async (row, e) => {
    e?.preventDefault?.();
    e?.stopPropagation?.();
    const id = String(row?.id || '');
    if (!id || busyId === id) return;
    setBusyId(id);
    setError('');
    try {
      const data = await deleteMarketHistory(id);
      setHistoryRows(Array.isArray(data?.records) ? data.records : []);
      if (historyOpen && historyOpen.id === id) {
        setHistoryOpen(null);
        setHistoryResults([]);
      }
    } catch (err) {
      setError(err.message || 'Could not delete that search.');
    } finally {
      setBusyId('');
    }
  };

  const downloadSavedSearch = async (row, e, mine = false) => {
    e?.preventDefault?.();
    e?.stopPropagation?.();
    const id = String(row?.id || '');
    if (!id || busyId === `dl-${id}`) return;
    setBusyId(`dl-${id}`);
    setError('');
    try {
      await downloadMarketHistoryPdf(id, mine);
    } catch (err) {
      setError(err.message || 'Could not download that search.');
    } finally {
      setBusyId('');
    }
  };

  const openHistoryLeadAsCustomer = (lead) => {
    const id = String(lead?.id || '');
    if (!id || lead.imported || customerSaving) return;
    setCustomerLeadId(id);
    setCustomerForm(formFromMarketLead(lead, formDefaults, formOptions));
    setCustomerError('');
    setError('');
    setMessage('');
    setCustomerModalOpen(true);
  };

  const closeCustomerModal = () => {
    if (customerSaving) return;
    setCustomerModalOpen(false);
    setCustomerLeadId('');
    setCustomerError('');
    setCustomerForm({ ...formDefaults });
  };

  const updateCustomerField = (field, value) => {
    setCustomerForm((prev) => {
      if (field === 'country') {
        return { ...prev, country: value, city: '' };
      }
      return { ...prev, [field]: value };
    });
  };

  const saveHistoryLeadAsCustomer = async (e) => {
    e.preventDefault();
    const id = String(customerLeadId || '');
    if (!id || customerSaving) return;
    if (!customerForm.company_name?.trim() || !customerForm.contact_person?.trim() || !customerForm.source?.trim()) {
      setCustomerError('Company name, contact person, and source are required.');
      return;
    }

    setCustomerSaving(true);
    setCustomerError('');
    try {
      const data = await importMarketLead(id, customerForm);
      setHistoryResults((prev) =>
        prev.map((row) => (String(row.id) === id ? { ...row, imported: true } : row))
      );
      setSearchRows((prev) =>
        prev.map((row) => (String(row.id) === id ? { ...row, imported: true } : row))
      );
      setMessage(data?.message || 'Added to My Customers.');
      setCustomerModalOpen(false);
      setCustomerLeadId('');
      setCustomerForm({ ...formDefaults });
    } catch (err) {
      setCustomerError(err.message || 'Could not add that lead as a customer.');
    } finally {
      setCustomerSaving(false);
    }
  };

  const onSaveMessage = async (e) => {
    e.preventDefault();
    setTplBusy(true);
    setError('');
    setMessage('');
    try {
      await saveMarketMessage({ subject: tplSubject, html: tplHtml, sendMode: tplSendMode });
      setMessage('Message template saved.');
    } catch (err) {
      setError(err.message || 'Could not save message.');
    } finally {
      setTplBusy(false);
    }
  };

  const onSaveSettings = async (e) => {
    e.preventDefault();
    const token = extractRapidApiKeyFromPaste(setKey);
    if (!token) {
      setError(setHasKey ? 'Enter a new token to replace the saved one.' : 'Paste an API token to save.');
      return;
    }
    if (tokenStatus !== 'ok' && tokenStatus !== 'quota') {
      setError(tokenStatus === 'testing' ? 'Wait for the token test to finish.' : 'Token must pass the connection test before saving.');
      return;
    }
    setSetBusy(true);
    setError('');
    setMessage('');
    try {
      const data = await saveMarketSettings({ key: token });
      setSetKeyMasked(String(data.keyMasked || ''));
      setSetHasKey(Boolean(data.hasKey));
      setSetKey('');
      setTokenStatus('ok');
      setMessage('API token saved.');
    } catch (err) {
      setError(err.message || 'Could not save API token.');
    } finally {
      setSetBusy(false);
    }
  };

  const openSalesDoc = useCallback((doc) => {
    const downloadUrl = String(doc?.download_url || '').trim();
    const previewUrl = buildSalesDocPreviewUrl(downloadUrl);
    if (!previewUrl) {
      if (doc?.view_url) {
        window.open(doc.view_url, '_blank', 'noopener,noreferrer');
      }
      return;
    }
    const kindLabel = doc?.kind === 'invoice' ? 'Invoice' : 'Quotation';
    setDocViewer({
      title: `${kindLabel} ${doc?.number || `#${doc?.id || ''}`}`.trim(),
      previewUrl,
      downloadUrl,
    });
  }, []);

  const closeSalesDoc = useCallback(() => {
    if (docDownloading) return;
    setDocViewer(null);
  }, [docDownloading]);

  const downloadOpenSalesDoc = useCallback(async () => {
    if (!docViewer?.downloadUrl || docDownloading) return;
    setDocDownloading(true);
    try {
      await downloadSalesDocPdf(docViewer.downloadUrl);
    } catch {
      // keep modal open; silent fail is fine for now
    } finally {
      setDocDownloading(false);
    }
  }, [docViewer, docDownloading]);

  if (isSummary) {
    const stats = attribution || {};
    const formatDocDate = (value) => {
      const raw = String(value || '').trim();
      if (!raw) return '-';
      return raw.slice(0, 10) || raw;
    };
    const titles = {
      leads: 'Leads assigned',
      crm: 'In CRM',
      quotes: 'Quotations',
      sales: 'Sales',
    };
    const helpers = {
      leads: 'from Market searches',
      crm: 'imported as customers',
      quotes: stats.quotes_total_formatted && stats.quotes_total_formatted !== '-'
        ? stats.quotes_total_formatted
        : 'open pipeline',
      sales: stats.invoices_total_formatted && stats.invoices_total_formatted !== '-'
        ? stats.invoices_total_formatted
        : 'invoices from Market',
    };
    const counts = {
      leads: Number(stats.leads_assigned || 0),
      crm: Number(stats.in_crm || 0),
      quotes: Number(stats.quotes_count || 0),
      sales: Number(stats.invoices_count || 0),
    };
    const leads = Array.isArray(stats.leads) ? stats.leads : [];
    const customers = Array.isArray(stats.customers) ? stats.customers : [];
    const quotes = Array.isArray(stats.quotes) ? stats.quotes : [];
    const invoices = Array.isArray(stats.invoices) ? stats.invoices : [];

    return (
      <div className="crm-desk-page crm-market-page">
        <p className="crm-market-meta crm-market-history-toolbar">
          <button
            type="button"
            className="crm-hist-icon-btn"
            title="Back to Home"
            aria-label="Back to Home"
            onClick={() => { window.location.href = marketViewHref('home'); }}
          >
            <IconArrowLeft />
          </button>
          <span className="crm-market-history-summary">
            {titles[summaryType] || 'Summary'}
            {' — '}
            {attributionLoading && !attribution ? '...' : counts[summaryType]}
            {helpers[summaryType] ? ` · ${helpers[summaryType]}` : ''}
          </span>
        </p>

        <section className="crm-market-table-wrap crm-market-wins-wrap">
          {attributionLoading && !attribution ? (
            <div className="crm-market-empty"><h2>Loading summary...</h2></div>
          ) : summaryType === 'leads' ? (
            leads.length === 0 ? (
              <NothingSummaryState
                src={nothingSrc}
                title="No assigned leads yet"
                copy="Assign companies from Market searches to see them here."
              />
            ) : (
              <table className="crm-desk-table crm-market-table crm-market-wins-table">
                <thead>
                  <tr>
                    <th className="crm-wins-type">No</th>
                    <th className="crm-wins-company">Company</th>
                    <th className="crm-wins-person">Location</th>
                    <th className="crm-wins-number">Phone</th>
                    <th className="crm-wins-person">Assigned to</th>
                  </tr>
                </thead>
                <tbody>
                  {leads.map((lead, i) => (
                    <tr key={lead.id || i}>
                      <td className="crm-wins-type">{i + 1}</td>
                      <td className="crm-wins-company" title={lead.name || ''}>
                        <span className="crm-wins-company-text">{lead.name || lead.username || '-'}</span>
                        {lead.category ? <div className="crm-market-sub">{lead.category}</div> : null}
                      </td>
                      <td className="crm-wins-person">{lead.location || '-'}</td>
                      <td className="crm-wins-number">{lead.phone || '-'}</td>
                      <td className="crm-wins-person">{lead.assigned_user_name || 'Unassigned'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )
          ) : summaryType === 'crm' ? (
            customers.length === 0 ? (
              <NothingSummaryState
                src={nothingSrc}
                title="No Market customers in CRM yet"
                copy="Import Market leads as customers to see them here."
              />
            ) : (
              <table className="crm-desk-table crm-market-table crm-market-wins-table">
                <thead>
                  <tr>
                    <th className="crm-wins-type">No</th>
                    <th className="crm-wins-company">Company</th>
                    <th className="crm-wins-person">Source</th>
                    <th className="crm-wins-amount">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {customers.map((c, i) => {
                    const href = c.contact_id ? buildContactViewUrl(c.contact_id, links) : '';
                    return (
                      <tr
                        key={c.contact_id || c.customer_id || i}
                        className={href ? 'crm-market-wins-row is-clickable' : undefined}
                        onClick={href ? () => { window.location.href = href; } : undefined}
                      >
                        <td className="crm-wins-type">{i + 1}</td>
                        <td className="crm-wins-company" title={c.company || ''}>
                          <span className="crm-wins-company-text">{c.company || '-'}</span>
                        </td>
                        <td className="crm-wins-person">{c.source || 'CRM Market'}</td>
                        <td className="crm-wins-amount">{href ? 'Open' : '-'}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )
          ) : summaryType === 'quotes' ? (
            quotes.length === 0 ? (
              <NothingSummaryState
                src={nothingSrc}
                title="No quotations from Market customers yet"
                copy="Import Market leads as customers, then create quotations in Sales."
              />
            ) : (
              <table className="crm-desk-table crm-market-table crm-market-wins-table">
                <thead>
                  <tr>
                    <th className="crm-wins-company">Company</th>
                    <th className="crm-wins-number">Number</th>
                    <th className="crm-wins-date">Date</th>
                    <th className="crm-wins-person">Status</th>
                    <th className="crm-wins-person">Salesperson</th>
                    <th className="crm-wins-amount">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {quotes.map((doc) => {
                    const statusKey = String(doc.status || '').toLowerCase();
                    let statusLabel = 'Quotation';
                    if (['invoiced', 'paid'].includes(statusKey) || doc.converted) {
                      statusLabel = doc.invoice_number
                        ? `Invoiced · ${doc.invoice_number}`
                        : 'Invoiced';
                    } else if (['confirmed', 'shipped', 'delivered'].includes(statusKey)) {
                      statusLabel = 'Sales order';
                    } else if (statusKey && statusKey !== 'draft' && statusKey !== 'quotation') {
                      statusLabel = String(doc.status || 'Quotation');
                    }
                    return (
                      <tr
                        key={`q-${doc.id}`}
                        className={(doc.download_url || doc.view_url) ? 'crm-market-wins-row is-clickable' : undefined}
                        onClick={() => openSalesDoc(doc)}
                      >
                        <td className="crm-wins-company" title={doc.company || ''}>
                          <span className="crm-wins-company-text">{doc.company || '-'}</span>
                        </td>
                        <td className="crm-wins-number">{doc.number || '-'}</td>
                        <td className="crm-wins-date">{formatDocDate(doc.date)}</td>
                        <td className="crm-wins-person">{statusLabel}</td>
                        <td className="crm-wins-person">{doc.salesperson || '-'}</td>
                        <td className="crm-wins-amount crm-desk-price">{doc.amount_formatted || '-'}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )
          ) : invoices.length === 0 ? (
            <NothingSummaryState
              src={nothingSrc}
              title="No sales invoices from Market customers yet"
              copy="Import Market leads as customers, then create invoices in Sales."
            />
          ) : (
            <table className="crm-desk-table crm-market-table crm-market-wins-table">
              <thead>
                <tr>
                  <th className="crm-wins-company">Company</th>
                  <th className="crm-wins-number">Number</th>
                  <th className="crm-wins-date">Date</th>
                  <th className="crm-wins-person">Source</th>
                  <th className="crm-wins-person">Salesperson</th>
                  <th className="crm-wins-amount">Amount</th>
                </tr>
              </thead>
              <tbody>
                {invoices.map((doc) => (
                    <tr
                      key={`s-${doc.id}`}
                      className={(doc.download_url || doc.view_url) ? 'crm-market-wins-row is-clickable' : undefined}
                      onClick={() => openSalesDoc(doc)}
                    >
                      <td className="crm-wins-company" title={doc.company || ''}>
                        <span className="crm-wins-company-text">{doc.company || '-'}</span>
                      </td>
                      <td className="crm-wins-number">{doc.number || '-'}</td>
                      <td className="crm-wins-date">{formatDocDate(doc.date)}</td>
                      <td className="crm-wins-person">{doc.from_quote ? 'From quotation' : 'Direct'}</td>
                      <td className="crm-wins-person">{doc.salesperson || '-'}</td>
                      <td className="crm-wins-amount crm-desk-price">{doc.amount_formatted || '-'}</td>
                    </tr>
                  ))}
              </tbody>
            </table>
          )}
        </section>
        <SalesDocViewModal
          open={Boolean(docViewer)}
          title={docViewer?.title || ''}
          previewUrl={docViewer?.previewUrl || ''}
          downloadUrl={docViewer?.downloadUrl || ''}
          onDownload={() => void downloadOpenSalesDoc()}
          onClose={closeSalesDoc}
        />
      </div>
    );
  }

  if (isHome) {
    const stats = attribution || {};
    const recentDocs = Array.isArray(stats.recent) ? stats.recent : [];
    const formatDocDate = (value) => {
      const raw = String(value || '').trim();
      if (!raw) return '-';
      const d = raw.slice(0, 10);
      return d || raw;
    };

    return (
      <div className="crm-desk-page crm-market-page">
        <section className="crm-market-perf" aria-label="Market performance">
          <div className="crm-desk-kpi-grid crm-market-perf-grid">
            <a className="crm-desk-kpi-card crm-desk-kpi-card--link" href={marketViewHref('summary', { type: 'leads' })}>
              <div className="crm-desk-kpi-icon crm-desk-kpi-icon--indigo" aria-hidden="true">
                <FaUsers />
              </div>
              <div>
                <div className="crm-desk-kpi-label">Leads assigned</div>
                <div className="crm-desk-kpi-value">{attributionLoading && !attribution ? '...' : Number(stats.leads_assigned || 0)}</div>
                <div className="crm-desk-kpi-helper">from Market searches</div>
              </div>
            </a>
            <a className="crm-desk-kpi-card crm-desk-kpi-card--link" href={marketViewHref('summary', { type: 'crm' })}>
              <div className="crm-desk-kpi-icon crm-desk-kpi-icon--teal" aria-hidden="true">
                <FaUserCheck />
              </div>
              <div>
                <div className="crm-desk-kpi-label">In CRM</div>
                <div className="crm-desk-kpi-value">{attributionLoading && !attribution ? '...' : Number(stats.in_crm || 0)}</div>
                <div className="crm-desk-kpi-helper">imported as customers</div>
              </div>
            </a>
            <a className="crm-desk-kpi-card crm-desk-kpi-card--link" href={marketViewHref('summary', { type: 'quotes' })}>
              <div className="crm-desk-kpi-icon crm-desk-kpi-icon--amber" aria-hidden="true">
                <FaFileInvoiceDollar />
              </div>
              <div>
                <div className="crm-desk-kpi-label">Quotations</div>
                <div className="crm-desk-kpi-value">{attributionLoading && !attribution ? '...' : Number(stats.quotes_count || 0)}</div>
                <div className="crm-desk-kpi-helper">{stats.quotes_total_formatted && stats.quotes_total_formatted !== '-' ? stats.quotes_total_formatted : 'open pipeline'}</div>
              </div>
            </a>
            <a className="crm-desk-kpi-card crm-desk-kpi-card--link" href={marketViewHref('summary', { type: 'sales' })}>
              <div className="crm-desk-kpi-icon crm-desk-kpi-icon--rose" aria-hidden="true">
                <FaChartLine />
              </div>
              <div>
                <div className="crm-desk-kpi-label">Sales</div>
                <div className="crm-desk-kpi-value">{attributionLoading && !attribution ? '...' : Number(stats.invoices_count || 0)}</div>
                <div className="crm-desk-kpi-helper">{stats.invoices_total_formatted && stats.invoices_total_formatted !== '-' ? stats.invoices_total_formatted : 'invoices from Market'}</div>
              </div>
            </a>
          </div>

          {recentDocs.length > 0 ? (
            <div className="crm-market-wins-block">
              <h3 className="crm-market-wins-title">Recent quotes &amp; sales</h3>
              <div className="crm-market-table-wrap crm-market-wins-wrap">
                <table className="crm-desk-table crm-market-table crm-market-wins-table">
                  <thead>
                    <tr>
                      <th className="crm-wins-type">Type</th>
                      <th className="crm-wins-company">Company</th>
                      <th className="crm-wins-number">Number</th>
                      <th className="crm-wins-date">Date</th>
                      <th className="crm-wins-person">Salesperson</th>
                      <th className="crm-wins-amount">Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    {recentDocs.map((doc) => {
                      const kind = doc.kind === 'invoice' ? 'Sale' : 'Quote';
                      const canOpen = Boolean(doc.download_url || doc.view_url);
                      const rowInner = (
                        <>
                          <td className="crm-wins-type">
                            <span className={`crm-market-doc-kind crm-market-doc-kind--${doc.kind === 'invoice' ? 'sale' : 'quote'}`}>
                              {kind}
                            </span>
                          </td>
                          <td className="crm-wins-company" title={doc.company || ''}>
                            <span className="crm-wins-company-text">{doc.company || '-'}</span>
                          </td>
                          <td className="crm-wins-number">{doc.number || '-'}</td>
                          <td className="crm-wins-date">{formatDocDate(doc.date)}</td>
                          <td className="crm-wins-person">{doc.salesperson || '-'}</td>
                          <td className="crm-wins-amount crm-desk-price">{doc.amount_formatted || '-'}</td>
                        </>
                      );
                      return canOpen ? (
                        <tr
                          key={`${doc.kind}-${doc.id}`}
                          className="crm-market-wins-row is-clickable"
                          onClick={() => openSalesDoc(doc)}
                        >
                          {rowInner}
                        </tr>
                      ) : (
                        <tr key={`${doc.kind}-${doc.id}`}>{rowInner}</tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          ) : !attributionLoading ? (
            <NothingSummaryState
              src={nothingSrc}
              title="No quotes or invoices yet from Market customers"
              copy="Import leads, then create quotes in Sales."
            />
          ) : null}
        </section>

        <section className="crm-market-home-grid">
          <a className="crm-market-home-card" href={marketViewHref('search')}>
            <span className="crm-market-home-card-icon" aria-hidden="true"><FaSearch /></span>
            <h2>Search</h2>
            <p>Look up businesses by keyword and country. Results are saved as prospects.</p>
          </a>
          <a className="crm-market-home-card" href={marketViewHref('history')}>
            <span className="crm-market-home-card-icon" aria-hidden="true"><FaHistory /></span>
            <h2>Saved search</h2>
            <p>Re-open previous search runs and the companies they returned.</p>
          </a>
          <a className="crm-market-home-card" href={marketViewHref('message')}>
            <span className="crm-market-home-card-icon" aria-hidden="true"><FaEnvelope /></span>
            <h2>Message</h2>
            <p>Edit the outreach email subject and body used for market leads.</p>
          </a>
          <a className="crm-market-home-card" href={marketViewHref('settings')}>
            <span className="crm-market-home-card-icon" aria-hidden="true"><FaCog /></span>
            <h2>Settings</h2>
            <p>Paste the RapidAPI token and test that search works.</p>
          </a>
        </section>
        <SalesDocViewModal
          open={Boolean(docViewer)}
          title={docViewer?.title || ''}
          previewUrl={docViewer?.previewUrl || ''}
          downloadUrl={docViewer?.downloadUrl || ''}
          onDownload={() => void downloadOpenSalesDoc()}
          onClose={closeSalesDoc}
        />
      </div>
    );
  }

  if (isMessage) {
    return (
      <div className="crm-desk-page crm-market-page">
        <header className="crm-market-hero crm-market-hero--compact">
          <div>
            <p className="crm-market-eyebrow">CRM Market</p>
            <h1 className="crm-market-title">Message</h1>
            <p className="crm-market-lead">Placeholders: {'{{BusinessName}}'}, {'{{Category}}'}, {'{{Location}}'}.</p>
          </div>
        </header>
        {(message || error) && (
          <div className={`crm-market-flash ${error ? 'is-error' : 'is-ok'}`} role="status">
            {error || message}
          </div>
        )}
        <form className="crm-market-form" onSubmit={(e) => void onSaveMessage(e)}>
          <label className="crm-market-field">
            <span>Subject</span>
            <input type="text" value={tplSubject} onChange={(e) => setTplSubject(e.target.value)} required />
          </label>
          <label className="crm-market-field">
            <span>Send mode</span>
            <select value={tplSendMode} onChange={(e) => setTplSendMode(e.target.value)}>
              <option value="manual">Manual</option>
              <option value="automatic">Automatic</option>
            </select>
          </label>
          <label className="crm-market-field">
            <span>HTML body</span>
            <textarea rows={14} value={tplHtml} onChange={(e) => setTplHtml(e.target.value)} />
          </label>
          <button type="submit" className="crm-desk-btn crm-desk-btn-primary" disabled={tplBusy}>
            {tplBusy ? 'Saving...' : 'Save message'}
          </button>
        </form>
      </div>
    );
  }

  if (isSettings) {
    return (
      <div className="crm-desk-page crm-market-page">
        <header className="crm-market-hero crm-market-hero--compact">
          <div>
            <p className="crm-market-eyebrow">CRM Market</p>
            <h1 className="crm-market-title">Settings</h1>
            <p className="crm-market-lead">Paste your RapidAPI key (or a curl snippet). It is tested automatically.</p>
          </div>
        </header>
        <section className="crm-market-status" aria-live="polite">
          <div className={`crm-market-pill ${status.connected ? 'is-ok' : 'is-warn'}`}>
            {status.connected ? 'Database connected' : 'Setup needed'}
          </div>
          <p>{status.message || 'Loading...'}</p>
          <p className="crm-market-meta">
            {Number(status.lead_count || 0)} leads in the market store
            {status.database ? ` | ${status.database}` : ''}
          </p>
        </section>
        {(message || error) && (
          <div className={`crm-market-flash ${error ? 'is-error' : 'is-ok'}`} role="status">
            {error || message}
          </div>
        )}
        <form className="crm-market-form" onSubmit={(e) => void onSaveSettings(e)}>
          <label className="crm-market-field">
            <span>API token {setHasKey ? `(saved: ${setKeyMasked})` : '(not set)'}</span>
            <input
              type="password"
              className={[
                'crm-market-token-input',
                tokenStatus === 'fail'
                  ? 'is-token-fail'
                  : tokenStatus === 'ok' || tokenStatus === 'quota' || setKey.trim() || tokenFocused
                    ? 'is-token-ok'
                    : '',
              ].filter(Boolean).join(' ')}
              value={setKey}
              onFocus={() => setTokenFocused(true)}
              onBlur={() => setTokenFocused(false)}
              onChange={(e) => {
                setTokenStatus('');
                setSetKey(extractRapidApiKeyFromPaste(e.target.value));
              }}
              onPaste={(e) => {
                const text = e.clipboardData?.getData('text') || '';
                if (!text) return;
                e.preventDefault();
                setTokenStatus('');
                setSetKey(extractRapidApiKeyFromPaste(text));
              }}
              placeholder={setHasKey ? 'Paste a new token to replace the saved one' : 'Paste RapidAPI token or curl'}
              autoComplete="off"
            />
            {tokenStatus === 'testing' ? (
              <small className="crm-market-token-hint">Testing token...</small>
            ) : tokenStatus === 'ok' ? (
              <small className="crm-market-token-hint is-ok">Token verified — search is ready</small>
            ) : tokenStatus === 'quota' ? (
              <small className="crm-market-token-hint is-fail">Token valid — monthly quota used up</small>
            ) : tokenStatus === 'fail' ? (
              <small className="crm-market-token-hint is-fail">Token failed verification</small>
            ) : null}
          </label>
          <div className="crm-market-settings-actions">
            <button
              type="submit"
              className="crm-desk-btn crm-desk-btn-primary"
              disabled={setBusy || testBusy || (tokenStatus !== 'ok' && tokenStatus !== 'quota') || !setKey.trim()}
            >
              {setBusy ? 'Saving...' : 'Save token'}
            </button>
          </div>
        </form>
      </div>
    );
  }

  if (isSearch) {
    const recent = historyRows.slice(0, 5);
    const quotaError = isSearchQuotaError(error);
    const nothingSrc = links.nothingAnimation || '/assets/animations/nothing.lottie';
    const searchAnimSrc = links.searchAnimation || '/assets/animations/Search.lottie';
    return (
      <div className="crm-desk-page crm-market-page crm-market-page--search">
        <form className="crm-market-filter-card" onSubmit={(e) => void onRunSearch(e)} autoComplete="off">
          <label className="crm-market-filter-field crm-market-filter-field--search">
            <span className="crm-market-filter-label">Search</span>
            <div className="crm-market-filter-search">
              <IconSearch />
              <input
                type="search"
                value={searchQ}
                onChange={(e) => {
                  setSearchQ(e.target.value);
                  setSuggestOpen(true);
                }}
                onFocus={() => {
                  if (suggestions.length) setSuggestOpen(true);
                }}
                onBlur={() => {
                  setTimeout(() => setSuggestOpen(false), 180);
                }}
                placeholder="Type a business or keyword..."
                aria-label="Search businesses"
                autoComplete="off"
              />
              <button type="submit" className="crm-market-filter-go" disabled={searchBusy} aria-label="Search">
                {searchBusy ? '...' : (
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                  </svg>
                )}
              </button>
              {suggestOpen && suggestions.length > 0 ? (
                <ul className="crm-market-suggest" role="listbox">
                  {suggestions.map((item, i) => (
                    <li key={`${item.label}-${i}`}>
                      <button
                        type="button"
                        className="crm-market-suggest-item"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => {
                          setSearchQ(item.label);
                          setSuggestOpen(false);
                          setSuggestions([]);
                        }}
                      >
                        <span>{item.label}</span>
                        {item.city || item.type ? (
                          <small>{[item.type, item.city].filter(Boolean).join(' | ')}</small>
                        ) : null}
                      </button>
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          </label>
          <div className="crm-market-filter-field">
            <span className="crm-market-filter-label">Location</span>
            <LocationSelect
              value={searchLocation}
              disabled={searchBusy}
              onChange={setSearchLocation}
            />
          </div>
        </form>

        {(message || (error && !quotaError)) && (
          <div className={`crm-market-flash ${error ? 'is-error' : 'is-ok'}`} role="status">
            {error || message}
          </div>
        )}

        <section className="crm-market-results">
          <div className="crm-market-results-head">
            <span className="crm-market-results-count">
              {quotaError
                ? 'Search unavailable'
                : searchBusy
                ? 'Searching...'
                : searchRows.length === 0
                ? 'Previous 5 searches'
                : `${searchRows.length} ${searchRows.length === 1 ? 'result' : 'results'}`}
            </span>
            {searchRows.length > 0 ? (
              <button
                type="button"
                className="crm-desk-btn crm-desk-btn-secondary"
                onClick={() => {
                  setSearchRows([]);
                  setMessage('');
                  setError('');
                }}
              >
                Previous searches
              </button>
            ) : null}
          </div>
          <div className="crm-desk-table-wrap crm-market-table-wrap">
            {searchBusy ? (
              <SearchBusyState src={searchAnimSrc} />
            ) : quotaError ? (
              <SearchQuotaState src={nothingSrc} message={error} />
            ) : searchRows.length === 0 ? (
              historyLoading ? (
                <div className="crm-market-empty"><h2>Loading saved searches...</h2></div>
              ) : recent.length === 0 ? (
                <NothingSummaryState
                  src={nothingSrc}
                  title="No previous searches yet"
                  copy="Enter a keyword and location, then Search."
                />
              ) : (
                <table className="crm-desk-table crm-market-table crm-market-history-table">
                  <thead>
                    <tr>
                      <th scope="col" className="crm-hist-no">No</th>
                      <th scope="col" className="crm-hist-when">When</th>
                      <th scope="col" className="crm-hist-search">Search</th>
                      <th scope="col" className="crm-hist-location">Location</th>
                      <th scope="col" className="crm-hist-results">Results</th>
                      <th scope="col" className="crm-hist-saved">Saved</th>
                      <th scope="col" className="crm-hist-stored">Already stored</th>
                      <th scope="col" className="crm-hist-col-actions"><span className="crm-sr-only">Actions</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    {recent.map((row, i) => (
                      <tr
                        key={row.id || i}
                        className="crm-market-history-row"
                        tabIndex={0}
                        onClick={() => void openSavedSearch(row)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            void openSavedSearch(row);
                          }
                        }}
                      >
                        <td className="crm-hist-no">{i + 1}</td>
                        <td className="crm-hist-when">{formatWhen(row.createdAt)}</td>
                        <td className="crm-hist-search">
                          <span className="crm-hist-search-text">{row.query || '-'}</span>
                        </td>
                        <td className="crm-hist-location">{row.location || '-'}</td>
                        <td className="crm-hist-results">{row.resultCount ?? 0}</td>
                        <td className="crm-hist-saved">{row.insertedCount ?? 0}</td>
                        <td className="crm-hist-stored">{row.skippedCount ?? 0}</td>
                        <td className="crm-hist-col-actions">
                          <button
                            type="button"
                            className="crm-hist-icon-btn"
                            aria-label="Download PDF"
                            disabled={busyId === `dl-${row.id}`}
                            onClick={(e) => void downloadSavedSearch(row, e)}
                          >
                            <IconPdf />
                          </button>
                          <button
                            type="button"
                            className="crm-hist-icon-btn crm-hist-trash"
                            aria-label="Delete saved search"
                            disabled={busyId === row.id}
                            onClick={(e) => void deleteSavedSearch(row, e)}
                          >
                            <IconTrash />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )
            ) : (
              <table className="crm-desk-table crm-market-table">
                <thead>
                  <tr>
                    <th scope="col">No</th>
                    <th scope="col">Name</th>
                    <th scope="col">Type</th>
                    <th scope="col">City</th>
                    <th scope="col">Phone</th>
                    <th scope="col">Assigned to</th>
                    <th scope="col">Website</th>
                    <th scope="col">Email</th>
                    <th scope="col">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {searchRows.map((lead, i) => {
                    const site = websiteHref(lead.website);
                    const mine = Boolean(lead.assigned_to_me) || (viewerId > 0 && Number(lead.assigned_to) === viewerId);
                    return (
                      <tr key={lead.id} className={`${lead.imported ? 'is-imported' : ''}${mine ? ' is-mine' : ''}`.trim()}>
                        <td>{i + 1}</td>
                        <td>
                          <div className="crm-market-name">
                            {mine ? <AssignedStar /> : null}
                            {lead.name || lead.username || '-'}
                          </div>
                        </td>
                        <td>{lead.category || '-'}</td>
                        <td>{lead.location || '-'}</td>
                        <td>{lead.phone || '-'}</td>
                        <td>
                          <span className={`crm-desk-badge crm-desk-badge-lead${mine ? ' is-mine' : ''}`}>
                            {mine ? 'You | ' : ''}
                            {lead.assigned_user_name && lead.assigned_user_name !== 'Unassigned'
                              ? lead.assigned_user_name
                              : (lead.assignedToName || 'Unassigned')}
                          </span>
                        </td>
                        <td>
                          {site ? (
                            <a className="crm-market-link" href={site} target="_blank" rel="noreferrer">
                              {String(lead.website).replace(/^https?:\/\//i, '')}
                            </a>
                          ) : '-'}
                        </td>
                        <td>{lead.email || '-'}</td>
                        <td>
                          {lead.imported ? (
                            <span className="crm-desk-badge crm-desk-badge-customer">In CRM</span>
                          ) : mine ? (
                            <button
                              type="button"
                              className="crm-desk-btn crm-desk-btn-secondary crm-market-import-btn"
                              disabled={customerSaving && customerLeadId === String(lead.id)}
                              onClick={() => openHistoryLeadAsCustomer(lead)}
                            >
                              {customerSaving && customerLeadId === String(lead.id) ? 'Opening...' : 'Add to CRM'}
                            </button>
                          ) : (
                            <span className="crm-desk-muted">-</span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </div>
        </section>
        <MarketCustomerModal
          open={customerModalOpen}
          title="Add customer"
          form={customerForm}
          options={formOptions}
          statuses={formStatuses}
          saving={customerSaving}
          error={customerError}
          onClose={closeCustomerModal}
          onSave={(e) => void saveHistoryLeadAsCustomer(e)}
          onFieldChange={updateCustomerField}
        />
      </div>
    );
  }

  if (isHistory || isNewLeads) {
    const openResults = async (row) => {
      const id = String(row?.id || '');
      setHistoryOpen(row);
      setHistoryBusy(true);
      setHistoryResults([]);
      // Optimistically clear the unread dot for this account only.
      if (id) {
        setHistoryRows((prev) =>
          prev.map((r) => (String(r.id) === id ? { ...r, viewed: true } : r))
        );
      }
      try {
        const data = await fetchMarketHistoryResults(id, historyMineOnly);
        setHistoryResults(Array.isArray(data.rows) ? data.rows : []);
      } catch (err) {
        setError(err.message || 'Could not load results.');
      } finally {
        setHistoryBusy(false);
      }
    };

    return (
      <div className="crm-desk-page crm-market-page">
        {(message || error) ? (
          <div className={`crm-market-flash ${error ? 'is-error' : 'is-ok'}`} role="status">
            {error || message}
          </div>
        ) : null}
        {historyOpen ? (
          <>
            <p className="crm-market-meta crm-market-history-toolbar">
              <button
                type="button"
                className="crm-hist-icon-btn"
                title="Back to saved searches"
                aria-label="Back to saved searches"
                onClick={() => { setHistoryOpen(null); setHistoryResults([]); setMessage(''); }}
              >
                <IconArrowLeft />
              </button>
              <button
                type="button"
                className="crm-hist-icon-btn"
                title="Download PDF"
                aria-label="Download PDF"
                disabled={!historyOpen?.id || busyId === `dl-${historyOpen.id}`}
                onClick={(e) => void downloadSavedSearch(historyOpen, e, historyMineOnly)}
              >
                <IconPdf />
              </button>
              <span className="crm-market-history-summary">
                {historyBusy
                  ? 'Loading results...'
                  : historyMineOnly
                    ? `${historyResults.length} assigned to you - ${historyOpen.query || '-'}`
                    : `${historyResults.length} assigned clients - ${historyOpen.query || '-'}`}
              </span>
            </p>
            <section className="crm-market-table-wrap">
              {historyBusy ? (
                <div className="crm-market-empty"><h2>Loading companies for this search...</h2></div>
              ) : historyResults.length === 0 ? (
                historyMineOnly ? (
                  <NothingAssignedState src={nothingSrc} query={historyOpen.query || ''} />
                ) : (
                  <div className="crm-market-empty">
                    <h2>No assigned clients for this search</h2>
                    <p>Run Search again or open another saved search.</p>
                  </div>
                )
              ) : (
                <table className="crm-desk-table crm-market-table crm-market-history-results">
                  <thead>
                    <tr>
                      <th className="crm-hr-no">No</th>
                      <th className="crm-hr-name">Name</th>
                      <th className="crm-hr-type">Type</th>
                      <th className="crm-hr-city">City</th>
                      <th className="crm-hr-phone">Phone</th>
                      <th className="crm-hr-assigned">Assigned to</th>
                      <th className="crm-hr-web">Website</th>
                      <th className="crm-hr-email">Email</th>
                      <th className="crm-hr-action">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {historyResults.map((r, i) => {
                      const rowMine = viewerId > 0 && Number(r.assigned_to ?? r.assignedTo) === viewerId;
                      const canAdd = historyMineOnly || rowMine;
                      return (
                      <tr key={r.id || i} className={!historyMineOnly && rowMine ? 'is-mine' : undefined}>
                        <td className="crm-hr-no">{i + 1}</td>
                        <td className="crm-hr-name" title={r.name || ''}>
                          <span className="crm-hr-clamp">{r.name || '-'}</span>
                        </td>
                        <td className="crm-hr-type" title={r.type || ''}>
                          <span className="crm-hr-clamp">{r.type || '-'}</span>
                        </td>
                        <td className="crm-hr-city">{r.city || '-'}</td>
                        <td className="crm-hr-phone">{r.phone || '-'}</td>
                        <td className="crm-hr-assigned">{r.assignedToName || r.assigned_user_name || 'Unassigned'}</td>
                        <td className="crm-hr-web">
                          {r.website ? (
                            <a href={String(r.website).startsWith('http') ? r.website : `https://${r.website}`} target="_blank" rel="noreferrer" title={String(r.website)}>
                              {String(r.website).replace(/^https?:\/\//i, '')}
                            </a>
                          ) : '-'}
                        </td>
                        <td className="crm-hr-email" title={r.email || ''}>{r.email || '-'}</td>
                        <td className="crm-hr-action">
                          {r.imported ? (
                            <span className="crm-desk-badge crm-desk-badge-customer">In CRM</span>
                          ) : canAdd ? (
                            <button
                              type="button"
                              className="crm-hist-icon-btn crm-hist-icon-btn--primary"
                              title="Add to customer"
                              aria-label={`Add ${r.name || 'lead'} to customer`}
                              disabled={customerSaving && customerLeadId === String(r.id)}
                              onClick={() => openHistoryLeadAsCustomer(r)}
                            >
                              <IconUserPlus />
                            </button>
                          ) : (
                            <span className="crm-desk-muted">-</span>
                          )}
                        </td>
                      </tr>
                      );
                    })}
                  </tbody>
                </table>
              )}
            </section>
          </>
        ) : (
          <section className="crm-market-table-wrap">
            {historyLoading ? (
              <div className="crm-market-empty"><h2>Loading saved searches...</h2></div>
            ) : historyRows.length === 0 ? (
              <NothingSummaryState
                src={nothingSrc}
                title="No saved searches yet"
                copy={
                  historyMineOnly
                    ? 'Run a Search in CRM Market. Assigned companies appear here for each sales person.'
                    : 'Run a Search in CRM Market. Open a saved search to see clients assigned across all sales users.'
                }
              />
            ) : (
              <table className="crm-desk-table crm-market-table crm-market-history-table">
                <thead>
                  <tr>
                    <th className="crm-hist-search">Search</th>
                    <th className="crm-hist-location">Location</th>
                    <th className="crm-hist-results">Results</th>
                    <th className="crm-hist-saved">{historyMineOnly ? 'Yours' : 'Assigned'}</th>
                    <th className="crm-hist-when">When</th>
                    <th className="crm-hist-col-actions">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {historyRows.map((row) => {
                    const rowId = String(row.id || '');
                    const isUnseen = rowId !== '' && row.viewed !== true;
                    return (
                    <tr
                      key={row.id}
                      className={`crm-market-history-row${isUnseen ? ' is-unseen' : ''}`}
                      tabIndex={0}
                      onClick={() => void openResults(row)}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                          e.preventDefault();
                          void openResults(row);
                        }
                      }}
                    >
                      <td className="crm-hist-search">
                        <span className="crm-hist-search-inner">
                          {isUnseen ? <span className="crm-hist-unseen-dot" aria-label="New" title="New" /> : null}
                          <span className="crm-hist-search-text">{row.query || '-'}</span>
                        </span>
                      </td>
                      <td className="crm-hist-location">{row.location || '-'}</td>
                      <td className="crm-hist-results">{row.resultCount ?? 0}</td>
                      <td className={`crm-hist-saved${historyMineOnly ? ' is-yours' : ''}`}>
                        {historyMineOnly
                          ? (row.assignedCount ?? 0)
                          : (row.totalAssignedCount ?? row.assignedCount ?? 0)}
                      </td>
                      <td className="crm-hist-when">{row.createdAt ? new Date(row.createdAt).toLocaleString() : '-'}</td>
                      <td className="crm-hist-col-actions">
                        <button
                          type="button"
                          className="crm-hist-icon-btn"
                          aria-label="Download PDF"
                          disabled={busyId === `dl-${row.id}`}
                          onClick={(e) => void downloadSavedSearch(row, e, historyMineOnly)}
                        >
                          <IconPdf />
                        </button>
                        <button
                          type="button"
                          className="crm-hist-icon-btn crm-hist-trash"
                          aria-label="Delete saved search"
                          disabled={busyId === row.id}
                          onClick={(e) => void deleteSavedSearch(row, e)}
                        >
                          <IconTrash />
                        </button>
                      </td>
                    </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </section>
        )}
        <MarketCustomerModal
          open={customerModalOpen}
          title="Add customer"
          form={customerForm}
          options={formOptions}
          statuses={formStatuses}
          saving={customerSaving}
          error={customerError}
          onClose={closeCustomerModal}
          onSave={(e) => void saveHistoryLeadAsCustomer(e)}
          onFieldChange={updateCustomerField}
        />
      </div>
    );
  }

  return (
    <div className="crm-desk-page crm-market-page">
      <header className="crm-market-hero crm-market-hero--compact">
        <div>
          <p className="crm-market-eyebrow">CRM Market</p>
          <h1 className="crm-market-title">Import to CRM</h1>
        </div>
        <div className="crm-market-actions">
          <a href={customersUrl} className="crm-desk-btn crm-desk-btn-secondary">
            My Customers
          </a>
        </div>
      </header>

      <section className="crm-market-status" aria-live="polite">
        <div className={`crm-market-pill ${status.connected ? 'is-ok' : 'is-warn'}`}>
          {status.connected ? 'Database connected' : 'Setup needed'}
        </div>
        <p>{status.message || 'Loading...'}</p>
        <p className="crm-market-meta">
          {Number(status.lead_count || 0)} leads ready to import
          {status.database ? ` | ${status.database}` : ''}
        </p>
      </section>

      {(message || error) && (
        <div className={`crm-market-flash ${error ? 'is-error' : 'is-ok'}`} role="status">
          {error || message}
        </div>
      )}

      <section className="crm-market-toolbar">
        <form
          className="crm-desk-search"
          onSubmit={(e) => {
            e.preventDefault();
            refresh(search);
          }}
        >
          <IconSearch />
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search name, @username, email, location..."
            aria-label="Search market leads"
          />
        </form>
        <div className="crm-market-toolbar-actions">
          <button type="button" className="crm-desk-btn crm-desk-btn-secondary" onClick={() => refresh()}>
            Refresh
          </button>
          <button
            type="button"
            className="crm-desk-btn crm-desk-btn-primary"
            disabled={bulkBusy || selectedIds.length === 0}
            onClick={onImportSelected}
          >
            {bulkBusy ? 'Importing...' : `Import selected (${selectedIds.length})`}
          </button>
        </div>
      </section>

      <section className="crm-market-table-wrap">
        {leads.length === 0 ? (
          <div className="crm-market-empty">
            <h2>No leads yet</h2>
            <p>Use Search from the ERP sidebar, then return here to import into My Customers.</p>
          </div>
        ) : (
          <table className="crm-desk-table crm-market-table">
            <thead>
              <tr>
                <th scope="col" className="crm-market-check">
                  <input
                    type="checkbox"
                    aria-label="Select all importable leads"
                    onChange={toggleAll}
                    checked={
                      leads.some((l) => !l.imported) &&
                      leads.filter((l) => !l.imported).every((l) => selected[l.id])
                    }
                  />
                </th>
                <th scope="col">Prospect</th>
                <th scope="col">Category</th>
                <th scope="col">Location</th>
                <th scope="col">Assigned to</th>
                <th scope="col">Score</th>
                <th scope="col">Contact</th>
                <th scope="col">Status</th>
                <th scope="col">Action</th>
              </tr>
            </thead>
            <tbody>
              {leads.map((lead) => (
                <tr key={lead.id} className={lead.imported ? 'is-imported' : ''}>
                  <td className="crm-market-check">
                    <input
                      type="checkbox"
                      disabled={!!lead.imported}
                      checked={!!selected[lead.id]}
                      onChange={() => toggleOne(lead.id)}
                      aria-label={`Select ${lead.name || lead.username}`}
                    />
                  </td>
                  <td>
                    <div className="crm-market-name">{lead.name || lead.username || '-'}</div>
                    {lead.username ? <div className="crm-market-sub">@{lead.username}</div> : null}
                    {lead.source ? <div className="crm-market-sub">{lead.source}</div> : null}
                  </td>
                  <td>{lead.category || '-'}</td>
                  <td>{lead.location || '-'}</td>
                  <td>
                    <span className="crm-desk-badge crm-desk-badge-lead">
                      {lead.assigned_user_name || 'Unassigned'}
                    </span>
                  </td>
                  <td>
                    <span className="crm-market-score">{lead.score ?? 0}</span>
                    {lead.level ? <span className="crm-market-sub"> {lead.level}</span> : null}
                  </td>
                  <td>
                    <div className="crm-market-sub">{lead.email || '-'}</div>
                    <div className="crm-market-sub">{lead.phone || ''}</div>
                  </td>
                  <td>
                    {lead.imported ? (
                      <span className="crm-desk-badge crm-desk-badge-customer">In CRM</span>
                    ) : (
                      <span className="crm-desk-badge crm-desk-badge-lead">New</span>
                    )}
                  </td>
                  <td>
                    {lead.imported ? (
                      <span className="crm-market-sub">Imported</span>
                    ) : (
                      <button
                        type="button"
                        className="crm-desk-btn crm-desk-btn-secondary crm-market-import-btn"
                        disabled={busyId === lead.id}
                        onClick={() => onImportOne(lead)}
                      >
                        {busyId === lead.id ? 'Adding...' : 'Add to CRM'}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </div>
  );
}
