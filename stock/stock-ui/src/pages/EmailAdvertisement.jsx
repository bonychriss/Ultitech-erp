import React, { useEffect, useState } from 'react';
import {
  HiOutlinePaperAirplane,
  HiOutlineEye,
  HiOutlineTrash,
  HiOutlineComputerDesktop,
  HiOutlineDevicePhoneMobile,
  HiOutlineClock,
  HiOutlineCheckCircle,
  HiOutlineExclamationCircle,
  HiOutlineDocumentText,
  HiOutlineCube,
} from 'react-icons/hi2';
import './products-desk.css';
import './email-advertisement.css';

const INTRO_MAX = 500;
const CATALOGUE_STORAGE_KEY = 'email_ad_catalogue_items';
const CATALOGUE_DRAFT_KEY = 'email_ad_catalogue_items_draft';
const FORM_DRAFT_KEY = 'email_ad_form_draft';

function catalogueUrlWithSelected(baseUrl, productIds) {
  if (!baseUrl) return '';
  try {
    const url = new URL(baseUrl, window.location.origin);
    const ids = (productIds || []).map((id) => Number(id)).filter((id) => id > 0);
    if (ids.length) url.searchParams.set('selected', ids.join(','));
    else url.searchParams.delete('selected');
    return url.toString();
  } catch {
    return baseUrl;
  }
}

function readCataloguePickedIds() {
  const ids = [];
  const ingest = (raw) => {
    try {
      const list = JSON.parse(raw || '[]');
      if (!Array.isArray(list)) return;
      list.forEach((row) => {
        const id = Number(row?.product_id ?? row?.id ?? 0);
        if (id > 0 && !ids.includes(id)) ids.push(id);
      });
    } catch {
      /* ignore */
    }
  };
  ingest(localStorage.getItem(CATALOGUE_STORAGE_KEY));
  ingest(localStorage.getItem(CATALOGUE_DRAFT_KEY));
  try {
    const param = new URLSearchParams(window.location.search).get('catalogue_product_ids') || '';
    param.split(',').forEach((part) => {
      const id = Number(String(part).trim());
      if (id > 0 && !ids.includes(id)) ids.push(id);
    });
  } catch {
    /* ignore */
  }
  return ids;
}

function clearCatalogueStorage() {
  try {
    localStorage.removeItem(CATALOGUE_STORAGE_KEY);
    localStorage.removeItem(CATALOGUE_DRAFT_KEY);
  } catch {
    /* ignore */
  }
  try {
    const url = new URL(window.location.href);
    if (url.searchParams.has('catalogue_product_ids')) {
      url.searchParams.delete('catalogue_product_ids');
      window.history.replaceState({}, '', url.toString());
    }
  } catch {
    /* ignore */
  }
}

