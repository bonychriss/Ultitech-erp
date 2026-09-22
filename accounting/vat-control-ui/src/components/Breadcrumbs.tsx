import type { Crumb } from '../nav';

export default function Breadcrumbs({ items }: { items: Crumb[] }) {
  return (
    <nav className="ld-breadcrumbs" aria-label="Breadcrumb">
      {items.map((item, index) => {
        const isLast = index === items.length - 1;
        return (
          <span key={`${item.label}-${index}`} className="ld-breadcrumb-item">
            {index > 0 && <span className="ld-breadcrumb-sep" aria-hidden="true">&gt;</span>}
            {item.onClick && !isLast ? (
              <button type="button" className="ld-breadcrumb-link" onClick={item.onClick}>
                {item.label}
              </button>
            ) : (
              <span className={`ld-breadcrumb-current${isLast ? ' is-current' : ''}`}>{item.label}</span>
            )}
          </span>
        );
      })}
    </nav>
  );
}
