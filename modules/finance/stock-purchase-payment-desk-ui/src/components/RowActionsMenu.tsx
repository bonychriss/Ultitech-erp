import { useEffect, useRef, useState } from 'react';
import { MoreHorizontal } from 'lucide-react';
import type { PurchaseOrderRow } from '../types';
import { isPaidStatus } from '../utils/format';

type RowActionsMenuProps = {
  order: PurchaseOrderRow;
  onPay: () => void;
};

export default function RowActionsMenu({ order, onPay }: RowActionsMenuProps) {
  const [open, setOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;

    function handlePointerDown(event: globalThis.MouseEvent) {
      if (!menuRef.current?.contains(event.target as Node)) {
        setOpen(false);
      }
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false);
      }
    }

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [open]);

  const isPaid = isPaidStatus(order.paymentStatus);
  const editUrl = order.editUrl?.trim() ?? '';
  const showEdit = editUrl !== '' && !isPaid && order.balanceDue > 0.009;

  function handlePay() {
    setOpen(false);
    onPay();
  }

  return (
    <div className="sppd-row-actions" ref={menuRef}>
      <button
        type="button"
        className={`sppd-row-actions-trigger${open ? ' is-active' : ''}`}
        data-sppd-row-ignore
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label={`Actions for ${order.poNumber}`}
      >
        <MoreHorizontal className="sppd-row-actions-icon" aria-hidden="true" />
      </button>
      {open && (
        <div className="sppd-row-actions-menu" role="menu" aria-label={`Actions for ${order.poNumber}`}>
          {showEdit && (
            <a
              href={editUrl}
              className="sppd-row-actions-item"
              role="menuitem"
              data-sppd-row-ignore
              onClick={() => setOpen(false)}
            >
              Edit purchase order
            </a>
          )}
          {!isPaid && (
            <button
              type="button"
              className="sppd-row-actions-item"
              role="menuitem"
              data-sppd-row-ignore
              onClick={handlePay}
            >
              Pay purchase
            </button>
          )}
        </div>
      )}
    </div>
  );
}
