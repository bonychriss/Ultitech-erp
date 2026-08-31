import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from 'react';
import { ArrowLeft, Loader2 } from 'lucide-react';
import { fetchEditInit, submitEditCustomer } from '../api/catalogueDesk';
import CountrySelect from '../components/CountrySelect.jsx';
import CurrencySelect from '../components/CurrencySelect.jsx';

const SECTIONS = [
  { id: 'general-info', label: 'General' },
  { id: 'contact-address', label: 'Contact & Address' },
  { id: 'tax-info', label: 'Tax' },
];

function scrollToSection(id) {
  const el = document.getElementById(id);
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export default function CustomerEditPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [activeSection, setActiveSection] = useState(SECTIONS[0].id);
  const [form, setForm] = useState(null);

  const loadInit = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams(window.location.search);
      if (!params.get('id') && window.__CUSTOMERS_DESK_CFG__?.customer_id) {
        params.set('id', String(window.__CUSTOMERS_DESK_CFG__.customer_id));
      }
      const payload = await fetchEditInit(params);
      setInit(payload);
      setForm({ ...(payload.defaults || {}) });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load customer form.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadInit();
  }, [loadInit]);

  useEffect(() => {
    const sections = SECTIONS.map((s) => document.getElementById(s.id)).filter(Boolean);
    if (sections.length === 0) return undefined;

    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((entry) => entry.isIntersecting)
          .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
        if (visible[0]?.target?.id) {
          setActiveSection(visible[0].target.id);
        }
      },
      { rootMargin: '-30% 0px -55% 0px', threshold: [0.1, 0.35, 0.6] },
    );

    sections.forEach((section) => observer.observe(section));
    return () => observer.disconnect();
  }, [init]);

  const options = init?.options || {};
  const urls = init?.urls || {};

  const customerTypes = useMemo(
    () => options.customer_types || [],
    [options.customer_types],
  );
  const paymentTerms = useMemo(
    () => options.payment_terms || [],
    [options.payment_terms],
  );
  const currencies = useMemo(
    () => options.currencies || [],
    [options.currencies],
  );
  const countries = useMemo(
    () => options.countries || [],
    [options.countries],
  );
  const citiesByCountry = useMemo(
    () => options.cities_by_country || {},
    [options.cities_by_country],
  );
  const availableCities = useMemo(() => {
    if (!form?.country) return [];
    return citiesByCountry[form.country] || [];
  }, [citiesByCountry, form?.country]);

  useEffect(() => {
    if (!form?.country || !form.city) return;
    const allowed = citiesByCountry[form.country] || [];
    if (allowed.some((opt) => opt.value === form.city)) return;
    setForm((prev) => (prev ? { ...prev, city: '' } : prev));
  }, [citiesByCountry, form?.country, form?.city]);

  function updateField(name, value) {
    setForm((prev) => {
      if (name === 'country') {
        return { ...prev, country: value, city: '' };
      }
      return { ...prev, [name]: value };
    });
  }

  async function handleSubmit(e) {
    e.preventDefault();
    if (!form || saving) return;

    setSaving(true);
    setError('');
    try {
      const result = await submitEditCustomer(form);
      if (result.redirect_url) {
        window.location.href = result.redirect_url;
        return;
      }
      setError(result.error || 'Could not update customer.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not update customer.');
    } finally {
      setSaving(false);
    }
  }

  if (loading && !init) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading form...</span>
      </div>
    );
  }

  if (!form) {
    return (
      <div className="exp-desk-page">
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">
          {error || 'Could not load customer form.'}
        </div>
      </div>
    );
  }

  return (
    <div className="exp-desk-page">
      <div className="exp-create-shell ca-shell">
        <div className="exp-create-topbar ca-topbar">
          {urls.view && (
            <a href={urls.view} className="ca-back-link">
              <ArrowLeft size={16} aria-hidden="true" />
              Back to Customer
            </a>
          )}
        </div>

        {error && (
          <div className="exp-create-alert exp-create-alert--error" role="alert">{error}</div>
        )}

        <form onSubmit={handleSubmit}>
          <div className="ca-layout">
            <aside className="exp-create-nav ca-nav" aria-label="Form sections">
              <ul>
                {SECTIONS.map((section) => (
                  <li key={section.id}>
                    <button
                      type="button"
                      className={activeSection === section.id ? 'is-active' : ''}
                      onClick={() => {
                        setActiveSection(section.id);
                        scrollToSection(section.id);
                      }}
                    >
                      {section.label}
                    </button>
                  </li>
                ))}
              </ul>
            </aside>

            <div className="exp-create-main">
              <section className="exp-create-section" id="general-info">
                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-customer-code">Customer Code</label>
                  <div>
                    <input
                      id="ca-customer-code"
                      type="text"
                      readOnly
                      className="exp-create-input exp-create-input--readonly ca-code-input"
                      value={form.customer_code || ''}
                    />
                    <div className="exp-create-help">
                      This code is assigned permanently and cannot be changed.
                    </div>
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-company-name">
                    Company Name
                    <span className="req">*</span>
                  </label>
                  <div>
                    <input
                      id="ca-company-name"
                      type="text"
                      required
                      className="exp-create-input"
                      placeholder="e.g. Ultimate Trading Company"
                      value={form.company_name}
                      onChange={(e) => updateField('company_name', e.target.value)}
                    />
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-customer-type">
                    Customer Type
                    <span className="req">*</span>
                  </label>
                  <div>
                    <select
                      id="ca-customer-type"
                      required
                      className="exp-create-select"
                      value={form.customer_type}
                      onChange={(e) => updateField('customer_type', e.target.value)}
                    >
                      {customerTypes.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                      ))}
                    </select>
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-payment-terms">
                    Payment Terms{' '}
                    <span className="exp-create-label-hint">(days)</span>
                  </label>
                  <div>
                    <select
                      id="ca-payment-terms"
                      className="exp-create-select"
                      value={form.payment_terms}
                      onChange={(e) => updateField('payment_terms', e.target.value)}
                    >
                      {paymentTerms.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                      ))}
                    </select>
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-currency">Currency</label>
                  <div>
                    <CurrencySelect
                      id="ca-currency"
                      required
                      value={form.currency}
                      options={currencies}
                      placeholder="Select currency"
                      onChange={(value) => updateField('currency', value)}
                    />
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-credit-limit">Credit Limit</label>
                  <div>
                    <input
                      id="ca-credit-limit"
                      type="number"
                      step="0.01"
                      min="0"
                      className="exp-create-input exp-create-input--price ca-credit-input"
                      placeholder="0.00"
                      value={form.credit_limit}
                      onChange={(e) => updateField('credit_limit', e.target.value)}
                    />
                  </div>
                </div>
              </section>

              <section className="exp-create-section" id="contact-address">
                <div className="exp-create-section-header">
                  <h2>Contact &amp; Address</h2>
                  <p className="exp-create-help">Primary contact details and billing location.</p>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-contact-person">
                    Contact Person
                    <span className="req">*</span>
                  </label>
                  <div>
                    <input
                      id="ca-contact-person"
                      type="text"
                      required
                      className="exp-create-input"
                      placeholder="e.g. John Doe"
                      value={form.contact_person}
                      onChange={(e) => updateField('contact_person', e.target.value)}
                    />
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-email">
                    Email
                    <span className="req">*</span>
                  </label>
                  <div>
                    <input
                      id="ca-email"
                      type="email"
                      required
                      className="exp-create-input"
                      placeholder="e.g. john.doe@example.com"
                      value={form.email}
                      onChange={(e) => updateField('email', e.target.value)}
                    />
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-phone">
                    Phone
                    <span className="req">*</span>
                  </label>
                  <div>
                    <input
                      id="ca-phone"
                      type="text"
                      required
                      className="exp-create-input"
                      placeholder="e.g. +255 123 456 789"
                      value={form.phone}
                      onChange={(e) => updateField('phone', e.target.value)}
                    />
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-address">
                    Address
                    <span className="req">*</span>
                  </label>
                  <div>
                    <textarea
                      id="ca-address"
                      required
                      rows={3}
                      className="exp-create-textarea"
                      placeholder="e.g. Plot 12, Nyerere Road, P.O. Box 75, Dar es Salaam"
                      value={form.address}
                      onChange={(e) => updateField('address', e.target.value)}
                    />
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-country">
                    Country
                    <span className="req">*</span>
                  </label>
                  <div>
                    <CountrySelect
                      id="ca-country"
                      required
                      value={form.country}
                      options={countries}
                      placeholder="Select country"
                      onChange={(value) => updateField('country', value)}
                    />
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-city">
                    City
                    <span className="req">*</span>
                  </label>
                  <div>
                    <select
                      key={form.country || 'no-country'}
                      id="ca-city"
                      required={Boolean(form.country)}
                      className="exp-create-select"
                      value={form.city}
                      disabled={!form.country || availableCities.length === 0}
                      onChange={(e) => updateField('city', e.target.value)}
                    >
                      <option value="">
                        {!form.country
                          ? 'Select country first'
                          : availableCities.length === 0
                            ? 'No cities for this country'
                            : 'Select city'}
                      </option>
                      {availableCities.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                      ))}
                    </select>
                    {form.country && availableCities.length > 0 && (
                      <p className="exp-create-help ca-city-help">
                        {availableCities.length} cities in {form.country}
                      </p>
                    )}
                  </div>
                </div>
              </section>

              <section className="exp-create-section" id="tax-info">
                <div className="exp-create-section-header">
                  <h2>Tax</h2>
                  <p className="exp-create-help">Optional tax identifiers.</p>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-tin">TIN Number</label>
                  <div>
                    <input
                      id="ca-tin"
                      type="text"
                      className="exp-create-input"
                      placeholder="e.g. 123-456-789"
                      value={form.tin}
                      onChange={(e) => updateField('tin', e.target.value)}
                    />
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="ca-vrn">VRN Number</label>
                  <div>
                    <input
                      id="ca-vrn"
                      type="text"
                      className="exp-create-input"
                      placeholder="e.g. 10-123456-X"
                      value={form.vrn}
                      onChange={(e) => updateField('vrn', e.target.value)}
                    />
                  </div>
                </div>
              </section>

              <div className="ca-form-actions">
                {(urls.view || urls.index) && (
                  <a
                    href={urls.view || urls.index}
                    className="exp-desk-btn exp-desk-btn-secondary ca-cancel-btn"
                  >
                    Cancel
                  </a>
                )}
                <button
                  type="submit"
                  className="exp-create-btn-save ca-save-btn"
                  disabled={saving}
                >
                  {saving ? 'Saving...' : 'Save Customer'}
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
  );
}
