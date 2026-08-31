import React, { useEffect, useMemo, useState } from 'react';
import { HiOutlineArrowLeft, HiOutlineArrowPath, HiOutlineCheck } from 'react-icons/hi2';
import './products-desk.css';
import './suppliers-desk.css';

function cssStringToStyle(css) {
  if (!css || typeof css !== 'string') return undefined;
  const out = {};
  css.split(';').forEach((part) => {
    const idx = part.indexOf(':');
    if (idx < 1) return;
    const rawKey = part.slice(0, idx).trim();
    const value = part.slice(idx + 1).trim();
    if (!rawKey || !value) return;
    const key = rawKey.replace(/-([a-z])/gi, (_, c) => String(c).toUpperCase());
    out[key] = value;
  });
  return Object.keys(out).length ? out : undefined;
}

function decodeHtmlEntities(value) {
  const raw = String(value ?? '');
  if (!raw || (raw.indexOf('&') === -1 && raw.indexOf('<') === -1)) return raw;
  if (typeof document !== 'undefined') {
    const el = document.createElement('textarea');
    let prev = raw;
    // Decode repeatedly in case values were stored as &amp;amp;
    for (let i = 0; i < 3; i += 1) {
      el.innerHTML = prev;
      const next = el.value;
      if (next === prev) break;
      prev = next;
    }
    return prev;
  }
  return raw
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&apos;/g, "'");
}

function initialsFromName(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (!parts.length) return 'SU';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return `${parts[0][0] || ''}${parts[1][0] || ''}`.toUpperCase();
}

