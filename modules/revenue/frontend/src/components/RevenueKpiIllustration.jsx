function Illustration({ traceKey }) {
  const common = { viewBox: '0 0 120 80', fill: 'none', xmlns: 'http://www.w3.org/2000/svg', 'aria-hidden': true };

  if (traceKey === 'totalVat') {
    return (
      <svg {...common} className="rev-kpi-illus-svg">
        <rect x="8" y="12" width="104" height="56" rx="12" fill="#ecfdf5" />
        <circle cx="38" cy="40" r="18" fill="#bbf7d0" />
        <text x="38" y="46" textAnchor="middle" fontSize="16" fontWeight="700" fill="#15803d">%</text>
        <rect x="62" y="28" width="40" height="8" rx="4" fill="#86efac" />
        <rect x="62" y="42" width="28" height="8" rx="4" fill="#4ade80" />
      </svg>
    );
  }

  if (traceKey === 'totalInclTax') {
    return (
      <svg {...common} className="rev-kpi-illus-svg">
        <rect x="8" y="12" width="104" height="56" rx="12" fill="#fff7ed" />
        <circle cx="42" cy="48" r="16" fill="#fdba74" />
        <circle cx="58" cy="38" r="14" fill="#f59e0b" />
        <circle cx="72" cy="48" r="12" fill="#d97706" />
        <path d="M30 24h60" stroke="#fed7aa" strokeWidth="4" strokeLinecap="round" />
      </svg>
    );
  }

  if (traceKey === 'outstandingAr') {
    return (
      <svg {...common} className="rev-kpi-illus-svg">
        <rect x="8" y="12" width="104" height="56" rx="12" fill="#fff7ed" />
        <circle cx="60" cy="38" r="20" stroke="#fdba74" strokeWidth="4" fill="#ffedd5" />
        <path d="M60 28v14l8 6" stroke="#ea580c" strokeWidth="3" strokeLinecap="round" />
        <rect x="24" y="56" width="72" height="6" rx="3" fill="#fed7aa" />
      </svg>
    );
  }

  if (traceKey === 'thisMonth') {
    return (
      <svg {...common} className="rev-kpi-illus-svg">
        <rect x="8" y="12" width="104" height="56" rx="12" fill="#ccfbf1" />
        <rect x="24" y="22" width="72" height="44" rx="8" fill="#fff" stroke="#99f6e4" strokeWidth="2" />
        <rect x="24" y="22" width="72" height="12" rx="8" fill="#14b8a6" />
        <circle cx="40" cy="46" r="4" fill="#5eead4" />
        <circle cx="56" cy="46" r="4" fill="#5eead4" />
        <circle cx="72" cy="46" r="4" fill="#0d9488" />
      </svg>
    );
  }

  if (traceKey === 'totalInvoices') {
    return (
      <svg {...common} className="rev-kpi-illus-svg">
        <rect x="8" y="12" width="104" height="56" rx="12" fill="#eff6ff" />
        <rect x="28" y="24" width="64" height="40" rx="6" fill="#fff" stroke="#93c5fd" strokeWidth="2" />
        <path d="M36 36h48M36 44h36M36 52h28" stroke="#60a5fa" strokeWidth="3" strokeLinecap="round" />
      </svg>
    );
  }

  if (traceKey === 'outstandingInvoices') {
    return (
      <svg {...common} className="rev-kpi-illus-svg">
        <rect x="8" y="12" width="104" height="56" rx="12" fill="#ecfdf5" />
        <rect x="30" y="26" width="60" height="36" rx="6" fill="#fff" stroke="#6ee7b7" strokeWidth="2" />
        <path d="M42 46h36" stroke="#34d399" strokeWidth="4" strokeLinecap="round" />
        <circle cx="78" cy="38" r="8" fill="#10b981" />
      </svg>
    );
  }

  if (traceKey === 'overdueInvoices') {
    return (
      <svg {...common} className="rev-kpi-illus-svg">
        <rect x="8" y="12" width="104" height="56" rx="12" fill="#fef2f2" />
        <path d="M60 24l18 32H42L60 24z" fill="#fee2e2" stroke="#fca5a5" strokeWidth="2" />
        <path d="M60 34v10" stroke="#dc2626" strokeWidth="3" strokeLinecap="round" />
        <circle cx="60" cy="50" r="2" fill="#dc2626" />
      </svg>
    );
  }

  return (
    <svg {...common} className="rev-kpi-illus-svg">
      <rect x="8" y="12" width="104" height="56" rx="12" fill="#eff6ff" />
      <rect x="24" y="24" width="48" height="32" rx="6" fill="#fff" stroke="#93c5fd" strokeWidth="2" />
      <path d="M32 48h32M32 40h24M32 32h28" stroke="#60a5fa" strokeWidth="3" strokeLinecap="round" />
      <circle cx="86" cy="28" r="10" fill="#2563eb" />
      <path d="M82 28h8M86 24v8" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
    </svg>
  );
}

export default Illustration;
