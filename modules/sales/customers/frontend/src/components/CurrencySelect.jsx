import { useEffect, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';

const FLAG_BASE = 'https://flagcdn.com/w40/';

function flagUrl(code) {
  return `${FLAG_BASE}${(code || 'un').toLowerCase()}.png`;
}

export default function CurrencySelect({
  id,
  value,
  options,
  onChange,
  required = false,
  placeholder = 'Select currency',
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
      className={`ca-currency-picker${open ? ' is-open' : ''}`}
    >
      <button
        id={id}
        type="button"
        className="ca-currency-trigger exp-create-select"
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => setOpen((prev) => !prev)}
      >
        {selected ? (
          <>
            <img
              src={flagUrl(selected.flag)}
              alt=""
              className="ca-currency-flag"
            />
            <span className="ca-currency-code">{selected.label}</span>
            {selected.name && selected.name !== selected.label && (
              <span className="ca-currency-name">{selected.name}</span>
            )}
          </>
        ) : (
          <span className="ca-currency-placeholder">{placeholder}</span>
        )}
        <ChevronDown className="ca-currency-chevron" aria-hidden="true" size={16} />
      </button>

      {required && (
        <input
          tabIndex={-1}
          aria-hidden="true"
          className="ca-currency-required-proxy"
          value={value || ''}
          required={required}
          onChange={() => {}}
        />
      )}

      {open && (
        <ul className="ca-currency-menu" role="listbox" aria-labelledby={id}>
          {options.map((opt) => (
            <li key={opt.value} role="option" aria-selected={opt.value === value}>
              <button
                type="button"
                className={`ca-currency-option${opt.value === value ? ' is-selected' : ''}`}
                onClick={() => choose(opt.value)}
              >
                <img
                  src={flagUrl(opt.flag)}
                  alt=""
                  className="ca-currency-flag"
                />
                <span className="ca-currency-code">{opt.label}</span>
                {opt.name && (
                  <span className="ca-currency-name">{opt.name}</span>
                )}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
