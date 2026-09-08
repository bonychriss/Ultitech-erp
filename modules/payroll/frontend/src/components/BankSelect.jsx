import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Landmark, Search } from 'lucide-react';
import { TANZANIA_BANKS, findBankByName } from '../data/tanzaniaBanks.js';

export function BankLogo({ bank, size = 28, className = '' }) {
  if (bank?.logo) {
    return (
      <img
        className={`pay-bank-logo-img ${className}`.trim()}
        src={bank.logo}
        alt=""
        width={size}
        height={size}
        title={bank.name}
      />
    );
  }

  return (
    <span
      className={`pay-bank-logo pay-bank-logo--empty ${className}`.trim()}
      style={{ width: size, height: size }}
      aria-hidden="true"
    >
      <Landmark size={Math.max(12, Math.round(size * 0.55))} />
    </span>
  );
}

export default function BankSelect({ id, value, onChange, placeholder = 'Select bank' }) {
  const listId = useId();
  const rootRef = useRef(null);
  const searchRef = useRef(null);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');

  const selected = useMemo(() => findBankByName(value), [value]);
  const customValue = value && !selected ? String(value) : '';

  const options = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return TANZANIA_BANKS;
    return TANZANIA_BANKS.filter(
      (bank) => bank.name.toLowerCase().includes(q) || bank.short.toLowerCase().includes(q),
    );
  }, [query]);

  useEffect(() => {
    if (!open) return undefined;

    function handlePointer(event) {
      if (!rootRef.current?.contains(event.target)) {
        setOpen(false);
        setQuery('');
      }
    }

    function handleKey(event) {
      if (event.key === 'Escape') {
        setOpen(false);
        setQuery('');
      }
    }

    document.addEventListener('mousedown', handlePointer);
    document.addEventListener('keydown', handleKey);
    const timer = window.setTimeout(() => searchRef.current?.focus(), 0);
    return () => {
      document.removeEventListener('mousedown', handlePointer);
      document.removeEventListener('keydown', handleKey);
      window.clearTimeout(timer);
    };
  }, [open]);

  function choose(bankName) {
    onChange(bankName);
    setOpen(false);
    setQuery('');
  }

  return (
    <div className={`pay-bank-select${open ? ' is-open' : ''}`} ref={rootRef}>
      <button
        id={id}
        type="button"
        className="pay-bank-select-trigger"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={listId}
        onClick={() => setOpen((current) => !current)}
      >
        <span className="pay-bank-select-value">
          {selected ? (
            <>
              <BankLogo bank={selected} size={28} />
              <span>{selected.name}</span>
            </>
          ) : customValue ? (
            <>
              <BankLogo bank={null} size={28} />
              <span>{customValue}</span>
            </>
          ) : (
            <span className="pay-bank-select-placeholder">{placeholder}</span>
          )}
        </span>
        <ChevronDown size={16} className="pay-bank-select-caret" aria-hidden="true" />
      </button>

      {open && (
        <div className="pay-bank-select-panel" role="listbox" id={listId}>
          <label className="pay-bank-select-search">
            <Search size={14} aria-hidden="true" />
            <input
              ref={searchRef}
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Search banks..."
              aria-label="Search banks"
            />
          </label>

          <div className="pay-bank-select-list">
            <button
              type="button"
              className={`pay-bank-option${!value ? ' is-active' : ''}`}
              role="option"
              aria-selected={!value}
              onClick={() => choose('')}
            >
              <span className="pay-bank-option-main">
                <BankLogo bank={null} size={28} />
                <span>No bank selected</span>
              </span>
            </button>

            {customValue && (
              <button
                type="button"
                className="pay-bank-option is-active"
                role="option"
                aria-selected
                onClick={() => choose(customValue)}
              >
                <span className="pay-bank-option-main">
                  <BankLogo bank={null} size={28} />
                  <span>{customValue}</span>
                </span>
                <Check size={16} aria-hidden="true" />
              </button>
            )}

            {options.map((bank) => {
              const active = selected?.name === bank.name;
              return (
                <button
                  key={bank.name}
                  type="button"
                  className={`pay-bank-option${active ? ' is-active' : ''}`}
                  role="option"
                  aria-selected={active}
                  onClick={() => choose(bank.name)}
                >
                  <span className="pay-bank-option-main">
                    <BankLogo bank={bank} size={28} />
                    <span className="pay-bank-option-text">
                      <span className="pay-bank-option-name">{bank.name}</span>
                      <span className="pay-bank-option-short">{bank.short}</span>
                    </span>
                  </span>
                  {active && <Check size={16} aria-hidden="true" />}
                </button>
              );
            })}

            {options.length === 0 && (
              <div className="pay-bank-select-empty">No banks match “{query}”.</div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
