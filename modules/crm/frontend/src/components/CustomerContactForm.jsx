import { useEffect, useMemo } from 'react';
import CountrySelect from './CountrySelect.jsx';
import CurrencySelect from './CurrencySelect.jsx';

export default function CustomerContactForm({
  form,
  options,
  statuses,
  saving,
  error,
  isNew,
  idPrefix = 'crm',
  onFieldChange,
  onSubmit,
  onCancel,
  onDelete,
}) {
  const countries = useMemo(() => options?.countries || [], [options?.countries]);
  const paymentTerms = useMemo(() => options?.payment_terms || [], [options?.payment_terms]);
  const currencies = useMemo(() => options?.currencies || [], [options?.currencies]);
  const citiesByCountry = useMemo(
    () => options?.cities_by_country || {},
    [options?.cities_by_country],
  );
  const availableCities = useMemo(() => {
    if (!form?.country) return [];
    return citiesByCountry[form.country] || [];
  }, [citiesByCountry, form?.country]);

  useEffect(() => {
    if (!form?.country || !form.city) return;
    const allowed = citiesByCountry[form.country] || [];
    if (allowed.some((opt) => opt.value === form.city)) return;
    onFieldChange('city', '');
  }, [citiesByCountry, form?.country, form?.city]);

  return (
    <>
      {error && <div className="crm-form-alert crm-form-alert-error">{error}</div>}
      <form className="crm-customer-form" onSubmit={onSubmit}>
        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-company-name`}>
            Company Name
            <span className="req">*</span>
          </label>
          <input
            id={`${idPrefix}-company-name`}
            className="crm-field-input"
            placeholder="e.g. Ultimate Trading Company"
            value={form.company_name || ''}
            onChange={(e) => onFieldChange('company_name', e.target.value)}
            required
            autoFocus
          />
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-contact-person`}>
            Contact Person
            <span className="req">*</span>
          </label>
          <input
            id={`${idPrefix}-contact-person`}
            className="crm-field-input"
            placeholder="e.g. John Doe"
            value={form.contact_person || ''}
            onChange={(e) => onFieldChange('contact_person', e.target.value)}
            required
          />
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-email`}>
            Email
            <span className="req">*</span>
          </label>
          <input
            id={`${idPrefix}-email`}
            type="email"
            className="crm-field-input"
            placeholder="e.g. john.doe@example.com"
            value={form.email || ''}
            onChange={(e) => onFieldChange('email', e.target.value)}
            required
          />
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-phone`}>
            Phone
            <span className="req">*</span>
          </label>
          <input
            id={`${idPrefix}-phone`}
            className="crm-field-input"
            placeholder="e.g. +255 123 456 789"
            value={form.phone || ''}
            onChange={(e) => onFieldChange('phone', e.target.value)}
            required
          />
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-source`}>
            Source
            <span className="req">*</span>
          </label>
          <input
            id={`${idPrefix}-source`}
            className="crm-field-input"
            placeholder="Referral, website, trade show..."
            value={form.source || ''}
            onChange={(e) => onFieldChange('source', e.target.value)}
            required
          />
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-address`}>
            Address
            <span className="req">*</span>
          </label>
          <textarea
            id={`${idPrefix}-address`}
            className="crm-field-textarea"
            rows={3}
            placeholder="e.g. Plot 12, Nyerere Road, P.O. Box 75, Dar es Salaam"
            value={form.address || ''}
            onChange={(e) => onFieldChange('address', e.target.value)}
            required
          />
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-country`}>
            Country
            <span className="req">*</span>
          </label>
          <CountrySelect
            id={`${idPrefix}-country`}
            required
            value={form.country || ''}
            options={countries}
            placeholder="Select country"
            onChange={(value) => onFieldChange('country', value)}
          />
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-city`}>
            City
            <span className="req">*</span>
          </label>
          <select
            key={form.country || 'no-country'}
            id={`${idPrefix}-city`}
            className="crm-field-input crm-field-select"
            value={form.city || ''}
            required={Boolean(form.country)}
            disabled={!form.country || availableCities.length === 0}
            onChange={(e) => onFieldChange('city', e.target.value)}
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

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-tin`}>TIN Number</label>
          <input
            id={`${idPrefix}-tin`}
            className="crm-field-input"
            placeholder="e.g. 123-456-789"
            value={form.tin || ''}
            onChange={(e) => onFieldChange('tin', e.target.value)}
          />
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-vrn`}>VRN Number</label>
          <input
            id={`${idPrefix}-vrn`}
            className="crm-field-input"
            placeholder="e.g. 10-123456-X"
            value={form.vrn || ''}
            onChange={(e) => onFieldChange('vrn', e.target.value)}
          />
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-status`}>
            Status
            <span className="req">*</span>
          </label>
          <select
            id={`${idPrefix}-status`}
            className="crm-field-input crm-field-select"
            value={form.status || 'lead'}
            onChange={(e) => onFieldChange('status', e.target.value)}
            required
          >
            {statuses.map((opt) => (
              <option key={opt.value} value={opt.value}>{opt.label}</option>
            ))}
          </select>
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-payment-terms`}>
            Payment Terms <span className="crm-label-hint">(days)</span>
          </label>
          <select
            id={`${idPrefix}-payment-terms`}
            className="crm-field-input crm-field-select"
            value={form.payment_terms || 'Net 30'}
            onChange={(e) => onFieldChange('payment_terms', e.target.value)}
          >
            {paymentTerms.map((opt) => (
              <option key={opt.value} value={opt.value}>{opt.label}</option>
            ))}
          </select>
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-currency`}>Currency</label>
          <CurrencySelect
            id={`${idPrefix}-currency`}
            required
            value={form.currency || 'TZS'}
            options={currencies}
            placeholder="Select currency"
            onChange={(value) => onFieldChange('currency', value)}
          />
        </div>

        <div className="crm-form-field">
          <label htmlFor={`${idPrefix}-credit-limit`}>Credit Limit</label>
          <input
            id={`${idPrefix}-credit-limit`}
            type="number"
            step="0.01"
            min="0"
            className="crm-field-input crm-credit-input"
            placeholder="0.00"
            value={form.credit_limit ?? '0.00'}
            onChange={(e) => onFieldChange('credit_limit', e.target.value)}
          />
        </div>

        <div className="crm-form-actions">
          {!isNew && onDelete && (
            <button type="button" className="crm-form-btn crm-form-btn-danger" onClick={onDelete} disabled={saving}>
              Delete
            </button>
          )}
          <button type="button" className="crm-form-btn crm-form-btn-secondary" onClick={onCancel} disabled={saving}>
            Cancel
          </button>
          <button type="submit" className="crm-form-btn crm-form-btn-primary" disabled={saving}>
            {saving ? 'Saving...' : isNew ? 'Add client' : 'Save Changes'}
          </button>
        </div>
      </form>
    </>
  );
}
