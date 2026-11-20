export const INVOICE_PRINT_TOUR_STORAGE_KEY = 'sales.invoices.printFeatureTour.v10';

export function hasSeenInvoicePrintTour() {
  try {
    return window.localStorage.getItem(INVOICE_PRINT_TOUR_STORAGE_KEY) === '1';
  } catch (_) {
    return true;
  }
}

export function markInvoicePrintTourSeen() {
  try {
    window.localStorage.setItem(INVOICE_PRINT_TOUR_STORAGE_KEY, '1');
  } catch (_) {
    /* ignore quota / private mode */
  }
}

export function isNarrowInvoiceViewport() {
  return typeof window !== 'undefined' && window.matchMedia('(max-width: 768px)').matches;
}
