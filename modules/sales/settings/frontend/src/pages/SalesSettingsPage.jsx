import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import {
  BadgeCheck,
  Calculator,
  ChevronDown,
  CircleDollarSign,
  FileText,
  LayoutTemplate,
  Loader2,
  MessageSquareText,
  Package,
  Settings2,
  Target,
  Truck,
} from 'lucide-react';
import { fetchMonthlyTargets, fetchSettingsInit, saveMonthlyTargets, saveSettings, saveSettingsFields } from '../api/settingsDesk';

const TAB_ICONS = {
  financials: CircleDollarSign,
  targets: Target,
  settings: LayoutTemplate,
  truck: Truck,
  spare: Package,
};

const TAB_ICON_TONES = {
  financials: 'finance',
  targets: 'target',
  settings: 'layout',
  truck: 'truck',
  spare: 'spare',
};

function TabIcon({ tabKey }) {
  const Icon = TAB_ICONS[tabKey] || Settings2;
  const tone = TAB_ICON_TONES[tabKey] || 'settings';
  return <Icon size={14} className={`ss-tab-icon ss-icon-tone ss-icon-tone--${tone}`} aria-hidden="true" />;
}

function SectionTitle({ icon: Icon, tone = 'settings', children }) {
  return (
    <h3 className="ss-form-block-title ss-form-block-title--with-icon">
      {Icon ? (
        <Icon size={14} className={`ss-section-icon ss-icon-tone ss-icon-tone--${tone}`} aria-hidden="true" />
      ) : null}
      <span>{children}</span>
    </h3>
  );
}

function LayoutCardSkeleton() {
  return (
    <div className="ss-layout-card ss-layout-card--skeleton" aria-hidden="true">
      <div className="ss-layout-thumb ss-skeleton-block" />
      <div className="ss-layout-card-body">
        <div className="ss-skeleton-line ss-skeleton-line--title" />
        <div className="ss-skeleton-line" />
        <div className="ss-skeleton-line ss-skeleton-line--short" />
      </div>
    </div>
  );
}