function money(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0.00';
  return n.toLocaleString('en', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

function statusMeta(status) {
  const map = {
    draft: { label: 'Draft', className: 'is-draft' },
    scheduled: { label: 'Scheduled', className: 'is-scheduled' },
    sending: { label: 'Sending', className: 'is-scheduled' },
    sent: { label: 'Sent', className: 'is-sent' },
    failed: { label: 'Failed', className: 'is-failed' },
  };
  return map[status] || { label: status || '�', className: 'is-draft' };
}

function formatWhen(value) {
  if (!value) return '�';
  const d = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function ProductThumb({ product, className = '' }) {
  const src = product?.image_url || '';
  if (!src) {
    return <div className={`ead-thumb ead-thumb--empty ${className}`.trim()}>No photo</div>;
  }
  return (
    <div className={`ead-thumb ${className}`.trim()}>
      <img
        src={src}
        alt=""
        loading="lazy"
        onError={(e) => {
          e.currentTarget.style.display = 'none';
        }}
      />
    </div>
  );
}

function Step({ n, title, children }) {
  return (
    <section className="ead-step">
      <header className="ead-step-head">
        <span className="ead-step-num" aria-hidden="true">
          {n}
        </span>
        <h2 className="ead-step-title">{title}</h2>
      </header>
      <div className="ead-step-body">{children}</div>
    </section>
  );
}

function EmailPreview({
  companyName,
  siteHost,
  headline,
  intro,
  ctaLabel,
  products,
  previewMode,
  onPreviewMode,
}) {
  const pairs = [];
  for (let i = 0; i < products.length; i += 2) {
    pairs.push(products.slice(i, i + 2));
  }

  return (
    <div className="ead-preview-panel">
      <div className="ead-preview-bar">
        <div className="ead-preview-bar-title">
          <HiOutlineEye aria-hidden="true" />
          <span>Email preview</span>
        </div>
        <div className="ead-preview-toggle" role="group" aria-label="Preview size">
          <button
            type="button"
            className={previewMode === 'desktop' ? 'is-on' : ''}
            onClick={() => onPreviewMode('desktop')}
          >
            <HiOutlineComputerDesktop aria-hidden="true" />
            Desktop
          </button>
          <button
            type="button"
            className={previewMode === 'mobile' ? 'is-on' : ''}
            onClick={() => onPreviewMode('mobile')}
          >
            <HiOutlineDevicePhoneMobile aria-hidden="true" />
            Mobile
          </button>
        </div>
      </div>

      <div className={`ead-mail-frame ead-mail-frame--${previewMode}`}>
        <div className="ead-mail-sheet">
          <div className="ead-mail-brand">
            <div>
              <strong>{companyName}</strong>
              <small>Your Trusted PPE Partner</small>
            </div>
            <div className="ead-mail-brand-meta">
              <span>Safety</span>
              <span>Quality</span>
              <span>Reliability</span>
            </div>
          </div>

          <div className="ead-mail-hero">
            <div className="ead-mail-hero-overlay">
              <h3>{headline || 'Recommended products for your business'}</h3>
            </div>
          </div>

          <div className="ead-mail-body">
            <p className="ead-mail-intro">
              {intro.trim()
                ? intro
                : `Discover quality products from ${companyName}. Inquire directly from this email.`}
            </p>

            {products.length === 0 ? (
              <div className="ead-mail-empty">Select products in step 2 to fill this grid.</div>
            ) : (
              <div className="ead-mail-grid">
                {pairs.map((row, idx) => (
                  <div key={idx} className="ead-mail-row">
                    {row.map((p) => (
                      <article key={p.id} className="ead-mail-card">
                        <ProductThumb product={p} />
                        <div className="ead-mail-card-body">
                          <h4>{p.name}</h4>
                          <small>{p.product_code || '�'}</small>
                          <div className="ead-mail-price">TZS {money(p.unit_price)}</div>
                          <span className="ead-mail-cta">{ctaLabel || 'Inquire Now'}</span>
                        </div>
                      </article>
                    ))}
                    {row.length === 1 ? <div className="ead-mail-card ead-mail-card--spacer" /> : null}
                  </div>
                ))}
              </div>
            )}

            <div className="ead-mail-foot">
              <div className="ead-mail-foot-points">
                <span>Wide range of PPE products</span>
                <span>Get a quotation</span>
              </div>
              <div className="ead-mail-catalogue">Visit Our Catalogue</div>
              <small>
                {companyName} � {siteHost}
              </small>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function EmailAdvertisement({ data }) {
  const {
    companyName = 'Ultimate',
    siteHost = 'ultimate.co.tz',
    customers: initialCustomers = [],
    campaigns: initialCampaigns = [],
    apiUrl = '',
    catalogueUrl = '',
  } = data || {};

  const [name, setName] = useState('');
  const [subject, setSubject] = useState(`Products from ${companyName}`);
  const [headline, setHeadline] = useState('QUALITY PPE FOR A SAFER WORKPLACE');
  const [intro, setIntro] = useState(
    'Discover our latest industrial safety products, carefully selected for quality, durability and compliance. Request pricing or place your enquiry directly from this email.'
  );
  const [ctaLabel, setCtaLabel] = useState('Inquire Now');
  const [audienceMode, setAudienceMode] = useState('all');
  const [selectedCustomerIds, setSelectedCustomerIds] = useState([]);
  const [selectedProducts, setSelectedProducts] = useState([]);
  const [customers] = useState(initialCustomers);
  const [campaigns, setCampaigns] = useState(initialCampaigns);
  const [scheduleMode, setScheduleMode] = useState('now');
  const [scheduledAt, setScheduledAt] = useState('');
  const [previewMode, setPreviewMode] = useState('desktop');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const customerCount = audienceMode === 'all' ? customers.length : selectedCustomerIds.length;
  const canSend = selectedProducts.length > 0 && subject.trim() !== '' && customerCount > 0;
  const introLen = intro.length;

  const api = async (action, payload = {}) => {
    const res = await fetch(apiUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action, ...payload }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.ok === false) {
      throw new Error(json.error || 'Request failed');
    }
    return json;
  };

  useEffect(() => {
    try {
      const raw = sessionStorage.getItem(FORM_DRAFT_KEY);
      if (!raw) return;
      const draft = JSON.parse(raw);
      if (!draft || typeof draft !== 'object') return;
      if (typeof draft.subject === 'string') setSubject(draft.subject);
      if (typeof draft.headline === 'string') setHeadline(draft.headline);
      if (typeof draft.intro === 'string') setIntro(draft.intro);
      if (typeof draft.ctaLabel === 'string') setCtaLabel(draft.ctaLabel);
      if (typeof draft.name === 'string') setName(draft.name);
      if (draft.audienceMode === 'all' || draft.audienceMode === 'selected') setAudienceMode(draft.audienceMode);
      if (Array.isArray(draft.selectedCustomerIds)) setSelectedCustomerIds(draft.selectedCustomerIds);
      if (draft.scheduleMode === 'now' || draft.scheduleMode === 'later') setScheduleMode(draft.scheduleMode);
      if (typeof draft.scheduledAt === 'string') setScheduledAt(draft.scheduledAt);
      sessionStorage.removeItem(FORM_DRAFT_KEY);
    } catch {
      /* ignore */
    }
  }, []);

  useEffect(() => {
    const ids = readCataloguePickedIds();
    if (!ids.length || !apiUrl) return undefined;
    let cancelled = false;
    (async () => {
      try {
        const json = await api('products_by_ids', { ids });
        if (cancelled) return;
        setSelectedProducts(json.products || []);
      } catch {
        /* keep empty */
      } finally {
        clearCatalogueStorage();
      }
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [apiUrl]);

  const removeProduct = (id) => {
    setSelectedProducts((prev) => prev.filter((p) => p.id !== id));
  };

  const clearProducts = () => setSelectedProducts([]);

  const openSalesCatalogue = () => {
    if (!catalogueUrl) {
      setError('Sales catalogue is not available.');
      return;
    }
    try {
      sessionStorage.setItem(
        FORM_DRAFT_KEY,
        JSON.stringify({
          name,
          subject,
          headline,
          intro,
          ctaLabel,
          audienceMode,
          selectedCustomerIds,
          scheduleMode,
          scheduledAt,
        })
      );
    } catch {
      /* ignore */
    }
    window.location.href = catalogueUrlWithSelected(
      catalogueUrl,
      selectedProducts.map((p) => p.id)
    );
  };

  const toggleCustomer = (id) => {
    setSelectedCustomerIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  const refreshCampaigns = async () => {
    const json = await api('list');
    setCampaigns(json.campaigns || []);
  };

  const payloadBase = () => ({
    name: name.trim() || subject.trim() || headline.trim(),
    subject: subject.trim(),
    headline: headline.trim(),
    cta_label: ctaLabel.trim() || 'Inquire Now',
    intro_html: intro.trim().replace(/\n/g, '<br>'),
    product_ids: selectedProducts.map((p) => p.id),
    audience_mode: audienceMode,
    audience_ids: audienceMode === 'selected' ? selectedCustomerIds : [],
  });

  const submit = async (mode) => {
    if (busy || !apiUrl) return;
    if (mode !== 'draft' && !canSend) {
      setError('Add products, a subject, and recipients before sending.');
      return;
    }
    setBusy(true);
    setError('');
    setMessage('');
    try {
      const payload = {
        ...payloadBase(),
        schedule_mode: mode === 'schedule' ? 'later' : mode === 'draft' ? 'draft' : 'now',
        scheduled_at: mode === 'schedule' ? scheduledAt : '',
      };

      if (mode === 'send') {
        const json = await api('save_and_send', payload);
        setMessage(
          `Email sent to ${json.sent || 0} customer${(json.sent || 0) === 1 ? '' : 's'}` +
            (json.failed ? ` (${json.failed} failed)` : '') +
            '.'
        );
      } else if (mode === 'schedule') {
        await api('save', payload);
        setMessage('Campaign scheduled. It will send automatically at the chosen time.');
      } else {
        await api('save', payload);
        setMessage('Draft saved.');
      }

      if (mode !== 'draft') {
        setName('');
        setSelectedProducts([]);
        setSelectedCustomerIds([]);
        setScheduleMode('now');
        setScheduledAt('');
      }
      await refreshCampaigns();
    } catch (err) {
      setError(err?.message || 'Could not save');
    } finally {
      setBusy(false);
    }
  };

  const sendExisting = async (id) => {
    if (busy) return;
    setBusy(true);
    setError('');
    setMessage('');
    try {
      const json = await api('send', { id });
      setMessage(`Sent to ${json.sent || 0} customers` + (json.failed ? ` (${json.failed} failed)` : '') + '.');
      await refreshCampaigns();
    } catch (err) {
      setError(err?.message || 'Send failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="prod-desk-page ead-page ead-page--mock">
      <header className="ead-page-head">
        <p className="ead-sub">Create a product email in 4 steps and send to your customers.</p>
        <div className="ead-page-head-actions">
          <button type="button" className="ead-btn ead-btn--ghost" disabled={busy} onClick={() => submit('draft')}>
            Save draft
          </button>
          {scheduleMode === 'later' ? (
            <button
              type="button"
              className="ead-btn ead-btn--primary"
              disabled={busy || !canSend}
              onClick={() => submit('schedule')}
            >
              <HiOutlineClock aria-hidden="true" />
              {busy ? 'Saving�' : 'Schedule email'}
            </button>
          ) : (
            <button
              type="button"
              className="ead-btn ead-btn--primary"
              disabled={busy || !canSend}
              onClick={() => submit('send')}
            >
              <HiOutlinePaperAirplane aria-hidden="true" />
              {busy ? 'Sending�' : 'Send email now'}
            </button>
          )}
        </div>
      </header>

      {message ? (
        <div className="ead-flash ead-flash--ok">
          <HiOutlineCheckCircle aria-hidden="true" />
          {message}
        </div>
      ) : null}
      {error ? (
        <div className="ead-flash ead-flash--err">
          <HiOutlineExclamationCircle aria-hidden="true" />
          {error}
        </div>
      ) : null}

      <div className="ead-layout">
        <div className="ead-main">
          <Step n="1" title="Write the email">
            <div className="ead-fields ead-fields--core">
              <label className="ead-label">
                Email subject
                <input
                  className="ead-input"
                  value={subject}
                  onChange={(e) => setSubject(e.target.value)}
                  placeholder="What customers see in their inbox"
                />
              </label>
              <label className="ead-label">
                Big headline
                <input
                  className="ead-input"
                  value={headline}
                  onChange={(e) => setHeadline(e.target.value)}
                  placeholder="Main banner line in the email"
                />
              </label>
              <label className="ead-label">
                Short message
                <textarea
                  className="ead-input ead-textarea"
                  rows={3}
                  maxLength={INTRO_MAX}
                  value={intro}
                  onChange={(e) => setIntro(e.target.value)}
                  placeholder="Short note above the product list�"
                />
                <span className="ead-counter">
                  {introLen}/{INTRO_MAX}
                </span>
              </label>
            </div>
          </Step>

          <Step n="2" title="Choose products">
            {selectedProducts.length > 0 ? (
              <>
                <div className="ead-selected-head">
                  <span className="ead-selected-label">Selected products ({selectedProducts.length})</span>
                  <button type="button" className="ead-link" onClick={clearProducts}>
                    Clear all
                  </button>
                </div>
                <div className="ead-selected-strip">
                  {selectedProducts.map((p) => (
                    <div key={p.id} className="ead-chip">
                      <ProductThumb product={p} />
                      <div className="ead-chip-text">
                        <span>{p.name}</span>
                        <small>{p.product_code || '�'}</small>
                      </div>
                      <button type="button" className="ead-chip-x" onClick={() => removeProduct(p.id)} aria-label="Remove">
                        <HiOutlineTrash />
                      </button>
                    </div>
                  ))}
                </div>
                <button type="button" className="ead-catalog-open ead-catalog-open--slim" onClick={openSalesCatalogue}>
                  <HiOutlineCube aria-hidden="true" />
                  <span>View & select catalogue</span>
                </button>
              </>
            ) : (
              <div className="ead-catalog-link-wrap">
                <button type="button" className="ead-catalog-link" onClick={openSalesCatalogue}>
                  <HiOutlineCube aria-hidden="true" />
                  View & select catalogue
                </button>
                <p className="ead-inline-hint">No products selected yet. Browse products and add them to this email.</p>
              </div>
            )}
          </Step>

          <Step n="3" title="Choose who receives it">
            <div className="ead-choice-list" role="radiogroup" aria-label="Audience">
              <label className={`ead-choice${audienceMode === 'all' ? ' is-on' : ''}`}>
                <input
                  type="radio"
                  name="audience"
                  checked={audienceMode === 'all'}
                  onChange={() => setAudienceMode('all')}
                />
                <span>
                  <strong>All customers with email</strong>
                  <small>{customers.length} active customers currently have an email on file.</small>
                </span>
              </label>
              <label className={`ead-choice${audienceMode === 'selected' ? ' is-on' : ''}`}>
                <input
                  type="radio"
                  name="audience"
                  checked={audienceMode === 'selected'}
                  onChange={() => setAudienceMode('selected')}
                />
                <span>
                  <strong>Pick customers</strong>
                  <small>Choose specific people from your customer list.</small>
                </span>
              </label>
            </div>

            {audienceMode === 'selected' ? (
              <div className="ead-pick-list">
                {customers.map((c) => (
                  <label key={c.id} className="ead-check-row">
                    <input
                      type="checkbox"
                      checked={selectedCustomerIds.includes(c.id)}
                      onChange={() => toggleCustomer(c.id)}
                    />
                    <span>
                      <strong>{c.company_name || c.name}</strong>
                      <small>{c.email}</small>
                    </span>
                  </label>
                ))}
                {customers.length === 0 ? <div className="ead-empty">No customers have email addresses.</div> : null}
              </div>
            ) : null}
          </Step>

          <Step n="4" title="Send">
            <div className="ead-choice-list" role="radiogroup" aria-label="When to send">
              <label className={`ead-choice${scheduleMode === 'now' ? ' is-on' : ''}`}>
                <input
                  type="radio"
                  name="schedule"
                  checked={scheduleMode === 'now'}
                  onChange={() => setScheduleMode('now')}
                />
                <span>
                  <strong>Send now</strong>
                  <small>Deliver immediately after you click Send email now.</small>
                </span>
              </label>
              <label className={`ead-choice${scheduleMode === 'later' ? ' is-on' : ''}`}>
                <input
                  type="radio"
                  name="schedule"
                  checked={scheduleMode === 'later'}
                  onChange={() => setScheduleMode('later')}
                />
                <span>
                  <strong>Schedule for later</strong>
                  <small>Choose a date and time for automatic sending.</small>
                </span>
              </label>
            </div>
            {scheduleMode === 'later' ? (
              <label className="ead-label">
                Date and time
                <input
                  className="ead-input"
                  type="datetime-local"
                  value={scheduledAt}
                  onChange={(e) => setScheduledAt(e.target.value)}
                />
              </label>
            ) : null}
          </Step>
        </div>

        <aside className="ead-aside">
          <EmailPreview
            companyName={companyName}
            siteHost={siteHost}
            headline={headline}
            intro={intro}
            ctaLabel={ctaLabel}
            products={selectedProducts}
            previewMode={previewMode}
            onPreviewMode={setPreviewMode}
          />
        </aside>
      </div>

      <section className="ead-history">
        <div className="ead-history-head">
          <HiOutlineDocumentText aria-hidden="true" />
          <h2>Previous campaigns</h2>
        </div>
        {campaigns.length === 0 ? (
          <div className="ead-empty ead-empty--block">No campaigns yet. Send or save a draft to see them here.</div>
        ) : (
          <div className="ead-table-wrap">
            <table className="ead-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Name</th>
                  <th>Subject</th>
                  <th>Recipients</th>
                  <th>Status</th>
                  <th>Scheduled</th>
                  <th>Sent</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {campaigns.map((c, i) => {
                  const st = statusMeta(c.status);
                  const canResend = c.status === 'draft' || c.status === 'scheduled' || c.status === 'failed';
                  const recipients =
                    c.recipient_count ?? c.sent_count ?? c.audience_count ?? c.recipients ?? '�';
                  return (
                    <tr key={c.id}>
                      <td>{i + 1}</td>
                      <td>{c.name || '�'}</td>
                      <td>{c.subject || '�'}</td>
                      <td>{recipients}</td>
                      <td>
                        <span className={`ead-badge ${st.className}`}>{st.label}</span>
                      </td>
                      <td>{formatWhen(c.scheduled_at)}</td>
                      <td>{formatWhen(c.sent_at)}</td>
                      <td>
                        <div className="ead-row-actions">
                          {canResend ? (
                            <button
                              type="button"
                              className="ead-icon-btn"
                              title="Send now"
                              disabled={busy}
                              onClick={() => sendExisting(c.id)}
                            >
                              <HiOutlinePaperAirplane />
                            </button>
                          ) : (
                            <span className="ead-muted-dash">�</span>
                          )}
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
    </div>
  );
}
