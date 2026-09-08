import { useEffect, useState } from 'react';
import { Loader2 } from 'lucide-react';
import { fetchSettingsInit, saveSettings, saveSettingsFields } from '../api/settingsDesk';

const ROADMASTER_TABS = [
  { key: 'financials', label: 'Tax & Finance' },
  { key: 'truck', label: 'Truck Layout' },
  { key: 'spare', label: 'Spare Layout' },
];

function getSettingsTabs(init) {
  if (init?.is_ultimate) {
    return [
      { key: 'financials', label: 'Tax & Finance' },
      { key: 'settings', label: 'Settings' },
    ];
  }

  return ROADMASTER_TABS;
}

function getLayoutTabConfig(activeTab) {
  if (activeTab === 'settings') {
    return {
      layoutField: 'spare_part_layout',
      fieldPrefix: 'spare',
      previewType: 'spare',
      catalogKey: 'ultimate',
      sectionTitle: 'Document settings',
      sectionDescription: 'View the document layout in use and manage footer content for quotations and invoices.',
      documentLabel: 'Ultimate',
      saveLabel: 'Ultimate',
      showTruckRemarks: false,
      hideLayoutPicker: true,
      fixedLayoutId: 1,
    };
  }

  if (activeTab === 'truck') {
    return {
      layoutField: 'truck_layout',
      fieldPrefix: 'truck',
      previewType: 'truck',
      catalogKey: 'truck',
      sectionTitle: 'Truck document layout',
      sectionDescription: 'Choose the print layout and footer content for truck quotations and invoices.',
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
    sectionTitle: 'Spare part document layout',
    sectionDescription: 'Choose the print layout and footer content for spare part quotations and invoices.',
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

function layoutLabel(activeTab, id) {
  if (activeTab === 'truck') {
    if (id === 2) return 'Classic';
    if (id === 3) return 'Minimalist';
    return 'Watermark';
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
    comingSoon: catalogKey === 'truck' && id > 1,
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

function buildLayoutPreviewUrl(init, previewType, layoutId, fullView = false) {
  if (!init?.urls?.layoutPreview) return '';
  const params = new URLSearchParams(window.location.search);
  params.set('type', previewType);
  params.set('layout', String(layoutId));
  if (fullView) {
    params.set('full', '1');
  } else {
    params.delete('full');
  }
  return `${init.urls.layoutPreview}?${params.toString()}`;
}

function openLayoutPreviewPopup(previewUrl) {
  if (!previewUrl || typeof window === 'undefined') return;

  const url = new URL(previewUrl, window.location.origin);
  url.searchParams.set('full', '1');

  const width = Math.min(window.screen.availWidth - 48, 980);
  const height = Math.min(window.screen.availHeight - 48, 900);
  const left = Math.max(0, Math.round((window.screen.availWidth - width) / 2));
  const top = Math.max(0, Math.round((window.screen.availHeight - height) / 2));

  window.open(
    url.toString(),
    'salesLayoutPreview',
    `popup=yes,width=${width},height=${height},left=${left},top=${top},scrollbars=yes,resizable=yes`,
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
        className={`ss-switch${isOn ? ' is-on' : ''}`}
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

function getSelectedFontStack(init, fontKey) {
  const match = init?.fontCatalog?.find((entry) => entry.key === fontKey);
  if (match?.stack) return match.stack;
  if (fontKey === 'arima') return "'Arima', Arial, 'Helvetica Neue', Helvetica, sans-serif";
  return 'system-ui, sans-serif';
}

function useDocumentFontPreview(init, fontKey) {
  const [, setFontTick] = useState(0);

  useEffect(() => {
    if (!init?.fontCatalog) return undefined;

    const match = init.fontCatalog.find((entry) => entry.key === fontKey);
    const links = [];
    let cancelled = false;

    const notifyReady = () => {
      if (!cancelled) {
        setFontTick((value) => value + 1);
      }
    };

    if (match?.localCss) {
      const local = document.createElement('link');
      local.rel = 'stylesheet';
      local.href = match.localCss;
      local.dataset.ssDocFontPreview = '1';
      local.onload = notifyReady;
      local.onerror = notifyReady;
      document.head.appendChild(local);
      links.push(local);
    }

    if (match?.google) {
      const google = document.createElement('link');
      google.rel = 'stylesheet';
      google.href = match.google;
      google.dataset.ssDocFontPreview = '1';
      google.onload = notifyReady;
      google.onerror = notifyReady;
      document.head.appendChild(google);
      links.push(google);
    }

    if (typeof document !== 'undefined' && document.fonts?.ready) {
      document.fonts.ready.then(notifyReady).catch(notifyReady);
    }

    return () => {
      cancelled = true;
      links.forEach((node) => node.remove());
    };
  }, [init, fontKey]);
}

export default function SalesSettingsPage() {
  const [init, setInit] = useState(null);
  const [settings, setSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [activeTab, setActiveTab] = useState('financials');
  const [saving, setSaving] = useState(false);
  const [layoutTeaser, setLayoutTeaser] = useState(null);

  useDocumentFontPreview(init, settings?.sales_document_font || 'arima');

  useEffect(() => {
    let cancelled = false;

    fetchSettingsInit()
      .then((data) => {
        if (cancelled) return;
        setInit(data);
        setSettings(data.settings || {});
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
      return;
    }

    setLayoutTeaser(null);
    handleLayoutChange(type, option.id);
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
    return (
      <div className="exp-create-loading" role="status" aria-live="polite">
        <Loader2 size={22} className="exp-create-spinner" aria-hidden="true" />
        Loading settings...
      </div>
    );
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
  const layoutPreviewUrl = layoutConfig
    ? buildLayoutPreviewUrl(init, layoutConfig.previewType, selectedLayoutId)
    : '';
  const fieldPrefix = layoutConfig?.fieldPrefix || activeTab;

  return (
    <div className="exp-create-shell ss-settings-shell">
      <header className="ss-page-intro">
        <h1 className="ss-page-title">Sales settings</h1>
        <p className="ss-page-lead">Tax, currency, and document footer content for quotations and invoices.</p>
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
                }}
              >
                {tab.label}
              </button>
            </li>
          ))}
        </ul>
      </nav>

      <form className="ss-settings-form" onSubmit={saveAll}>
        <div className="exp-create-main">
          {activeTab === 'financials' && (
            <section className="exp-create-section ss-form-section" id="settings-financials">
              <div className="exp-create-section-header">
                <h2>Tax &amp; Finance</h2>
                <p>VAT, currency, and payment details for quotations and invoices.</p>
              </div>

              <div className="ss-form-block">
                <h3 className="ss-form-block-title">Registration</h3>
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
                    <select
                      id="sales_document_font"
                      name="sales_document_font"
                      className="ss-field-input ss-field-select"
                      value={settings.sales_document_font || 'arima'}
                      onChange={handleInputChange}
                    >
                      {(init.fontCatalog || []).map((font) => (
                        <option key={font.key} value={font.key}>
                          {font.label}
                        </option>
                      ))}
                    </select>
                    <p className="ss-field-help">Used when printing or exporting PDF.</p>
                  </div>
                </div>
                <div
                  className="ss-doc-font-preview"
                  style={{
                    '--ss-doc-font-stack': getSelectedFontStack(init, settings.sales_document_font || 'arima'),
                    fontFamily: getSelectedFontStack(init, settings.sales_document_font || 'arima'),
                  }}
                >
                  <div className="ss-doc-font-preview-label">Font preview</div>
                  <p className="ss-doc-font-preview-body">
                    Quotation #QT-2026-001 — Sample Customer Ltd — Total TZS 1,475,000
                  </p>
                </div>
              </div>

              <div className="ss-form-block">
                <h3 className="ss-form-block-title">Tax calculation</h3>
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

              <div className="ss-form-block">
                <h3 className="ss-form-block-title">Customer-facing text</h3>
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

          {layoutConfig && (
            <section className="exp-create-section ss-form-section" id={`settings-${activeTab}`}>
              <div className="exp-create-section-header">
                <h2>{layoutConfig.sectionTitle}</h2>
                <p>{layoutConfig.sectionDescription}</p>
              </div>

              <div className="ss-form-block">
                <h3 className="ss-form-block-title">Document layout</h3>
                {layoutConfig.hideLayoutPicker ? (
                  <>
                    <div className="ss-layout-current">
                      <span className="ss-layout-current-badge">In use</span>
                      <div className="ss-layout-current-copy">
                        <strong className="ss-layout-current-name">{selectedLayout.label}</strong>
                        <span className="ss-layout-current-meta">
                          Active layout for
                          {' '}
                          {layoutConfig.documentLabel}
                          {' '}
                          quotations and invoices
                        </span>
                      </div>
                    </div>
                    {selectedLayout.description ? (
                      <p className="ss-field-help">{selectedLayout.description}</p>
                    ) : null}
                  </>
                ) : (
                  <>
                    <div className="ss-layout-pills" role="radiogroup" aria-label="Document layout">
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
                            className={`ss-pill-choice${selected ? ' is-selected' : ''}${option.comingSoon ? ' is-coming-soon' : ''}${teaserActive ? ' is-teaser-active' : ''}`}
                            onClick={() => handleLayoutSelect(activeTab, option)}
                          >
                            <span className="ss-pill-choice-label">{String(option.id)}</span>
                            {option.comingSoon ? (
                              <span className="ss-pill-soon-badge">Soon</span>
                            ) : selected ? (
                              <span className="ss-pill-choice-knob" aria-hidden="true" />
                            ) : null}
                          </button>
                        );
                      })}
                    </div>
                    <p className="ss-field-help">
                      {layoutTeaser ? (
                        <>
                          <strong>{layoutTeaser.label}</strong>
                          {' '}
                          layout is
                          {' '}
                          <span className="ss-coming-soon-inline">coming soon</span>
                          .
                        </>
                      ) : (
                        <>
                          <strong>{selectedLayout.label}</strong>
                          {' '}
                          layout is active for
                          {' '}
                          {layoutConfig.documentLabel}
                          {' '}
                          documents.
                          {selectedLayout.description ? (
                            <>
                              {' '}
                              {selectedLayout.description}
                            </>
                          ) : null}
                        </>
                      )}
                    </p>
                  </>
                )}

                {(!layoutConfig.hideLayoutPicker && layoutTeaser) ? null : layoutPreviewUrl && (
                  <div className="ss-layout-preview-block">
                    <div className="ss-layout-live-preview">
                      <iframe
                        key={`${activeTab}-${selectedLayoutId}`}
                        title={`${selectedLayout.label} layout preview`}
                        src={layoutPreviewUrl}
                        className="ss-layout-preview-frame"
                        loading="lazy"
                      />
                    </div>
                    <button
                      type="button"
                      className="ss-layout-preview-open"
                      onClick={() => openLayoutPreviewPopup(layoutPreviewUrl)}
                    >
                      Preview layout
                    </button>
                  </div>
                )}
              </div>

              <div className="ss-editor-layout">
                <div className="ss-form-block">
                  <h3 className="ss-form-block-title">Footer content</h3>
                  <div className="ss-form-stack">
                    <div className="ss-field ss-field--full">
                      <label className="ss-field-label" htmlFor={`${fieldPrefix}_payment_details`}>Payment instructions</label>
                      <textarea
                        id={`${fieldPrefix}_payment_details`}
                        name={`${fieldPrefix}_payment_details`}
                        className="ss-field-input ss-field-textarea"
                        rows={4}
                        value={settings[`${fieldPrefix}_payment_details`] ?? ''}
                        onChange={handleInputChange}
                        placeholder="Enter bank names and account numbers..."
                      />
                    </div>

                    <div className="ss-field ss-field--full">
                      <label className="ss-field-label" htmlFor={`${fieldPrefix}_terms`}>Terms &amp; policy</label>
                      <textarea
                        id={`${fieldPrefix}_terms`}
                        name={`${fieldPrefix}_terms`}
                        className="ss-field-input ss-field-textarea"
                        rows={4}
                        value={settings[`${fieldPrefix}_terms`] ?? ''}
                        onChange={handleInputChange}
                        placeholder="Standard terms and conditions..."
                      />
                    </div>

                    <div className="ss-form-grid">
                      <div className="ss-field">
                        <label className="ss-field-label" htmlFor={`${fieldPrefix}_validity`}>Quote validity</label>
                        <input
                          id={`${fieldPrefix}_validity`}
                          name={`${fieldPrefix}_validity`}
                          className="ss-field-input"
                          value={settings[`${fieldPrefix}_validity`] ?? ''}
                          onChange={handleInputChange}
                          placeholder="e.g. Valid for 10 days"
                        />
                      </div>
                      <div className="ss-field">
                        <label className="ss-field-label" htmlFor={`${fieldPrefix}_thanks_note`}>Closing note</label>
                        <input
                          id={`${fieldPrefix}_thanks_note`}
                          name={`${fieldPrefix}_thanks_note`}
                          className="ss-field-input"
                          value={settings[`${fieldPrefix}_thanks_note`] ?? ''}
                          onChange={handleInputChange}
                          placeholder={activeTab === 'settings' ? 'e.g. Thank you for your business' : 'e.g. Thank you for choosing Roadmaster'}
                        />
                      </div>
                      <div className="ss-field ss-field--full">
                        <label className="ss-field-label" htmlFor={`${fieldPrefix}_return_policy`}>Return policy</label>
                        <input
                          id={`${fieldPrefix}_return_policy`}
                          name={`${fieldPrefix}_return_policy`}
                          className="ss-field-input"
                          value={settings[`${fieldPrefix}_return_policy`] ?? ''}
                          onChange={handleInputChange}
                        />
                      </div>
                    </div>

                    {layoutConfig.showTruckRemarks && (
                      <div className="ss-field ss-field--full">
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
                    )}
                  </div>
                </div>

                <div className="ss-preview-pane">
                  <div className="ss-preview-label">Document footer preview</div>
                  <div className="ss-mock-footer">
                    <div className="ss-mock-payment-header">Payment details</div>
                    <div className="ss-mock-payment-body">
                      {settings[`${fieldPrefix}_payment_details`] || 'Select a payment method...'}
                    </div>
                    <div className="ss-mock-meta">
                      <div>{settings[`${fieldPrefix}_terms`]}</div>
                      <div className="ss-mock-meta-strong">{settings[`${fieldPrefix}_validity`]}</div>
                    </div>
                    <div className="ss-mock-thanks">
                      {settings[`${fieldPrefix}_thanks_note`] || 'Thank you'}
                      <div className="ss-mock-policy">
                        {settings[`${fieldPrefix}_return_policy`]}
                      </div>
                    </div>
                  </div>
                  <div className="ss-preview-note">
                    Preview updates as you type
                  </div>
                </div>
              </div>
            </section>
          )}

          <div className="ss-form-actions">
            <button
              type="button"
              className="ss-btn ss-btn--ghost"
              onClick={() => { window.location.href = init.urls.dashboard; }}
            >
              Cancel
            </button>
            <button type="submit" className="ss-btn ss-btn--primary" disabled={saving}>
              {saving && <Loader2 size={18} className="exp-create-spinner" aria-hidden="true" />}
              {saving ? 'Saving…' : 'Save changes'}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
