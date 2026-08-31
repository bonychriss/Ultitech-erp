import React from 'react';
import { Search, X } from 'lucide-react';

interface ProductSearchBarProps {
  value: string;
  onChange: (value: string) => void;
  matchCount?: number;
  totalCount?: number;
}

export default function ProductSearchBar({
  value,
  onChange,
  matchCount,
  totalCount,
}: ProductSearchBarProps) {
  const hasQuery = value.trim().length > 0;

  return (
    <div className="sms-top-search">
      <div className="sms-top-search-inner">
        <div className="sms-top-search-field">
          <Search className="sms-top-search-icon" aria-hidden="true" />
          <input
            type="search"
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder="Search by name, code, category, description, unit..."
            className="sms-input sms-top-search-input"
            aria-label="Search products"
          />
          {hasQuery && (
            <button
              type="button"
              className="sms-top-search-clear"
              onClick={() => onChange('')}
              aria-label="Clear search"
            >
              <X className="w-4 h-4" />
            </button>
          )}
        </div>
        {hasQuery && matchCount !== undefined && totalCount !== undefined && (
          <p className="sms-top-search-meta">
            {matchCount} of {totalCount} products match
          </p>
        )}
      </div>
    </div>
  );
}
