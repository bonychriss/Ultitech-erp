import {
  ChevronDown,
  ChevronRight,
  FilePlus,
  FileText,
  Inbox,
  Settings,
  ShoppingCart,
  Tags,
  Truck,
  Users,
  Wallet,
  Zap,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

function readCfgUrls() {
  if (typeof window === 'undefined') return {};
  return window.__SALES_DASHBOARD_CFG__?.action_urls || {};
}

export default function SalesActionsMenu({ urls = {} }) {
  const [open, setOpen] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
  const wrapRef = useRef(null);
  const merged = { ...readCfgUrls(), ...urls };

  useEffect(() => {
    if (!open) {
      setMoreOpen(false);
      return undefined;
    }
    const onDoc = (event) => {
      if (wrapRef.current && !wrapRef.current.contains(event.target)) {
        setOpen(false);
        setMoreOpen(false);
      }
    };
    const onKey = (event) => {
      if (event.key === 'Escape') {
        setOpen(false);
        setMoreOpen(false);
      }
    };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const primary = [
    { href: merged.new_quote, label: 'Create Quotation', tone: 'teal', Icon: ShoppingCart },
    { href: merged.new_order, label: 'Create Sell Order', tone: 'violet', Icon: FilePlus },
    { href: merged.new_invoice, label: 'Create Invoice', tone: 'purple', Icon: FileText },
    { href: merged.new_delivery_note, label: 'Create Delivery Note', tone: 'orange', Icon: Truck },
  ].filter((item) => item.href);

  const moreItems = [
    { href: merged.customers, label: 'Customers', Icon: Users },
    { href: merged.catalogue, label: 'Catalogue', Icon: ShoppingCart },
    { href: merged.pricelist, label: 'Pricelist', Icon: Tags },
    { href: merged.quote_requests, label: 'Quote requests', Icon: Inbox },
    { href: merged.payment_create, label: 'Record payment', Icon: Wallet },
    { href: merged.settings, label: 'Sales settings', Icon: Settings },
  ].filter((item) => item.href);

  if (primary.length === 0) return null;

  return (
    <div className={`sd-actions${open ? ' is-open' : ''}`} ref={wrapRef}>
      <button
        type="button"
        className="sd-actions-btn"
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
      >
        <span className="sd-actions-bolt" aria-hidden="true">
          <Zap size={16} strokeWidth={2.4} />
        </span>
        Actions
        <ChevronDown size={16} className="sd-actions-caret" aria-hidden="true" />
      </button>
      {open ? (
        <div className="sd-actions-menu" role="menu">
          {primary.map((item) => (
            <a key={item.label} className="sd-actions-item" role="menuitem" href={item.href}>
              <span className={`sd-actions-icon sd-actions-icon--${item.tone}`} aria-hidden="true">
                <item.Icon size={15} strokeWidth={2.2} />
              </span>
              {item.label}
            </a>
          ))}
          {moreItems.length > 0 ? (
            <div
              className={`sd-actions-more${moreOpen ? ' is-open' : ''}`}
              onMouseEnter={() => setMoreOpen(true)}
              onMouseLeave={() => setMoreOpen(false)}
            >
              <button
                type="button"
                className="sd-actions-item sd-actions-item--more"
                role="menuitem"
                aria-haspopup="menu"
                aria-expanded={moreOpen}
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  setMoreOpen(true);
                }}
              >
                <span className="sd-actions-icon sd-actions-icon--slate" aria-hidden="true">
                  <Settings size={15} strokeWidth={2.2} />
                </span>
                More Actions
                <ChevronRight size={16} className="sd-actions-more-caret" aria-hidden="true" />
              </button>
              {moreOpen ? (
                <div className="sd-actions-submenu" role="menu">
                  {moreItems.map((item) => (
                    <a key={item.label} className="sd-actions-item" role="menuitem" href={item.href}>
                      <span className="sd-actions-icon sd-actions-icon--slate" aria-hidden="true">
                        <item.Icon size={15} strokeWidth={2.2} />
                      </span>
                      {item.label}
                    </a>
                  ))}
                </div>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
