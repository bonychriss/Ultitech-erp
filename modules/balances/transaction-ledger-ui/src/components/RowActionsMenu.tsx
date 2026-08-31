import { useEffect, useState } from 'react';
import { Eye, MoreHorizontal } from 'lucide-react';
import type { LedgerTransaction } from '../types';

type RowActionsMenuProps = {
  transaction: LedgerTransaction;
};

export default function RowActionsMenu({ transaction }: RowActionsMenuProps) {
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!open) return;

    function handlePointerDown(event: globalThis.MouseEvent) {
      const target = event.target;
      if (!(target instanceof Element)) return;
      if (!target.closest(`[data-tl-menu="${transaction.id}"]`)) {
        setOpen(false);
      }
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false);
    }

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [open, transaction.id]);

  return (
    <div className="tl-row-actions" data-tl-menu={transaction.id}>
      <button
        type="button"
        className={`tl-row-actions-trigger${open ? ' is-active' : ''}`}
        data-tl-row-ignore
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label={`Actions for transaction ${transaction.id}`}
      >
        <MoreHorizontal className="tl-row-actions-icon" aria-hidden="true" />
      </button>
      {open && (
        <div className="tl-row-actions-menu" role="menu">
          <a
            href={transaction.viewUrl}
            className="tl-row-actions-item"
            role="menuitem"
            data-tl-row-ignore
            onClick={() => setOpen(false)}
          >
            <Eye className="w-4 h-4" aria-hidden="true" />
            View transaction
          </a>
        </div>
      )}
    </div>
  );
}
