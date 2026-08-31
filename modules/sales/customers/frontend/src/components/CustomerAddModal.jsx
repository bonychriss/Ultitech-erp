import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from 'react';
import { Loader2, X } from 'lucide-react';
import { fetchAddInit, submitAddCustomer } from '../api/catalogueDesk';
import CountrySelect from './CountrySelect.jsx';
import CurrencySelect from './CurrencySelect.jsx';
import { useBottomSheet } from '../hooks/useBottomSheet.js';

export default function CustomerAddModal({
  open,
  onClose,
  onSuccess,
  idPrefix = 'ca',
  title = 'Add client',
}) {
  const [init, setInit] = useState(null);
  const [form, setForm] = useState(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const loadInit = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams(window.location.search);
      const payload = await fetchAddInit(params);
      setInit(payload);
      setForm({ ...(payload.defaults || {}) });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load form.');
      setInit(null);
      setForm(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!open) {
      setInit(null);
      setForm(null);
      setError('');
      setSaving(false);
      return;
    }
    loadInit();
  }, [open, loadInit]);

  useEffect(() => {
    if (!open) return undefined;

    function onKeyDown(e) {
      if (e.key === 'Escape' && !saving) onClose();
    }

    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKeyDown);

    return () => {
      document.body.style.overflow = '';
      window.removeEventListener('keydown', onKeyDown);
    };
  }, [open, onClose, saving]);

  const options = init?.options || {};
  const statuses = useMemo(
    () => init?.statuses || [],
    [init?.statuses],
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
      if (!prev) return prev;
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
      const result = await submitAddCustomer(form);
      if (result.error) {
        setError(result.error);
        return;
      }
      if (typeof onSuccess === 'function') {
        onSuccess(result);
        return;
      }
      if (result.redirect_url) {
        window.location.href = result.redirect_url;
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not add customer.');
    } finally {
      setSaving(false);
    }
  }

  const { isMobileSheet, sheetStyle, sheetClassName, grabProps } = useBottomSheet({
    open,
    onClose,
    disabled: saving,
  });

  if (!open) return null;

  return (
    <div className="ca-modal-overlay" onClick={saving ? undefined : onClose} role="presentation">
      <div
        className={`ca-modal${sheetClassName ? ` ${sheetClassName}` : ''}`}
        style={sheetStyle}
        role="dialog"
        aria-modal="true"
        aria-labelledby={`${idPrefix}-modal-title`}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="ca-sheet-grab" {...(grabProps || {})}>
          {isMobileSheet && (
            <div className="ca-sheet-handle" aria-hidden="true">
              <span className="ca-sheet-handle-bar" />
            </div>
          )}
          <div className="ca-modal-header">
            <h2 id={`${idPrefix}-modal-title`} className="ca-modal-title">{title}</h2>
            <button
              type="button"
              className="ca-modal-close"
              onClick={onClose}
              disabled={saving}
              aria-label="Close"
            >
              <X size={18} aria-hidden="true" />
            </button>
          </div>
        </div>

        <div className="ca-modal-body">
          {loading || !form ? (
            <div className="ca-modal-loading" role="status">
              <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
              <span>Loading form...</span>
            </div>
          ) : (
            <>
              {error && (
                <div className="exp-create-alert exp-create-alert--error" role="alert">{error}</div>
              )}

              <form onSubmit={handleSubmit}>
                <div className="ca-modal-main">
                  <section className="exp-create-section">
                    <div className="exp-create-row">
                      <label className="exp-create-label" htmlFor={`${idPrefix}-company-name`}>
                        Company Name
                        <span className="req">*</span>
                      </label>
                      <div>
                        <input
                          id={`${idPrefix}-company-name`}
                          type="text"
                          required
                          className="exp-create-input"
                          placeholder="e.g. Ultimate Trading Company"
                          value={form.company_name}
                          onChange={(e) => updateField('company_name', e.target.value)}
                          autoFocus
                        />
                      </div>
                    </div>
                  </section>

                  <section className="exp-create-section">
                    <div className="exp-create-row">
                      <label className="exp-create-label" htmlFor={`${idPrefix}-contact-person`}>
                        Contact Person
                        <span className="req">*</span>
                      </label>
                      <div>
                        <input
                          id={`${idPrefix}-contact-person`}
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
                      <label className="exp-create-label" htmlFor={`${idPrefix}-email`}>
                        Email
                        <span className="req">*</span>
                      </label>
                      <div>
                        <input
                          id={`${idPrefix}-email`}
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
                      <label className="exp-create-label" htmlFor={`${idPrefix}-phone`}>
                        Phone
                        <span className="req">*</span>
                      </label>
                      <div>
                        <input
                          id={`${idPrefix}-phone`}
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
                      <label className="exp-create-label" htmlFor={`${idPrefix}-source`}>
                        Source
                        <span className="req">*</span>
                      </label>
                      <div>
                        <input
                          id={`${idPrefix}-source`}
                          type="text"
                          required
                          className="exp-create-input"
                          placeholder="Referral, website, trade show..."
                          value={form.source}
                          onChange={(e) => updateField('source', e.target.value)}
                        />
                      </div>
                    </div>

                    <div className="exp-create-row">
                      <label className="exp-create-label" htmlFor={`${idPrefix}-address`}>
                        Address
                        <span className="req">*</span>
                      </label>
                      <div>
                        <textarea
                          id={`${idPrefix}-address`}
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
                      <label className="exp-create-label" htmlFor={`${idPrefix}-country`}>
                        Country
                        <span className="req">*</span>
                      </label>
                      <div>
                        <CountrySelect
                          id={`${idPrefix}-country`}
                          required
                          value={form.country}
                          options={countries}
                          placeholder="Select country"
                          onChange={(value) => updateField('country', value)}
                        />
                      </div>
                    </div>

                    <div className="exp-create-row">
                      <label className="exp-create-label" htmlFor={`${idPrefix}-city`}>
                        City
                        <span className="req">*</span>
                      </label>
                      <div>
                        <select
                          key={form.country || 'no-country'}
                          id={`${idPrefix}-city`}
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
                      </div>
                    </div>
                  </section>

                  <section className="exp-create-section">
                    <div className="exp-create-row">
                      <label className="exp-create-label" htmlFor={`${idPrefix}-tin`}>TIN Number</label>
                      <div>
                        <input
                          id={`${idPrefix}-tin`}
                          type="text"
                          className="exp-create-input"
                          placeholder="e.g. 123-456-789"
                          value={form.tin}
                          onChange={(e) => updateField('tin', e.target.value)}
                        />
                      </div>
                    </div>

                    <div className="exp-create-row">
                      <label className="exp-create-label" htmlFor={`${idPrefix}-vrn`}>VRN Number</label>
                      <div>
                        <input
                          id={`${idPrefix}-vrn`}
                          type="text"
                          className="exp-create-input"
                          placeholder="e.g. 10-123456-X"
                          value={form.vrn}
                          onChange={(e) => updateField('vrn', e.target.value)}
                        />
                      </div>
                    </div>
                  </section>

                  <section className="exp-create-section">
                    <div className="exp-create-row">
                      <label className="exp-create-label" htmlFor={`${idPrefix}-status`}>
                        Status
                        <span className="req">*</span>
                      </label>
                      <div>
                        <select
                          id={`${idPrefix}-status`}
                          required
                          className="exp-create-select"
                          value={form.status}
                          onChange={(e) => updateField('status', e.target.value)}
                        >
                          {statuses.map((opt) => (
                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                          ))}
                        </select>
                      </div>
                    </div>

                    <div className="exp-create-row">
                      <label className="exp-create-label" htmlFor={`${idPrefix}-payment-terms`}>
                        Payment Terms{' '}
                        <span className="exp-create-label-hint">(days)</span>
                      </label>
                      <div>
                        <select
                          id={`${idPrefix}-payment-terms`}
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
                      <label className="exp-create-label" htmlFor={`${idPrefix}-currency`}>Currency</label>
                      <div>
                        <CurrencySelect
                          id={`${idPrefix}-currency`}
                          required
                          value={form.currency}
                          options={currencies}
                          placeholder="Select currency"
                          onChange={(value) => updateField('currency', value)}
                        />
                      </div>
                    </div>

                    <div className="exp-create-row">
                      <label className="exp-create-label" htmlFor={`${idPrefix}-credit-limit`}>Credit Limit</label>
                      <div>
                        <input
                          id={`${idPrefix}-credit-limit`}
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

                  <div className="ca-form-actions ca-form-actions--modal">
                    <button
                      type="button"
                      className="exp-desk-btn exp-desk-btn-secondary ca-cancel-btn"
                      onClick={onClose}
                      disabled={saving}
                    >
                      Cancel
                    </button>
                    <button
                      type="submit"
                      className="exp-create-btn-save ca-save-btn"
                      disabled={saving}
                    >
                      {saving ? 'Saving...' : 'Add client'}
                    </button>
                  </div>
                </div>
              </form>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
