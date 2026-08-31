import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Eye, MoreVertical } from 'lucide-react';

interface LineRowActionsProps {
  onView: () => void;
}

interface MenuPosition {
  top: number;
  left: number;
  openUp: boolean;
}

export default function LineRowActions({ onView }: LineRowActionsProps) {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState<MenuPosition | null>(null);
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  const panelRef = useRef<HTMLDivElement | null>(null);

  useLayoutEffect(() => {
    if (!open || !triggerRef.current) {
      setPosition(null);
      return undefined;
    }

    const updatePosition = () => {
      const trigger = triggerRef.current;
      if (!trigger) return;

      const rect = trigger.getBoundingClientRect();
      const panelHeight = panelRef.current?.offsetHeight ?? 48;
      const panelWidth = panelRef.current?.offsetWidth ?? 152;
      const gap = 6;
      const spaceBelow = window.innerHeight - rect.bottom;
      const openUp = spaceBelow < panelHeight + gap + 8 && rect.top > spaceBelow;

      const top = openUp ? rect.top - gap - panelHeight : rect.bottom + gap;
      const left = Math.min(
        Math.max(8, rect.right - panelWidth),
        window.innerWidth - panelWidth - 8
      );

      setPosition({ top, left, openUp });
    };

    updatePosition();
    window.addEventListener('resize', updatePosition);
    window.addEventListener('scroll', updatePosition, true);
    return () => {
      window.removeEventListener('resize', updatePosition);
      window.removeEventListener('scroll', updatePosition, true);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return undefined;

    const onPointerDown = (event: MouseEvent) => {
      const target = event.target as Node;
      if (triggerRef.current?.contains(target) || panelRef.current?.contains(target)) {
        return;
      }
      setOpen(false);
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };

    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  return (
    <div className={`sms-row-menu${open ? ' is-open' : ''}`}>
      <button
        ref={triggerRef}
        type="button"
        className="sms-row-menu-trigger"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label="Row actions"
        title="Actions"
        onClick={() => setOpen((value) => !value)}
      >
        <MoreVertical className="w-4 h-4" />
      </button>

      {open &&
        createPortal(
          <div
            ref={panelRef}
            className={`sms-row-menu-panel sms-row-menu-panel--portal${position?.openUp ? ' is-up' : ''}`}
            role="menu"
            style={
              position
                ? { top: position.top, left: position.left, visibility: 'visible' }
                : { top: 0, left: 0, visibility: 'hidden' }
            }
          >
            <button
              type="button"
              role="menuitem"
              className="sms-row-menu-item"
              onClick={() => {
                setOpen(false);
                onView();
              }}
            >
              <Eye className="w-3.5 h-3.5" />
              View
            </button>
          </div>,
          document.body
        )}
    </div>
  );
}
