import { useCallback, useEffect, useRef, useState } from 'react';

const SNAP_HALF = 0.5;
const SNAP_FULL = 0.92;
const SNAP_DISMISS = 0.22;

function viewportHeight() {
  return window.visualViewport?.height ?? window.innerHeight;
}

export function useBottomSheet({ open, onClose, disabled = false, breakpoint = 768 }) {
  const [isMobile, setIsMobile] = useState(false);
  const [snap, setSnap] = useState(SNAP_HALF);
  const [heightPx, setHeightPx] = useState(null);
  const [dragging, setDragging] = useState(false);
  const dragRef = useRef({ startY: 0, startHeight: 0 });
  const draggingRef = useRef(false);
  const heightPxRef = useRef(null);

  useEffect(() => {
    const mq = window.matchMedia(`(max-width: ${breakpoint}px)`);
    const sync = () => setIsMobile(mq.matches);
    sync();
    mq.addEventListener('change', sync);
    return () => mq.removeEventListener('change', sync);
  }, [breakpoint]);

  useEffect(() => {
    if (open) {
      setSnap(SNAP_HALF);
      setHeightPx(null);
      heightPxRef.current = null;
      setDragging(false);
      draggingRef.current = false;
    }
  }, [open]);

  const active = open && isMobile && !disabled;

  const snapToPx = useCallback((fraction) => fraction * viewportHeight(), []);

  const resolveSnap = useCallback((px) => {
    const vh = viewportHeight();
    const halfPx = SNAP_HALF * vh;
    const fullPx = SNAP_FULL * vh;
    const dismissPx = SNAP_DISMISS * vh;

    if (px < dismissPx) {
      return 'dismiss';
    }
    if (px < (halfPx + fullPx) / 2) {
      return SNAP_HALF;
    }
    return SNAP_FULL;
  }, []);

  useEffect(() => {
    if (!active) return undefined;

    const onResize = () => {
      if (!draggingRef.current) {
        setSnap((value) => value);
      }
    };

    window.visualViewport?.addEventListener('resize', onResize);
    window.addEventListener('resize', onResize);

    return () => {
      window.visualViewport?.removeEventListener('resize', onResize);
      window.removeEventListener('resize', onResize);
    };
  }, [active]);

  const onPointerDown = useCallback((e) => {
    if (!active || e.button > 0) return;
    if (e.target.closest('button, a, input, select, textarea, label')) return;

    e.currentTarget.setPointerCapture(e.pointerId);
    const current = heightPxRef.current ?? heightPx ?? snapToPx(snap);
    dragRef.current = { startY: e.clientY, startHeight: current };
    draggingRef.current = true;
    setDragging(true);
  }, [active, heightPx, snap, snapToPx]);

  const onPointerMove = useCallback((e) => {
    if (!draggingRef.current || !active) return;
    const { startY, startHeight } = dragRef.current;
    const delta = e.clientY - startY;
    const next = Math.min(snapToPx(SNAP_FULL), Math.max(0, startHeight - delta));
    heightPxRef.current = next;
    setHeightPx(next);
  }, [active, snapToPx]);

  const finishDrag = useCallback((e) => {
    if (!draggingRef.current) return;

    try {
      e.currentTarget.releasePointerCapture(e.pointerId);
    } catch {
      /* ignore */
    }

    draggingRef.current = false;
    setDragging(false);

    const current = heightPxRef.current ?? snapToPx(snap);
    const resolved = resolveSnap(current);

    if (resolved === 'dismiss') {
      setHeightPx(null);
      heightPxRef.current = null;
      onClose?.();
      return;
    }

    setSnap(resolved);
    setHeightPx(null);
    heightPxRef.current = null;
  }, [snap, snapToPx, resolveSnap, onClose]);

  const sheetStyle = active
    ? {
        height: `${heightPx ?? snapToPx(snap)}px`,
        maxHeight: 'none',
        transition: dragging
          ? 'none'
          : 'height 0.28s cubic-bezier(0.32, 0.72, 0, 1)',
      }
    : undefined;

  const sheetClassName = [
    active ? 'is-bottom-sheet' : '',
    dragging ? 'is-sheet-dragging' : '',
  ].filter(Boolean).join(' ');

  const grabProps = active
    ? {
        onPointerDown,
        onPointerMove,
        onPointerUp: finishDrag,
        onPointerCancel: finishDrag,
      }
    : null;

  return {
    isMobileSheet: active,
    sheetStyle,
    sheetClassName,
    grabProps,
  };
}
