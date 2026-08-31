import { useEffect, useRef, useState } from 'react';

const FLAG_BASE = 'https://flagcdn.com/w40/';

function flagUrl(code) {
  return `${FLAG_BASE}${(code || 'un').toLowerCase()}.png`;
}

function IconChevron() {
  return (
    <svg className="crm-picker-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
      <polyline points="6 9 12 15 18 9" />
    </svg>
  );
}

export default function CountrySelect({
  id,
  value,
  options,
  onChange,
  required = false,
  placeholder = 'Select country',
}) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);
  const selected = options.find((opt) => opt.value === value) || null;

  useEffect(() => {
    if (!open) return undefined;

    function handlePointerDown(event) {
      if (!rootRef.current?.contains(event.target)) {
        setOpen(false);
      }
    }

    function handleEscape(event) {
      if (event.key === 'Escape') setOpen(false);
    }

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('keydown', handleEscape);
    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('keydown', handleEscape);
    };
  }, [open]);

  function choose(nextValue) {
    onChange(nextValue);
    setOpen(false);
  }

  return (
    <div ref={rootRef} className={`crm-country-picker${open ? ' is-open' : ''}`}>
      <button
        id={id}
        type="button"
        className="crm-country-trigger crm-field-input"
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => setOpen((prev) => !prev)}
      >
        {selected ? (
          <>
            <img src={flagUrl(selected.flag)} alt="" className="crm-country-flag" />
            <span className="crm-country-label">{selected.label}</span>
          </>
        ) : (
          <span className="crm-country-placeholder">{placeholder}</span>
        )}
        <IconChevron />
      </button>

      {required && (
        <input
          tabIndex={-1}
          aria-hidden="true"
          className="crm-picker-required-proxy"
          value={value || ''}
          required={required}
          onChange={() => {}}
        />
      )}

      {open && (
        <ul className="crm-country-menu" role="listbox" aria-labelledby={id}>
          {options.map((opt) => (
            <li key={opt.value} role="option" aria-selected={opt.value === value}>
              <button
                type="button"
                className={`crm-country-option${opt.value === value ? ' is-selected' : ''}`}
                onClick={() => choose(opt.value)}
              >
                <img src={flagUrl(opt.flag)} alt="" className="crm-country-flag" />
                <span>{opt.label}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
