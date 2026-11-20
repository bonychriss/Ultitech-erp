export const QUOTATION_PRINT_TOUR_STORAGE_KEY = 'sales.quotations.printFeatureTour.v2';

export function hasSeenQuotationPrintTour() {
  try {
    return window.localStorage.getItem(QUOTATION_PRINT_TOUR_STORAGE_KEY) === '1';
  } catch (_) {
    return true;
  }
}

export function markQuotationPrintTourSeen() {
  try {
    window.localStorage.setItem(QUOTATION_PRINT_TOUR_STORAGE_KEY, '1');
  } catch (_) {
    /* ignore quota / private mode */
  }
}

export function isNarrowQuotationViewport() {
  return typeof window !== 'undefined' && window.matchMedia('(max-width: 768px)').matches;
}

export function orderStatusIsQuotation(status) {
  return ['draft', 'quotation', 'sent'].includes(String(status || '').toLowerCase());
}
