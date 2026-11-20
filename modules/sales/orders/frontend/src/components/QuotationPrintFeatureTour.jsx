import { useCallback, useEffect, useLayoutEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  hasSeenQuotationPrintTour,
  isNarrowQuotationViewport,
  markQuotationPrintTourSeen,
} from '../utils/quotationPrintTour.js';
import QuotationPrintTourCharacter from './QuotationPrintTourCharacter.jsx';
import '../quotation-print-tour.css';

const STEPS = [
  {
    id: 'actions',
    title: 'Print quotations',
    body: 'You can now print quotations from Actions. We’ll show you how.',
    cta: 'Next',
    desktopSelector: '[data-ov-tour="actions-desktop"]',
    mobileSelector: '[data-ov-tour="actions-mobile"]',
    openMenu: false,
  },
  {
    id: 'print',
    title: 'Print quotations',
    body: 'Open Actions and choose Print — same A4 layout as this page.',
    cta: 'Next',
    desktopSelector: '[data-ov-tour="print-desktop"]',
    mobileSelector: '[data-ov-tour="print-mobile"]',
    openMenu: true,
  },
  {
    id: 'ready',
    title: 'You’re all set',
    body: 'Ready when you are — print this quotation now.',
    cta: 'Print now',
    desktopSelector: '',
    mobileSelector: '',
    openMenu: false,
    printNow: true,
  },
];

const PAD = 8;
const CARD_GAP = 14;
const CARD_W = 320;

function measureTarget(selector) {
  const el = document.querySelector(selector);
  if (!el) return null;
  const rect = el.getBoundingClientRect();
  if (rect.width < 2 || rect.height < 2) return null;
  // Ignore off-screen / collapsed leftovers.
  if (rect.bottom < 0 || rect.top > window.innerHeight || rect.right < 0 || rect.left > window.innerWidth) {
    return null;
  }
  return {
    top: rect.top - PAD,
    left: rect.left - PAD,
    width: rect.width + PAD * 2,
    height: rect.height + PAD * 2,
    centerX: rect.left + rect.width / 2,
    bottom: rect.bottom + PAD,
    right: rect.right + PAD,
  };
}

function placeCard(hole, cardWidth = CARD_W) {
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  // Keep the tip in the main content column (away from the sidebar).
  const contentLeft = Math.min(236, Math.max(16, Math.round(vw * 0.18)));

  if (!hole) {
    return {
      top: `${Math.max(16, Math.round(vh * 0.22))}px`,
      left: `${Math.max(contentLeft, Math.round((vw - cardWidth) / 2))}px`,
      transform: 'none',
    };
  }

  // Prefer directly under the highlighted control.
  let top = hole.bottom + CARD_GAP;
  let left = hole.centerX - cardWidth / 2;

  if (top + 210 > vh) {
    top = Math.max(16, hole.top - 210);
  }

  // If still colliding with the hole, nudge to the side inside the content area.
  const cardBottom = top + 200;
  const overlapsHole = !(
    left + cardWidth < hole.left - 8
    || left > hole.right + 8
    || cardBottom < hole.top - 8
    || top > hole.bottom + 8
  );
  if (overlapsHole) {
    left = hole.right + 16;
    if (left + cardWidth > vw - 12) {
      left = Math.max(contentLeft, hole.left - cardWidth - 16);
    }
  }

  left = Math.max(contentLeft, Math.min(left, vw - cardWidth - 12));
  top = Math.max(12, Math.min(top, vh - 220));

  return { top: `${top}px`, left: `${left}px`, transform: 'none' };
}

function wait(ms) {
  return new Promise((resolve) => {
    window.setTimeout(resolve, ms);
  });
}

async function lockOntoTarget(selector, openMenu) {
  for (let attempt = 0; attempt < 24; attempt += 1) {
    if (typeof openMenu === 'function') openMenu();
    await wait(attempt === 0 ? 30 : 35);
    const hole = measureTarget(selector);
    if (hole) return hole;
  }
  return null;
}