export default function SupplierEdit({ data }) {
  const {
    mode = 'edit',
    isRoadmaster = false,
    showDepartment = false,
    indexUrl = 'index.php',
    viewUrl = 'view.php',
    formAction = 'edit.php',
    error = '',
    supplier = {},
  } = data;

  const isAdd = mode === 'add';

  const [activeSection, setActiveSection] = useState('general');
  const [saving, setSaving] = useState(false);
  const [name, setName] = useState(() => decodeHtmlEntities(supplier.name || ''));

  const code = isAdd ? 'Auto-generated on save' : String(supplier.supplier_code || '');
  const avatarStyle = useMemo(() => cssStringToStyle(supplier.avatar_style), [supplier.avatar_style]);
  const initials = supplier.initials || initialsFromName(name) || (isAdd ? 'SU' : 'SU');
  const contactPerson = decodeHtmlEntities(supplier.contact_person || '');
  const phone = decodeHtmlEntities(supplier.phone || '');
  const email = decodeHtmlEntities(supplier.email || '');
  const address = decodeHtmlEntities(supplier.address || '');
  const notes = decodeHtmlEntities(supplier.notes || '');
  const defaultType = supplier.supplier_type || 'general';

  useEffect(() => {
    const sections = [
      { id: 'general', el: document.getElementById('supplier-general') },
      { id: 'contact', el: document.getElementById('supplier-contact') },
    ];
    const onScroll = () => {
      const y = window.scrollY + 140;
      let current = 'general';
      sections.forEach((s) => {
        if (s.el && s.el.offsetTop <= y) current = s.id;
      });
      setActiveSection(current);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  return (
    <div className="prod-desk-page supplier-edit">
      <div className="supplier-edit-top">
        <div className="supplier-edit-top-lead">
          <span className="supplier-desk-avatar supplier-edit-avatar" style={avatarStyle} aria-hidden="true">
            {initials}
          </span>
          <div className="supplier-edit-top-meta">
            <p className="supplier-edit-name">{name || (isAdd ? 'New supplier' : 'Supplier')}</p>
            <p className="supplier-edit-sub">{isAdd ? 'Code assigned on save' : code}</p>
          </div>
        </div>
        <div className="supplier-edit-top-actions">
          {!isAdd && supplier.id ? (
            <a href={`${viewUrl}?id=${supplier.id}`} className="prod-desk-btn prod-desk-btn-secondary supplier-desk-btn">
              View
            </a>
          ) : null}
          <a href={indexUrl} className="prod-desk-btn prod-desk-btn-secondary supplier-desk-btn">
            <HiOutlineArrowLeft size={16} aria-hidden="true" /> Back
          </a>
        </div>
      </div>

      {error ? <div className="supplier-edit-error">{error}</div> : null}

      <form
        method="POST"
        action={formAction}
        className="supplier-edit-layout"
        onSubmit={() => setSaving(true)}
      >
        <aside className="supplier-edit-nav" aria-label="Form sections">
          <a
            href="#supplier-general"
            className={activeSection === 'general' ? 'is-active' : ''}
            onClick={() => setActiveSection('general')}
          >
            General
          </a>
          <a
            href="#supplier-contact"
            className={activeSection === 'contact' ? 'is-active' : ''}
            onClick={() => setActiveSection('contact')}
          >
            Contact
          </a>
        </aside>

        <div className="supplier-edit-main">
          <section className="supplier-edit-section" id="supplier-general">
            <header className="supplier-edit-section-head">
              <h2>General information</h2>
              <p>
                {isAdd
                  ? 'Supplier identity and registration defaults.'
                  : 'Supplier identity and registration details.'}
              </p>
            </header>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="supplier-code">
                Supplier code
              </label>
              <div>
                <input
                  id="supplier-code"
                  type="text"
                  className="supplier-edit-input is-readonly"
                  value={code}
                  readOnly
                />
                {isAdd ? (
                  <p className="supplier-edit-help">The system generates a supplier code automatically when you save.</p>
                ) : null}
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="supplier-name">
                Supplier name <span className="req">*</span>
              </label>
              <div>
                <input
                  id="supplier-name"
                  type="text"
                  name="name"
                  required
                  className="supplier-edit-input"
                  placeholder="e.g. Jiefang Motors (T) Ltd"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                />
              </div>
            </div>

            {showDepartment && isRoadmaster ? (
              <div className="supplier-edit-row">
                <label className="supplier-edit-label" htmlFor="supplier-type">
                  Department
                </label>
                <div>
                  <select
                    id="supplier-type"
                    name="supplier_type"
                    className="supplier-edit-input"
                    defaultValue={defaultType}
                  >
                    <option value="vehicle">Trucks &amp; Vehicles</option>
                    <option value="spare_part">Spare Parts &amp; Components</option>
                    <option value="general">General Service Provider</option>
                  </select>
                </div>
              </div>
            ) : (
              <input type="hidden" name="supplier_type" value={defaultType} />
            )}
          </section>

          <section className="supplier-edit-section" id="supplier-contact">
            <header className="supplier-edit-section-head">
              <h2>Contact &amp; address</h2>
              <p>Primary supplier contact and location details.</p>
            </header>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="contact_person">
                Contact person
              </label>
              <div>
                <input
                  id="contact_person"
                  type="text"
                  name="contact_person"
                  className="supplier-edit-input"
                  placeholder="e.g. John Doe"
                  defaultValue={contactPerson}
                />
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="phone">
                Phone
              </label>
              <div>
                <input
                  id="phone"
                  type="text"
                  name="phone"
                  className="supplier-edit-input"
                  placeholder="e.g. +255..."
                  defaultValue={phone}
                />
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="email">
                Email
              </label>
              <div>
                <input
                  id="email"
                  type="email"
                  name="email"
                  className="supplier-edit-input"
                  placeholder="partner@example.com"
                  defaultValue={email}
                />
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="address">
                Address
              </label>
              <div>
                <textarea
                  id="address"
                  name="address"
                  rows={4}
                  className="supplier-edit-input supplier-edit-textarea"
                  placeholder="Full physical address or warehouse location..."
                  defaultValue={address}
                />
              </div>
            </div>

            <div className="supplier-edit-row">
              <label className="supplier-edit-label" htmlFor="notes">
                Notes
              </label>
              <div>
                <textarea
                  id="notes"
                  name="notes"
                  rows={3}
                  className="supplier-edit-input supplier-edit-textarea"
                  placeholder="Optional internal notes about this supplier..."
                  defaultValue={notes}
                />
                <p className="supplier-edit-help">Notes are for internal use only.</p>
              </div>
            </div>
          </section>

          <div className="supplier-edit-actions">
            <a href={indexUrl} className="prod-desk-btn prod-desk-btn-secondary supplier-desk-btn">
              Cancel
            </a>
            <button
              type="submit"
              className={`prod-desk-btn prod-desk-btn-primary supplier-desk-btn${saving ? ' is-saving' : ''}`}
              disabled={saving}
              aria-busy={saving}
            >
              {saving ? (
                <HiOutlineArrowPath size={16} className="supplier-edit-spin" aria-hidden="true" />
              ) : (
                <HiOutlineCheck size={16} aria-hidden="true" />
              )}
              {saving ? 'Saving...' : isAdd ? 'Save supplier' : 'Save changes'}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
