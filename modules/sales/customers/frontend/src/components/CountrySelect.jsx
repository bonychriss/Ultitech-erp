import { useEffect, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';

const FLAG_BASE = 'https://flagcdn.com/w40/';

function flagUrl(code) {
  return `${FLAG_BASE}${(code || 'un').toLowerCase()}.png`;
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
    <div
      ref={rootRef}
      className={`ca-country-picker${open ? ' is-open' : ''}`}
    >
      <button
        id={id}
        type="button"
        className="ca-country-trigger exp-create-select"
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => setOpen((prev) => !prev)}
      >
        {selected ? (
          <>
            <img
              src={flagUrl(selected.flag)}
              alt=""
              className="ca-country-flag"
            />
            <span className="ca-country-label">{selected.label}</span>
          </>
        ) : (
          <span className="ca-country-placeholder">{placeholder}</span>
        )}
        <ChevronDown className="ca-country-chevron" aria-hidden="true" size={16} />
      </button>

      {required && (
        <input
          tabIndex={-1}
          aria-hidden="true"
          className="ca-country-required-proxy"
          value={value || ''}
          required={required}
          onChange={() => {}}
        />
      )}

      {open && (
        <ul className="ca-country-menu" role="listbox" aria-labelledby={id}>
          {options.map((opt) => (
            <li key={opt.value} role="option" aria-selected={opt.value === value}>
              <button
                type="button"
                className={`ca-country-option${opt.value === value ? ' is-selected' : ''}`}
                onClick={() => choose(opt.value)}
              >
                <img
                  src={flagUrl(opt.flag)}
                  alt=""
                  className="ca-country-flag"
                />
                <span>{opt.label}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