export default function QuotationPrintFeatureTour({
  ready = false,
  onOpenDesktopActions,
  onOpenMobileActions,
  onCloseMenus,
  onPrint,
}) {
  const [active, setActive] = useState(false);
  const [stepIndex, setStepIndex] = useState(0);
  const [hole, setHole] = useState(null);
  const [cardStyle, setCardStyle] = useState({});

  const clearTourBodyClass = () => {
    document.body.classList.remove('inv-print-tour-active');
    document.body.classList.remove('inv-print-tour-step-print');
  };

  const finish = useCallback((event) => {
    if (event?.stopPropagation) event.stopPropagation();
    markQuotationPrintTourSeen();
    setActive(false);
    clearTourBodyClass();
    if (typeof onCloseMenus === 'function') onCloseMenus();
  }, [onCloseMenus]);

  const finishAndPrint = useCallback((event) => {
    finish(event);
    if (typeof onPrint === 'function') {
      window.setTimeout(() => onPrint(), 40);
    }
  }, [finish, onPrint]);

  const step = STEPS[stepIndex] || STEPS[0];
  const isMobile = isNarrowQuotationViewport();
  const selector = isMobile ? step.mobileSelector : step.desktopSelector;
  const isLast = stepIndex >= STEPS.length - 1;

  const openMenuForStep = useCallback(() => {
    if (!step.openMenu) return;
    if (isMobile) {
      if (typeof onOpenMobileActions === 'function') onOpenMobileActions();
    } else if (typeof onOpenDesktopActions === 'function') {
      onOpenDesktopActions();
    }
  }, [step.openMenu, isMobile, onOpenDesktopActions, onOpenMobileActions]);

  const refreshPosition = useCallback(() => {
    if (!selector) {
      setHole(null);
      setCardStyle(placeCard(null));
      return;
    }
    openMenuForStep();
    const nextHole = measureTarget(selector);
    setHole(nextHole);
    setCardStyle(placeCard(nextHole));
  }, [selector, openMenuForStep]);

  useEffect(() => {
    if (!ready || hasSeenQuotationPrintTour()) return undefined;

    const timer = window.setTimeout(() => {
      setActive(true);
      setStepIndex(0);
    }, 700);

    return () => window.clearTimeout(timer);
  }, [ready]);

  useEffect(() => {
    if (!active) {
      clearTourBodyClass();
      return undefined;
    }
    document.body.classList.add('inv-print-tour-active');
    document.body.classList.toggle('inv-print-tour-step-print', step.id === 'print');
    if (step.id === 'ready' && typeof onCloseMenus === 'function') {
      onCloseMenus();
    }
    return () => clearTourBodyClass();
  }, [active, step.id, onCloseMenus]);

  useLayoutEffect(() => {
    if (!active) return undefined;

    let cancelled = false;

    (async () => {
      if (!selector) {
        if (typeof onCloseMenus === 'function') onCloseMenus();
        if (cancelled) return;
        setHole(null);
        setCardStyle(placeCard(null));
        return;
      }
      openMenuForStep();
      const nextHole = await lockOntoTarget(selector, openMenuForStep);
      if (cancelled) return;
      setHole(nextHole);
      setCardStyle(placeCard(nextHole));
    })();

    const sync = () => {
      refreshPosition();
    };

    window.addEventListener('resize', sync);
    window.addEventListener('scroll', sync, true);

    return () => {
      cancelled = true;
      window.removeEventListener('resize', sync);
      window.removeEventListener('scroll', sync, true);
    };
  }, [active, step.id, selector, openMenuForStep, refreshPosition, onCloseMenus]);

  const goNext = (event) => {
    if (event?.stopPropagation) event.stopPropagation();
    if (event?.preventDefault) event.preventDefault();
    if (isLast) {
      if (step.printNow) finishAndPrint(event);
      else finish(event);
      return;
    }
    // Open Actions immediately so Print exists before the next measure pass.
    if (typeof onOpenDesktopActions === 'function' && !isMobile) {
      onOpenDesktopActions();
    } else if (typeof onOpenMobileActions === 'function' && isMobile) {
      onOpenMobileActions();
    }
    setStepIndex((n) => n + 1);
  };

  if (!active || typeof document === 'undefined') return null;

  const holeStyle = hole
    ? {
        top: `${hole.top}px`,
        left: `${hole.left}px`,
        width: `${hole.width}px`,
        height: `${hole.height}px`,
      }
    : null;

  return createPortal(
    <div
      className="inv-print-tour"
      role="dialog"
      aria-modal="true"
      aria-labelledby="inv-print-tour-title"
      onMouseDown={(event) => event.stopPropagation()}
      onClick={(event) => event.stopPropagation()}
    >
      <button
        type="button"
        className={`inv-print-tour-scrim${holeStyle ? ' is-cutout' : ''}`}
        aria-label="Skip tip"
        onClick={finish}
      />
      {holeStyle ? <div className="inv-print-tour-hole" style={holeStyle} aria-hidden="true" /> : null}

      <div className="inv-print-tour-card" style={cardStyle}>
        <div className="inv-print-tour-hero">
          <QuotationPrintTourCharacter />
          <div className="inv-print-tour-hero-copy">
            <p className="inv-print-tour-kicker">{step.id === 'ready' ? 'Ready' : 'New'}</p>
            <h2 id="inv-print-tour-title" className="inv-print-tour-title">{step.title}</h2>
          </div>
        </div>
        <p className="inv-print-tour-body">{step.body}</p>
        <div className="inv-print-tour-footer">
          <span className="inv-print-tour-progress" aria-hidden="true">
            {stepIndex + 1}
            {' / '}
            {STEPS.length}
          </span>
          <div className="inv-print-tour-actions">
            <button type="button" className="inv-print-tour-skip" onClick={finish}>
              Skip
            </button>
            <button type="button" className="inv-print-tour-next" onClick={goNext}>
              {step.cta || (isLast ? 'Done' : 'Next')}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}