function DocumentLayoutPageSkeleton() {
  return (
    <div className="exp-create-shell ss-settings-shell" aria-busy="true" aria-live="polite">
      <div className="ss-hero-card" aria-hidden="true">
        <header className="ss-page-header">
          <div className="ss-skeleton-line ss-skeleton-line--heading" />
          <div className="ss-skeleton-line ss-skeleton-line--sub" />
        </header>
        <nav className="ss-settings-nav">
          <ul>
            <li><span className="ss-skeleton-nav" /></li>
            <li><span className="ss-skeleton-nav" /></li>
            <li><span className="ss-skeleton-nav ss-skeleton-nav--wide" /></li>
          </ul>
        </nav>
      </div>
      <div className="exp-create-main">
        <section className="exp-create-section ss-form-section">
          <div className="ss-layout-workspace ss-layout-workspace--single">
            <div className="ss-layout-workspace-main">
              <div className="ss-card">
                <div className="ss-skeleton-line ss-skeleton-line--title" style={{ marginBottom: '0.85rem' }} />
                <div className="ss-layout-cards">
                  {Array.from({ length: 8 }, (_, index) => (
                    <LayoutCardSkeleton key={index} />
                  ))}
                </div>
              </div>
              <div className="ss-card">
                <div className="ss-skeleton-line ss-skeleton-line--title" style={{ marginBottom: '0.85rem' }} />
                <div className="ss-form-stack">
                  <div className="ss-skeleton-block ss-skeleton-block--field" />
                  <div className="ss-skeleton-block ss-skeleton-block--field" />
                  <div className="ss-form-grid">
                    <div className="ss-skeleton-block ss-skeleton-block--input" />
                    <div className="ss-skeleton-block ss-skeleton-block--input" />
                    <div className="ss-skeleton-block ss-skeleton-block--input ss-skeleton-block--full" />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}

const ROADMASTER_TABS = [
  { key: 'financials', label: 'Tax & Finance' },
  { key: 'truck', label: 'Truck Layout' },
  { key: 'spare', label: 'Spare Layout' },
];

function tabFromLocation() {
  if (typeof window === 'undefined') return 'financials';
  const tab = new URLSearchParams(window.location.search).get('tab');
  if (tab === 'targets') return 'targets';
  if (tab === 'settings' || tab === 'layout') return 'settings';
  if (tab === 'truck') return 'truck';
  if (tab === 'spare') return 'spare';
  return 'financials';
}

function pageSubtitleForTab(activeTab, init) {
  if (activeTab === 'settings' || activeTab === 'truck' || activeTab === 'spare') {
    return 'Manage how your quotations and invoices look when printed or exported to PDF.';
  }
  if (activeTab === 'targets') {
    return 'Set monthly and yearly sales targets for your team.';
  }
  if (activeTab === 'financials') {
    return init?.is_ultimate
      ? 'Manage tax, currency, and document defaults for your company.'
      : 'VAT, currency, and payment details for quotations and invoices.';
  }
  return 'Manage sales settings for your company.';
}

function syncTabInUrl(tabKey) {
  const url = new URL(window.location.href);
  if (tabKey === 'financials') {
    url.searchParams.delete('tab');
  } else {
    url.searchParams.set('tab', tabKey);
  }
  window.history.replaceState({}, '', url);
}

function getSettingsTabs(init) {
  const targets = { key: 'targets', label: 'Sales target' };
  if (init?.is_ultimate) {
    return [
      { key: 'financials', label: 'Tax & Finance' },
      targets,
      { key: 'settings', label: 'Document Layout' },
    ];
  }

  return [...ROADMASTER_TABS, targets];
}

function yearlyFromMonthly(amount) {
  const value = Number(String(amount ?? '').replace(/,/g, '').trim());
  if (!Number.isFinite(value) || value <= 0) return '';
  const yearly = Math.round(value * 12 * 100) / 100;
  return String(yearly).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
}

function withYearlyDefaults(data) {
  const people = Array.isArray(data?.people) ? data.people : [];
  return {
    sharedYearly: '',
    people: people.map((person) => ({
      ...person,
      yearly_amount: '',
    })),
  };
}

function formatTargetMoney(amount, currency) {
  const value = Number(amount);
  if (!Number.isFinite(value) || value <= 0) return '';
  const formatted = new Intl.NumberFormat('en-TZ', { maximumFractionDigits: 0 }).format(value);
  return `${formatted} ${currency || 'TZS'}`;
}

function getLayoutTabConfig(activeTab) {
  if (activeTab === 'settings') {
    return {
      layoutField: 'spare_part_layout',
      fieldPrefix: 'spare',
      previewType: 'spare',
      catalogKey: 'ultimate',
      sectionTitle: 'Sales Settings',
      sectionDescription: 'Manage how your quotations and invoices look when printed or exported to PDF.',
      chooseTitle: 'Choose Document Layout',
      footerTitle: 'Document Footer Content',
      footerDescription: 'This content will be printed at the bottom of your quotations and invoices.',
      documentLabel: 'Ultimate',
      saveLabel: 'Document',
      showTruckRemarks: false,
      hideLayoutPicker: false,
    };
  }

  if (activeTab === 'truck') {
    return {
      layoutField: 'truck_layout',
      fieldPrefix: 'truck',
      previewType: 'truck',
      catalogKey: 'truck',
      sectionTitle: 'Sales Settings',
      sectionDescription: 'Manage how truck quotations and invoices look when printed or exported to PDF.',
      chooseTitle: 'Choose Truck Document Layout',
      footerTitle: 'Document Footer Content',
      footerDescription: 'This content will be printed at the bottom of your truck quotations and invoices.',
      documentLabel: 'truck',
      saveLabel: 'Truck',
      showTruckRemarks: true,
    };
  }

  return {
    layoutField: 'spare_part_layout',
    fieldPrefix: 'spare',
    previewType: 'spare',
    catalogKey: 'spare',
    sectionTitle: 'Sales Settings',
    sectionDescription: 'Manage how spare part quotations and invoices look when printed or exported to PDF.',
    chooseTitle: 'Choose Spare Document Layout',
    footerTitle: 'Document Footer Content',
    footerDescription: 'This content will be printed at the bottom of your spare part quotations and invoices.',
    documentLabel: 'spare part',
    saveLabel: 'Spare',
    showTruckRemarks: false,
  };
}

function isLayoutSettingsTab(activeTab) {
  return activeTab === 'truck' || activeTab === 'spare' || activeTab === 'settings';
}

function showToast(message, icon = 'success') {
  if (typeof window !== 'undefined' && window.Swal) {
    window.Swal.fire({
      toast: true,
      position: 'top-end',
      icon,
      title: message,
      showConfirmButton: false,
      timer: 3000,
      timerProgressBar: true,
    });
  }
}

const LIBRARY_LAYOUT_LABELS = {
  11: 'Purple Classic',
  12: 'Purple Brand Name',
  13: 'Navy & Orange Swoosh',
  14: 'Pixel Mart Red & Black',
  15: 'Yellow Bar Classic',
};

function layoutLabel(activeTab, id) {
  if (LIBRARY_LAYOUT_LABELS[id]) {
    return LIBRARY_LAYOUT_LABELS[id];
  }
  if (activeTab === 'truck') {
    if (id === 2) return 'Classic';
    if (id === 3) return 'Minimalist';
    return 'Watermark';
  }
  if (activeTab === 'settings' || activeTab === 'ultimate') {
    if (id === 2) return 'Classic';
    if (id === 3) return 'Minimalist';
    if (id === 4) return 'Formal';
    return 'Standard';
  }
  if (id === 1) return 'Premium';
  if (id === 2) return 'Classic';
  return 'Minimalist';
}

function getLayoutOptions(init, catalogKey) {
  const entries = init?.layouts?.[catalogKey];
  if (Array.isArray(entries) && entries.length > 0) {
    return entries;
  }
  const fallbackIds = catalogKey === 'truck' ? [1, 2, 3] : [1, 2, 3];
  return fallbackIds.map((id) => ({
    id,
    label: layoutLabel(catalogKey === 'truck' ? 'truck' : 'spare', id),
    description: '',
    comingSoon: false,
  }));
}

function getLayoutMeta(init, catalogKey, layoutId) {
  const options = getLayoutOptions(init, catalogKey);
  const match = options.find((entry) => Number(entry.id) === Number(layoutId));
  if (match) return match;
  return {
    id: layoutId,
    label: layoutLabel(catalogKey === 'truck' ? 'truck' : 'spare', layoutId),
    description: '',
  };
}

function buildLayoutPreviewUrl(init, previewType, layoutId, mode = '') {
  if (!init?.urls?.layoutPreview) return '';
  const params = new URLSearchParams();
  const module = new URLSearchParams(window.location.search).get('module');
  if (module) params.set('module', module);
  params.set('type', previewType);
  params.set('layout', String(layoutId));
  if (mode === 'card') params.set('card', '1');
  if (mode === 'modal') params.set('modal', '1');
  if (mode === 'full') params.set('full', '1');
  return `${init.urls.layoutPreview}?${params.toString()}`;
}

function LayoutThumb({ init, previewType, layoutId, label, comingSoon }) {
  const [loaded, setLoaded] = useState(false);
  const previewUrl = !comingSoon
    ? buildLayoutPreviewUrl(init, previewType, layoutId, 'card')
    : '';

  useEffect(() => {
    setLoaded(false);
  }, [previewUrl]);

  if (!previewUrl) {
    return (
      <div className="ss-layout-thumb ss-layout-thumb--empty" aria-hidden="true">
        <span className="ss-layout-thumb-empty-label">{comingSoon ? 'Coming soon' : 'Preview'}</span>
      </div>
    );
  }

  return (
    <div className={`ss-layout-thumb ss-layout-thumb--live${loaded ? ' is-loaded' : ''}`} aria-hidden="true">
      {!loaded ? (
        <div className="ss-layout-thumb-skeleton" aria-hidden="true">
          <span className="ss-skeleton-line ss-skeleton-line--thumb-top" />
          <span className="ss-skeleton-line ss-skeleton-line--thumb-mid" />
          <span className="ss-skeleton-line ss-skeleton-line--thumb-mid ss-skeleton-line--short" />
          <span className="ss-skeleton-block ss-skeleton-block--thumb-table" />
          <span className="ss-skeleton-block ss-skeleton-block--thumb-total" />
        </div>
      ) : null}
      <iframe
        className={`ss-layout-thumb-frame${loaded ? ' is-visible' : ''}`}
        src={previewUrl}
        title={`${label} layout preview`}
        loading="lazy"
        tabIndex={-1}
        onLoad={() => setLoaded(true)}
      />
    </div>
  );
}

function TaxToggleRow({ title, subtitle, enabled, onChange }) {
  const isOn = Number(enabled) === 1;

  return (
    <div className="ss-tax-option">
      <div className="ss-tax-option-copy">
        <div className="ss-tax-option-title">{title}</div>
        <div className="ss-tax-option-desc">{subtitle}</div>
      </div>
      <button
        type="button"
        className={`ss-switch ss-switch--pill${isOn ? ' is-on' : ''}`}
        role="switch"
        aria-checked={isOn}
        aria-label={`${title}: ${isOn ? 'On' : 'Off'}`}
        onClick={() => onChange(isOn ? 0 : 1)}
      >
        <span className="ss-switch-thumb" aria-hidden="true" />
      </button>
    </div>
  );
}

const FONT_STACK_FALLBACKS = {
  dm_sans: "'DM Sans', system-ui, -apple-system, 'Segoe UI', sans-serif",
  poppins: "'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
  arima: "'Arima', Arial, 'Helvetica Neue', Helvetica, sans-serif",
  inter: "'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif",
  outfit: "'Outfit', system-ui, -apple-system, sans-serif",
  roboto: "'Roboto', system-ui, -apple-system, sans-serif",
  open_sans: "'Open Sans', system-ui, -apple-system, sans-serif",
  lato: "'Lato', system-ui, -apple-system, sans-serif",
  montserrat: "'Montserrat', system-ui, -apple-system, sans-serif",
  nunito_sans: "'Nunito Sans', system-ui, -apple-system, sans-serif",
  source_sans_3: "'Source Sans 3', system-ui, -apple-system, sans-serif",
  raleway: "'Raleway', system-ui, -apple-system, sans-serif",
  ubuntu: "'Ubuntu', system-ui, -apple-system, sans-serif",
  merriweather: "'Merriweather', Georgia, 'Times New Roman', serif",
  playfair_display: "'Playfair Display', Georgia, 'Times New Roman', serif",
  system_ui: "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
};

function resolveFontStack(fontOrKey, init = null) {
  if (fontOrKey && typeof fontOrKey === 'object') {
    if (fontOrKey.stack) return String(fontOrKey.stack);
    return FONT_STACK_FALLBACKS[fontOrKey.key] || 'system-ui, sans-serif';
  }
  const key = String(fontOrKey || '');
  const match = init?.fontCatalog?.find((entry) => entry.key === key);
  if (match?.stack) return String(match.stack);
  return FONT_STACK_FALLBACKS[key] || 'system-ui, sans-serif';
}

function getSelectedFontStack(init, fontKey) {
  return resolveFontStack(fontKey, init);
}

function ensureFontStylesheet(href, marker) {
  if (!href || typeof document === 'undefined') return null;
  const safeMarker = String(marker).replace(/["\\]/g, '');
  const existing = document.querySelector(`link[data-ss-font="${safeMarker}"]`);
  if (existing) return existing;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  link.dataset.ssFont = marker;
  document.head.appendChild(link);
  return link;
}

function useDocumentFontCatalog(init) {
  useEffect(() => {
    if (!init?.fontCatalog?.length) return undefined;

    const loaders = [];
    init.fontCatalog.forEach((font) => {
      if (font.localCss) {
        ensureFontStylesheet(font.localCss, `local-${font.key}`);
      }
      if (font.google) {
        ensureFontStylesheet(font.google, `google-${font.key}`);
      }
      const family = String(font.stack || '').split(',')[0].replace(/['"]/g, '').trim();
      if (family && document.fonts?.load) {
        loaders.push(document.fonts.load(`400 16px "${family}"`).catch(() => null));
        loaders.push(document.fonts.load(`700 16px "${family}"`).catch(() => null));
      }
    });

    // Inject after erp-system-font-final so each option face wins.
    let style = document.getElementById('ss-font-select-override');
    if (!style) {
      style = document.createElement('style');
      style.id = 'ss-font-select-override';
      document.body.appendChild(style);
    }
    const rules = init.fontCatalog.map((font) => {
      const stack = String(font.stack || 'system-ui, sans-serif').replace(/;/g, '');
      const key = String(font.key || '').replace(/[^a-z0-9_-]/gi, '');
      if (!key) return '';
      return [
        `html body .ss-font-select-value[data-font="${key}"]`,
        `html body .ss-font-select-option[data-font="${key}"]`,
        `html body .ss-font-select-option[data-font="${key}"] .ss-font-select-option-label`,
        `html body button.ss-font-select-option[data-font="${key}"]`,
        `html body.dashboard .ss-font-select-value[data-font="${key}"]`,
        `html body.dashboard button.ss-font-select-option[data-font="${key}"]`,
        `html body.dashboard button.ss-font-select-option[data-font="${key}"] .ss-font-select-option-label`,
        `html body.page-exp-desk button.ss-font-select-option[data-font="${key}"]`,
        `html body.page-exp-desk button.ss-font-select-option[data-font="${key}"] .ss-font-select-option-label`,
      ].join(',') + `{font-family:${stack}!important;}`;
    }).filter(Boolean).join('\n');
    style.textContent = rules;

    Promise.all(loaders).then(() => {
      document.dispatchEvent(new CustomEvent('ss-fonts-ready'));
    });

    return undefined;
  }, [init]);
}

function applyImportantFont(node, stack) {
  if (!node || !stack) return;
  node.style.setProperty('font-family', stack, 'important');
}

function FontSelect({ id, name, value, fonts, onChange }) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);
  const valueRef = useRef(null);
  const optionRefs = useRef({});
  const selected = fonts.find((font) => font.key === value) || fonts[0] || null;
  const selectedStack = resolveFontStack(selected || value);

  useLayoutEffect(() => {
    const paint = () => {
      applyImportantFont(valueRef.current, selectedStack);
      fonts.forEach((font) => {
        applyImportantFont(optionRefs.current[font.key], resolveFontStack(font));
      });
    };
    paint();
    document.addEventListener('ss-fonts-ready', paint);
    return () => document.removeEventListener('ss-fonts-ready', paint);
  }, [fonts, open, selectedStack, value]);

  useEffect(() => {
    if (!open) return undefined;
    const onPointerDown = (event) => {
      if (rootRef.current && !rootRef.current.contains(event.target)) {
        setOpen(false);
      }
    };
    const onKeyDown = (event) => {
      if (event.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  return (
    <div className={`ss-font-select${open ? ' is-open' : ''}`} ref={rootRef}>
      <input type="hidden" id={id} name={name} value={value || ''} />
      <button
        type="button"
        className="ss-font-select-trigger ss-field-input"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label="Document font"
        onClick={() => setOpen((prev) => !prev)}
      >
        <span
          ref={valueRef}
          className="ss-font-select-value"
          data-font={selected?.key || ''}
          style={{ '--ss-option-font': selectedStack }}
        >
          {selected?.label || 'Select font'}
        </span>
        <ChevronDown size={16} aria-hidden="true" />
      </button>
      {open ? (
        <ul className="ss-font-select-menu" role="listbox" aria-label="Document fonts">
          {fonts.map((font) => {
            const isSelected = font.key === value;
            const stack = resolveFontStack(font);
            return (
              <li key={font.key} role="option" aria-selected={isSelected}>
                <button
                  type="button"
                  ref={(node) => {
                    if (node) {
                      optionRefs.current[font.key] = node;
                      applyImportantFont(node, stack);
                    } else {
                      delete optionRefs.current[font.key];
                    }
                  }}
                  className={`ss-font-select-option${isSelected ? ' is-selected' : ''}`}
                  data-font={font.key}
                  style={{ '--ss-option-font': stack }}
                  onClick={() => {
                    onChange({ target: { name, value: font.key } });
                    setOpen(false);
                  }}
                >
                  <span
                    className="ss-font-select-option-label"
                    ref={(node) => applyImportantFont(node, stack)}
                    style={{ '--ss-option-font': stack }}
                  >
                    {font.label}
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}

export default function SalesSettingsPage() {
  const [init, setInit] = useState(null);
  const [settings, setSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [activeTab, setActiveTab] = useState(tabFromLocation);
  const [saving, setSaving] = useState(false);
  const [layoutTeaser, setLayoutTeaser] = useState(null);
  const [layoutPreview, setLayoutPreview] = useState(null);
  const [targetMonth, setTargetMonth] = useState('');
  const [targetScope, setTargetScope] = useState('ongoing');
  const [targetMode, setTargetMode] = useState('all');
  const [sharedAmount, setSharedAmount] = useState('');
  const [ongoingShared, setOngoingShared] = useState('');
  const [yearlyShared, setYearlyShared] = useState('');
  const [targetPeople, setTargetPeople] = useState([]);
  const [targetsLoading, setTargetsLoading] = useState(false);
  const [targetsSaving, setTargetsSaving] = useState(false);

  useDocumentFontCatalog(init);

  useEffect(() => {
    let cancelled = false;

    fetchSettingsInit()
      .then((data) => {
        if (cancelled) return;
        setInit(data);
        setSettings(data.settings || {});
        setTargetMonth(data.monthlyTargets?.month || '');
        setSharedAmount(data.monthlyTargets?.shared_amount || '');
        setOngoingShared(data.monthlyTargets?.ongoing_shared || '');
        const seeded = withYearlyDefaults(data.monthlyTargets);
        setYearlyShared(seeded.sharedYearly);
        setTargetMode('all');
        setTargetScope('ongoing');
        setTargetPeople(seeded.people);
        setLoading(false);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(err.message || 'Failed to load settings.');
        showToast('Failed to load settings. Please check your connection or login status.', 'error');
        setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  function handleInputChange(event) {
    const { name, value } = event.target;
    setSettings((prev) => ({ ...prev, [name]: value }));
  }

  async function handleLayoutChange(type, layoutId) {
    const layoutConfig = getLayoutTabConfig(type);
    const field = layoutConfig.layoutField;
    setSettings((prev) => ({ ...prev, [field]: layoutId }));

    try {
      await saveSettingsFields(init.urls.save, { [field]: layoutId });
      showToast(`${layoutConfig.saveLabel} layout updated`);
    } catch (err) {
      showToast(err.message || 'Connection error', 'error');
    }
  }

  function handleLayoutSelect(type, option) {
    if (option.comingSoon) {
      setLayoutTeaser({ id: option.id, label: option.label });
      setLayoutPreview(null);
      return;
    }

    setLayoutTeaser(null);
    const tabConfig = getLayoutTabConfig(type);
    setLayoutPreview({
      id: option.id,
      label: option.label,
      description: option.description || '',
      tab: type,
      previewType: tabConfig.previewType,
      documentLabel: tabConfig.documentLabel,
      previewUrl: buildLayoutPreviewUrl(init, tabConfig.previewType, option.id, 'modal'),
    });
  }

  function closeLayoutPreview() {
    setLayoutPreview(null);
  }

  function useLayoutFromPreview() {
    if (!layoutPreview) return;
    handleLayoutChange(layoutPreview.tab, layoutPreview.id);
    setLayoutPreview(null);
  }

  useEffect(() => {
    if (!layoutPreview) return undefined;

    function onKeyDown(event) {
      if (event.key === 'Escape') {
        setLayoutPreview(null);
      }
    }

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKeyDown);

    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', onKeyDown);
    };
  }, [layoutPreview]);

  async function changeTargetMonth(month) {
    setTargetMonth(month);
    setTargetsLoading(true);
    try {
      const data = await fetchMonthlyTargets(month);
      setTargetMonth(data.month || month);
      setSharedAmount(data.shared_amount || '');
      setOngoingShared(data.ongoing_shared || '');
      const seeded = withYearlyDefaults(data);
      setYearlyShared(seeded.sharedYearly);
      setTargetPeople(seeded.people);
    } catch (err) {
      showToast(err.message || 'Could not load targets', 'error');
    } finally {
      setTargetsLoading(false);
    }
  }

  function scopePersonAmount(person) {
    if (targetScope === 'ongoing') return person.ongoing_amount || '';
    if (targetScope === 'year') return person.yearly_amount || '';
    return person.amount || '';
  }

  function changeTargetAmount(userId, amount) {
    const field = targetScope === 'ongoing' ? 'ongoing_amount' : 'amount';
    setTargetPeople((prev) => prev.map((person) => (
      person.id === userId
        ? { ...person, [field]: amount, yearly_amount: yearlyFromMonthly(amount) }
        : person
    )));
  }

  function changePersonYearly(userId, amount) {
    setTargetPeople((prev) => prev.map((person) => (
      person.id === userId ? { ...person, yearly_amount: amount } : person
    )));
  }

  async function saveTargets() {
    if (!targetMonth) return;
    const activeShared = targetScope === 'ongoing' ? ongoingShared : (targetScope === 'year' ? yearlyShared : sharedAmount);
    const activePeople = targetPeople.map((person) => ({
      id: person.id,
      amount: scopePersonAmount(person),
      yearly_amount: person.yearly_amount || '',
    }));
    setTargetsSaving(true);
    try {
      const data = await saveMonthlyTargets(targetMonth, activePeople, targetMode, activeShared, targetScope, yearlyShared);
      setTargetMonth(data.month || targetMonth);
      setSharedAmount(data.shared_amount || '');
      setOngoingShared(data.ongoing_shared || '');
      setTargetPeople((current) => withYearlyDefaults(data).people.map((person) => {
        const previous = current.find((item) => item.id === person.id);
        return { ...person, yearly_amount: previous?.yearly_amount || '' };
      }));
      showToast('Sales target saved');
    } catch (err) {
      showToast(err.message || 'Could not save targets', 'error');
    } finally {
      setTargetsSaving(false);
    }
  }

  async function saveAll(event) {
    event?.preventDefault?.();
    if (!init?.urls?.save || !settings) return;

    setSaving(true);
    const formData = new FormData();
    Object.entries(settings).forEach(([key, value]) => {
      if (value !== null && value !== undefined) {
        formData.append(key, String(value));
      }
    });

    try {
      await saveSettings(init.urls.save, formData);
      showToast('Settings saved successfully');
    } catch (err) {
      showToast(err.message || 'Server error', 'error');
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <DocumentLayoutPageSkeleton />;
  }

  if (error || !settings || !init) {
    return (
      <div className="exp-create-shell">
        <div className="exp-create-alert exp-create-alert--error" role="alert">
          {error || 'Could not load sales settings.'}
        </div>
      </div>
    );
  }

  const settingsTabs = getSettingsTabs(init);
  const layoutConfig = isLayoutSettingsTab(activeTab) ? getLayoutTabConfig(activeTab) : null;
  const layoutOptions = layoutConfig ? getLayoutOptions(init, layoutConfig.catalogKey) : [];
  const selectedLayoutId = layoutConfig
    ? Number(layoutConfig.fixedLayoutId ?? settings[layoutConfig.layoutField]) || Number(layoutOptions[0]?.id) || 1
    : 1;
  const selectedLayout = layoutConfig
    ? getLayoutMeta(init, layoutConfig.catalogKey, selectedLayoutId)
    : null;
  const fieldPrefix = layoutConfig?.fieldPrefix || activeTab;
  const pageSubtitle = pageSubtitleForTab(activeTab, init);

  return (
    <div className="exp-create-shell ss-settings-shell">
      <div className="ss-hero-card">
        <header className="ss-page-header">
          <h1 className="ss-page-title">
            <Settings2 size={20} className="ss-page-title-icon ss-icon-tone ss-icon-tone--settings" aria-hidden="true" />
            <span>Sales Settings</span>
          </h1>
          <p className="ss-page-subtitle">{pageSubtitle}</p>
        </header>

        <nav className="ss-settings-nav" aria-label="Settings sections">
          <ul>
            {settingsTabs.map((tab) => (
              <li key={tab.key}>
                <button
                  type="button"
                  className={activeTab === tab.key ? 'is-active' : ''}
                  onClick={() => {
                    setActiveTab(tab.key);
                    setLayoutTeaser(null);
                    setLayoutPreview(null);
                    syncTabInUrl(tab.key);
                  }}
                >
                  <TabIcon tabKey={tab.key} />
                  <span>{tab.label}</span>
                </button>
              </li>
            ))}
          </ul>
        </nav>
      </div>

      <form className="ss-settings-form" onSubmit={saveAll}>
        <div className="exp-create-main">
          {activeTab === 'financials' && (
            <section className="exp-create-section ss-form-section ss-doc-layout-page" id="settings-financials">
              <div className="ss-doc-block ss-doc-block--financials">
                <SectionTitle icon={BadgeCheck} tone="register">Registration</SectionTitle>
                <div className="ss-form-grid">
                  <div className="ss-field">
                    <label className="ss-field-label" htmlFor="company_tin">TIN</label>
                    <input
                      id="company_tin"
                      name="company_tin"
                      className="ss-field-input"
                      value={settings.company_tin || ''}
                      onChange={handleInputChange}
                      placeholder="e.g. 156-585-246"
                      autoComplete="off"
                    />
                  </div>
                  <div className="ss-field">
                    <label className="ss-field-label" htmlFor="company_vat">VRN / VAT registration</label>
                    <input
                      id="company_vat"
                      name="company_vat"
                      className="ss-field-input"
                      value={settings.company_vat || ''}
                      onChange={handleInputChange}
                      placeholder="e.g. 40-048025-L"
                      autoComplete="off"
                    />
                  </div>
                  <div className="ss-field">
                    <label className="ss-field-label" htmlFor="default_currency">Primary currency</label>
                    <input
                      id="default_currency"
                      name="default_currency"
                      className="ss-field-input"
                      value={settings.default_currency || ''}
                      onChange={handleInputChange}
                      placeholder="TZS"
                      autoComplete="off"
                    />
                  </div>
                  <div className="ss-field">
                    <label className="ss-field-label" htmlFor="sales_document_font">Document font</label>
                    <FontSelect
                      id="sales_document_font"
                      name="sales_document_font"
                      value={settings.sales_document_font || 'arima'}
                      fonts={init.fontCatalog || []}
                      onChange={handleInputChange}
                    />
                    <p className="ss-field-help">Used when printing or exporting PDF.</p>
                  </div>
                </div>
              </div>

              <div className="ss-doc-block">
                <div className="ss-doc-block-head">
                  <SectionTitle icon={Calculator} tone="tax">Tax calculation</SectionTitle>
                  <p className="ss-doc-block-sub">Choose how VAT is calculated on quotations and invoices.</p>
                </div>
                <div className="ss-tax-options">
                  <TaxToggleRow
                    title="Tax inclusive"
                    subtitle="Calculate tax backwards from the total price."
                    enabled={settings.enable_tax_inclusive}
                    onChange={(value) => setSettings((prev) => ({ ...prev, enable_tax_inclusive: value }))}
                  />
                  <TaxToggleRow
                    title="Tax exclusive"
                    subtitle="Add tax on top of the product subtotal."
                    enabled={settings.enable_tax_exclusive}
                    onChange={(value) => setSettings((prev) => ({ ...prev, enable_tax_exclusive: value }))}
                  />
                </div>
              </div>

              <div className="ss-doc-block">
                <div className="ss-doc-block-head">
                  <SectionTitle icon={MessageSquareText} tone="text">Customer-facing text</SectionTitle>
                  <p className="ss-doc-block-sub">Payment and footer copy shown on quotations and invoices.</p>
                </div>
                <div className="ss-form-stack">
                  <div className="ss-field ss-field--full">
                    <label className="ss-field-label" htmlFor="bank_details">Payment details</label>
                    <textarea
                      id="bank_details"
                      name="bank_details"
                      className="ss-field-input ss-field-textarea"
                      rows={3}
                      value={settings.bank_details || ''}
                      onChange={handleInputChange}
                      placeholder="Bank name, account name, account number, branch, mobile payment details"
                    />
                    <p className="ss-field-help">Shown on quotations and invoices for customer payments.</p>
                  </div>
                  <div className="ss-field ss-field--full">
                    <label className="ss-field-label" htmlFor="document_footer_message">Document footer</label>
                    <textarea
                      id="document_footer_message"
                      name="document_footer_message"
                      className="ss-field-input ss-field-textarea"
                      rows={2}
                      value={settings.document_footer_message ?? ''}
                      onChange={handleInputChange}
                      placeholder="e.g. Thank you for your business."
                    />
                    <p className="ss-field-help">Printed at the bottom of quotations and invoices.</p>
                  </div>
                </div>
              </div>
            </section>
          )}

          {activeTab === 'targets' && (
            <section className="exp-create-section ss-form-section" id="settings-targets">
              <div className="exp-create-section-header">
                <h2 className="ss-section-heading--with-icon">
                  <Target size={18} className="ss-section-icon ss-icon-tone ss-icon-tone--target" aria-hidden="true" />
                  <span>Sales target</span>
                </h2>
                <p>The revenue a salesperson should reach. One amount applies to everyone unless you set a different amount for a person.</p>
              </div>

              <div className="ss-form-block">
                <div className="ss-target-modes" role="radiogroup" aria-label="Which months this amount covers">
                  <label className={targetScope === 'ongoing' ? 'is-selected' : ''}>
                    <input
                      type="radio"
                      name="sales-target-scope"
                      checked={targetScope === 'ongoing'}
                      onChange={() => setTargetScope('ongoing')}
                    />
                    All months until changed
                  </label>
                  <label className={targetScope === 'month' ? 'is-selected' : ''}>
                    <input
                      type="radio"
                      name="sales-target-scope"
                      checked={targetScope === 'month'}
                      onChange={() => setTargetScope('month')}
                    />
                    Specific month
                  </label>
                </div>

                {targetScope === 'month' ? (
                  <div className="ss-field ss-target-month">
                    <label className="ss-field-label" htmlFor="sales-target-month">Month</label>
                    <input
                      id="sales-target-month"
                      className="ss-field-input"
                      type="month"
                      value={targetMonth}
                      onChange={(event) => changeTargetMonth(event.target.value)}
                    />
                    <p className="ss-field-help">Choose this month or any other month. This amount is used only for that month.</p>
                  </div>
                ) : (
                  <p className="ss-field-help ss-target-scope-note">Used every month until you save a new amount. A specific month still overrides it.</p>
                )}

                <div className="ss-target-modes" role="radiogroup" aria-label="How to set the monthly amount">
                  <label className={targetMode === 'all' ? 'is-selected' : ''}>
                    <input
                      type="radio"
                      name="sales-target-mode"
                      checked={targetMode === 'all'}
                      onChange={() => setTargetMode('all')}
                    />
                    One amount for everyone
                  </label>
                  <label className={targetMode === 'each' ? 'is-selected' : ''}>
                    <input
                      type="radio"
                      name="sales-target-mode"
                      checked={targetMode === 'each'}
                      onChange={() => setTargetMode('each')}
                    />
                    Set each person
                  </label>
                </div>

                {targetMode === 'all' ? (
                  <div className="ss-target-pair">
                    <div className="ss-field ss-target-shared">
                      <label className="ss-field-label" htmlFor="sales-target-shared">Monthly amount</label>
                      <input
                        id="sales-target-shared"
                        className="ss-field-input"
                        inputMode="decimal"
                        value={targetScope === 'ongoing' ? ongoingShared : sharedAmount}
                        onChange={(event) => {
                          const value = event.target.value;
                          if (targetScope === 'ongoing') setOngoingShared(value);
                          else setSharedAmount(value);
                          setYearlyShared(yearlyFromMonthly(value));
                        }}
                        placeholder={settings.default_currency || 'TZS'}
                        autoComplete="off"
                      />
                      <p className="ss-field-help">
                        {targetScope === 'ongoing'
                          ? 'Every salesperson meets this amount each month until you change it.'
                          : 'Every salesperson meets this amount for the selected month.'}
                      </p>
                    </div>
                    <div className="ss-field ss-target-shared">
                      <label className="ss-field-label" htmlFor="sales-target-yearly">Yearly amount</label>
                      <input
                        id="sales-target-yearly"
                        className="ss-field-input"
                        inputMode="decimal"
                        value={yearlyShared}
                        onChange={(event) => setYearlyShared(event.target.value)}
                        autoComplete="off"
                      />
                      <p className="ss-field-help">Filled from the monthly amount times 12. You can change it.</p>
                    </div>
                  </div>
                ) : targetsLoading ? (
                  <p className="ss-field-help">Loading targets…</p>
                ) : targetPeople.length === 0 ? (
                  <p className="ss-field-help">No salespeople are on the active list.</p>
                ) : (
                  <div className="ss-target-list">
                    {targetPeople.map((person) => {
                      const personAmount = scopePersonAmount(person);
                      const fallback = targetScope === 'ongoing' ? ongoingShared : (sharedAmount || ongoingShared);
                      const hint = !personAmount && fallback
                        ? `Uses ${formatTargetMoney(fallback, settings.default_currency)} until you set a different amount.`
                        : (!personAmount && targetScope === 'month' && person.yearly_monthly
                          ? `Yearly target works out to ${formatTargetMoney(person.yearly_monthly, settings.default_currency)} a month until you set this month.`
                          : '');
                      return (
                        <div className="ss-target-row" key={person.id}>
                          <label className="ss-field-label" htmlFor={`sales-target-${person.id}`}>{person.name}</label>
                          <div className="ss-field">
                            <span className="ss-field-label">Monthly</span>
                            <input
                              id={`sales-target-${person.id}`}
                              className="ss-field-input"
                              inputMode="decimal"
                              value={personAmount}
                              onChange={(event) => changeTargetAmount(person.id, event.target.value)}
                              placeholder={fallback || settings.default_currency || 'TZS'}
                              autoComplete="off"
                            />
                            {hint ? <p className="ss-field-help">{hint}</p> : null}
                          </div>
                          <div className="ss-field">
                            <label className="ss-field-label" htmlFor={`sales-target-year-${person.id}`}>Yearly</label>
                            <input
                              id={`sales-target-year-${person.id}`}
                              className="ss-field-input"
                              inputMode="decimal"
                              value={person.yearly_amount || ''}
                              onChange={(event) => changePersonYearly(person.id, event.target.value)}
                              autoComplete="off"
                            />
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>

              <div className="ss-form-actions">
                <button
                  type="button"
                  className="ss-btn ss-btn--primary ss-btn--round"
                  disabled={targetsSaving || targetsLoading}
                  onClick={saveTargets}
                >
                  {targetsSaving && <Loader2 size={18} className="exp-create-spinner" aria-hidden="true" />}
                  {targetsSaving ? 'Saving…' : 'Save targets'}
                </button>
              </div>
            </section>
          )}

          {layoutConfig && (
            <>
              <section className="exp-create-section ss-form-section ss-doc-layout-page" id={`settings-${activeTab}`}>
                <div className="ss-doc-block ss-doc-block--layouts">
                  <div className="ss-doc-block-head">
                    <SectionTitle icon={LayoutTemplate} tone="layout">{layoutConfig.chooseTitle || 'Choose Document Layout'}</SectionTitle>
                    <p className="ss-doc-block-sub">Select one layout to use for all your quotations and invoices.</p>
                  </div>

                  {layoutConfig.hideLayoutPicker ? (
                    <div className="ss-layout-current">
                      <span className="ss-layout-current-badge">Current</span>
                      <div className="ss-layout-current-copy">
                        <strong className="ss-layout-current-name">{selectedLayout.label}</strong>
                        <span className="ss-layout-current-meta">
                          Active layout for {layoutConfig.documentLabel} quotations and invoices
                        </span>
                      </div>
                    </div>
                  ) : (
                    <div className="ss-layout-cards" role="radiogroup" aria-label="Choose document layout">
                      {layoutOptions.map((option) => {
                        const selected = !option.comingSoon && Number(selectedLayoutId) === Number(option.id);
                        const teaserActive = layoutTeaser && Number(layoutTeaser.id) === Number(option.id);
                        return (
                          <button
                            key={option.id}
                            type="button"
                            role="radio"
                            aria-checked={selected || Boolean(teaserActive)}
                            aria-disabled={option.comingSoon || undefined}
                            title={option.description || option.label}
                            className={`ss-layout-card${selected ? ' is-selected' : ''}${option.comingSoon ? ' is-coming-soon' : ''}${teaserActive ? ' is-teaser-active' : ''}`}
                            onClick={() => handleLayoutSelect(activeTab, option)}
                          >
                            <span className={`ss-layout-card-radio${selected ? ' is-on' : ''}`} aria-hidden="true">
                              {selected ? <span className="ss-layout-card-radio-check">✓</span> : null}
                            </span>
                            {selected ? <span className="ss-layout-card-current">Current</span> : null}
                            {option.comingSoon ? <span className="ss-layout-card-current ss-layout-card-current--soon">Soon</span> : null}
                            <LayoutThumb
                              init={init}
                              previewType={layoutConfig.previewType}
                              layoutId={option.id}
                              label={option.label}
                              comingSoon={Boolean(option.comingSoon)}
                            />
                            <div className="ss-layout-card-body">
                              <span className="ss-layout-card-name">{option.id}. {option.label}</span>
                              {option.description ? (
                                <p className="ss-layout-card-desc">{option.description}</p>
                              ) : null}
                            </div>
                          </button>
                        );
                      })}
                    </div>
                  )}
                </div>
              </section>

              <section className="exp-create-section ss-form-section ss-doc-layout-page" id={`settings-${activeTab}-footer`}>
                <div className="ss-doc-block ss-doc-block--footer">
                  <div className="ss-doc-block-head">
                    <SectionTitle icon={FileText} tone="footer">{layoutConfig.footerTitle || 'Document Footer Content'}</SectionTitle>
                    <p className="ss-doc-block-sub">
                      {layoutConfig.footerDescription || 'This content will be printed at the bottom of your quotations and invoices.'}
                    </p>
                  </div>

                  <div className="ss-footer-grid">
                    <div className="ss-field">
                      <label className="ss-field-label" htmlFor={`${fieldPrefix}_payment_details`}>Payment Details</label>
                      <textarea
                        id={`${fieldPrefix}_payment_details`}
                        name={`${fieldPrefix}_payment_details`}
                        className="ss-field-input ss-field-textarea"
                        rows={4}
                        value={settings[`${fieldPrefix}_payment_details`] ?? ''}
                        onChange={handleInputChange}
                        placeholder="Synced from Company Settings → Finance when empty"
                      />
                      <p className="ss-field-help">
                        Auto-filled from Company Settings → Finance (Payment details) when empty.
                      </p>
                    </div>

                    <div className="ss-field">
                      <label className="ss-field-label" htmlFor={`${fieldPrefix}_terms`}>Terms &amp; Conditions</label>
                      <textarea
                        id={`${fieldPrefix}_terms`}
                        name={`${fieldPrefix}_terms`}
                        className="ss-field-input ss-field-textarea"
                        rows={4}
                        value={settings[`${fieldPrefix}_terms`] ?? ''}
                        onChange={handleInputChange}
                        placeholder="e.g. Payment terms, order conditions..."
                      />
                    </div>

                    <div className="ss-field">
                      <label className="ss-field-label" htmlFor={`${fieldPrefix}_validity`}>Validity Period</label>
                      <textarea
                        id={`${fieldPrefix}_validity`}
                        name={`${fieldPrefix}_validity`}
                        className="ss-field-input ss-field-textarea"
                        rows={4}
                        value={settings[`${fieldPrefix}_validity`] ?? ''}
                        onChange={handleInputChange}
                        placeholder="e.g. Quotation valid for 30 days..."
                      />
                    </div>

                    <div className="ss-field">
                      <label className="ss-field-label" htmlFor={`${fieldPrefix}_thanks_note`}>Thank You Note</label>
                      <textarea
                        id={`${fieldPrefix}_thanks_note`}
                        name={`${fieldPrefix}_thanks_note`}
                        className="ss-field-input ss-field-textarea"
                        rows={4}
                        value={settings[`${fieldPrefix}_thanks_note`] ?? ''}
                        onChange={handleInputChange}
                        placeholder="e.g. Thank you for your business..."
                      />
                    </div>

                    <div className="ss-field">
                      <label className="ss-field-label" htmlFor={`${fieldPrefix}_return_policy`}>Return Policy</label>
                      <textarea
                        id={`${fieldPrefix}_return_policy`}
                        name={`${fieldPrefix}_return_policy`}
                        className="ss-field-input ss-field-textarea"
                        rows={4}
                        value={settings[`${fieldPrefix}_return_policy`] ?? ''}
                        onChange={handleInputChange}
                        placeholder="e.g. Returns and warranty policy..."
                      />
                    </div>

                  </div>

                  {layoutConfig.showTruckRemarks ? (
                    <div className="ss-field ss-field--full" style={{ marginTop: '1rem' }}>
                      <label className="ss-field-label" htmlFor="truck_remarks">Truck remarks</label>
                      <textarea
                        id="truck_remarks"
                        name="truck_remarks"
                        className="ss-field-input ss-field-textarea"
                        rows={4}
                        value={settings.truck_remarks ?? ''}
                        onChange={handleInputChange}
                        placeholder="Appears on the second page of truck documents..."
                      />
                    </div>
                  ) : null}
                </div>

                <div className="ss-form-actions ss-form-actions--end">
                  <button type="submit" className="ss-btn ss-btn--primary ss-btn--round" disabled={saving}>
                    {saving && <Loader2 size={18} className="exp-create-spinner" aria-hidden="true" />}
                    {saving ? 'Saving…' : 'Save Changes'}
                  </button>
                </div>
              </section>
            </>
          )}

          {activeTab !== 'targets' && !layoutConfig && (
          <div className="ss-form-actions">
            <button
              type="button"
              className="ss-btn ss-btn--ghost"
              onClick={() => { window.location.href = init.urls.dashboard; }}
            >
              Cancel
            </button>
            <button type="submit" className="ss-btn ss-btn--primary ss-btn--round" disabled={saving}>
              {saving && <Loader2 size={18} className="exp-create-spinner" aria-hidden="true" />}
              {saving ? 'Saving…' : 'Save changes'}
            </button>
          </div>
          )}
        </div>
      </form>

      {layoutPreview ? (
        <div
          className="ss-layout-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="ss-layout-modal-title"
          onClick={closeLayoutPreview}
        >
          <div
            className="ss-layout-modal-panel"
            onClick={(event) => event.stopPropagation()}
          >
            <button
              type="button"
              className="ss-layout-modal-x"
              onClick={closeLayoutPreview}
              aria-label="Close layout preview"
            >
              <span aria-hidden="true">×</span>
            </button>

            <div className="ss-layout-modal-grid">
              <div className="ss-layout-modal-media">
                <div className="ss-layout-modal-media-frame">
                  {layoutPreview.previewUrl ? (
                    <iframe
                      key={layoutPreview.previewUrl}
                      className="ss-layout-modal-frame"
                      src={layoutPreview.previewUrl}
                      title={`${layoutPreview.label} layout preview`}
                    />
                  ) : (
                    <div className="ss-layout-modal-empty">Preview unavailable</div>
                  )}
                </div>
              </div>

              <div className="ss-layout-modal-info">
                <p className="ss-layout-modal-eyebrow">Document layout</p>
                <h3 id="ss-layout-modal-title" className="ss-layout-modal-title">
                  {layoutPreview.label}
                </h3>
                <div className="ss-layout-modal-pill">
                  {Number(selectedLayoutId) === Number(layoutPreview.id) ? 'Active layout' : 'Preview'}
                </div>

                {layoutPreview.description ? (
                  <p className="ss-layout-modal-desc">{layoutPreview.description}</p>
                ) : null}

                <div className="ss-layout-modal-meta">
                  <div className="ss-layout-modal-meta-row">
                    <span>Applies to</span>
                    <strong>{layoutPreview.documentLabel} documents</strong>
                  </div>
                  <div className="ss-layout-modal-meta-row">
                    <span>Layout ID</span>
                    <strong>{layoutPreview.id}</strong>
                  </div>
                  <div className="ss-layout-modal-meta-row">
                    <span>Status</span>
                    <strong className={Number(selectedLayoutId) === Number(layoutPreview.id) ? 'is-active' : ''}>
                      {Number(selectedLayoutId) === Number(layoutPreview.id) ? 'In use' : 'Available'}
                    </strong>
                  </div>
                </div>

                <div className="ss-layout-modal-actions">
                  <button
                    type="button"
                    className="ss-layout-modal-btn ss-layout-modal-btn--primary"
                    onClick={useLayoutFromPreview}
                    disabled={Number(selectedLayoutId) === Number(layoutPreview.id)}
                  >
                    {Number(selectedLayoutId) === Number(layoutPreview.id) ? 'Currently active' : 'Use this layout'}
                  </button>
                  <button
                    type="button"
                    className="ss-layout-modal-btn ss-layout-modal-btn--ghost"
                    onClick={closeLayoutPreview}
                  >
                    Close
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
