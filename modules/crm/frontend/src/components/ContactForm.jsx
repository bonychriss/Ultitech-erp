export default function ContactForm({
  form,
  statuses,
  saving,
  error,
  isNew,
  onFieldChange,
  onSubmit,
  onCancel,
  onDelete,
  idPrefix = 'contact',
}) {
  return (
    <>
      {error && <div className="contact-form-alert contact-form-alert-error">{error}</div>}
      <form className="contact-form" onSubmit={onSubmit}>
        <div className="contact-form-field">
          <label htmlFor={`${idPrefix}-name`}>Name *</label>
          <input
            id={`${idPrefix}-name`}
            className="contact-form-input"
            value={form.name}
            onChange={(e) => onFieldChange('name', e.target.value)}
            required
            autoFocus
          />
        </div>
        <div className="contact-form-field">
          <label htmlFor={`${idPrefix}-organization`}>Organization</label>
          <input
            id={`${idPrefix}-organization`}
            className="contact-form-input"
            value={form.organization}
            onChange={(e) => onFieldChange('organization', e.target.value)}
          />
        </div>
        <div className="contact-form-field">
          <label htmlFor={`${idPrefix}-status`}>Status</label>
          <select
            id={`${idPrefix}-status`}
            className="contact-form-select"
            value={form.status}
            onChange={(e) => onFieldChange('status', e.target.value)}
          >
            {statuses.map((s) => (
              <option key={s.value} value={s.value}>{s.label}</option>
            ))}
          </select>
        </div>
        <div className="contact-form-field">
          <label htmlFor={`${idPrefix}-email`}>Email</label>
          <input
            id={`${idPrefix}-email`}
            type="email"
            className="contact-form-input"
            value={form.email}
            onChange={(e) => onFieldChange('email', e.target.value)}
          />
        </div>
        <div className="contact-form-field">
          <label htmlFor={`${idPrefix}-phone`}>Phone</label>
          <input
            id={`${idPrefix}-phone`}
            className="contact-form-input"
            value={form.phone}
            onChange={(e) => onFieldChange('phone', e.target.value)}
          />
        </div>
        <div className="contact-form-field">
          <label htmlFor={`${idPrefix}-source`}>Source</label>
          <input
            id={`${idPrefix}-source`}
            className="contact-form-input"
            placeholder="Referral, website, trade show..."
            value={form.source}
            onChange={(e) => onFieldChange('source', e.target.value)}
          />
        </div>
        <div className="contact-form-field">
          <label htmlFor={`${idPrefix}-notes`}>Notes</label>
          <textarea
            id={`${idPrefix}-notes`}
            className="contact-form-textarea"
            rows={2}
            value={form.notes}
            onChange={(e) => onFieldChange('notes', e.target.value)}
          />
        </div>
        <div className="contact-form-actions">
          <button type="submit" className="contact-form-btn contact-form-btn-primary" disabled={saving}>
            {saving ? 'Saving...' : isNew ? 'Add client' : 'Save Changes'}
          </button>
          {!isNew && onDelete && (
            <button type="button" className="contact-form-btn contact-form-btn-danger" onClick={onDelete} disabled={saving}>
              Delete
            </button>
          )}
          <button type="button" className="contact-form-btn contact-form-btn-secondary" onClick={onCancel} disabled={saving}>
            Cancel
          </button>
        </div>
      </form>
    </>
  );
}
