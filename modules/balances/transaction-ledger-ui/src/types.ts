export type TxFilters = {
  q: string;
  date_from: string;
  date_to: string;
  type: '' | 'credit' | 'debit' | 'transfer';
  amount_min: string;
  amount_max: string;
  page: number;
  per_page: string;
};

export type TxSummary = {
  totalEntries: number;
  totalInflows: number;
  totalOutflows: number;
  creditCount: number;
  debitCount: number;
  transferCount: number;
  netMovement: number;
  periodLabel: string;
};

export type LedgerTransaction = {
  id: number;
  transactionDate: string;
  accountName: string;
  description: string;
  referenceType: string;
  referenceLabel: string;
  referenceId: number | null;
  userName: string;
  amount: number;
  type: 'credit' | 'debit';
  typeLabel: string;
  typeClass: 'credit' | 'debit' | 'transfer';
  amountDisplay: string;
  viewUrl: string;
};

export type TxPagination = {
  page: number;
  perPage: number;
  totalPages: number;
  viewAll: boolean;
  showingFrom: number;
  showingTo: number;
  totalEntries: number;
};

export type TxInit = {
  success: true;
  summary: TxSummary;
  transferUrl: string;
  companyName: string;
  dateLabel: string;
  aiConnected: boolean;
};

export type TxListResponse = {
  success: true;
  summary: TxSummary;
  transactions: LedgerTransaction[];
  pagination: TxPagination;
  filters?: Pick<TxFilters, 'q' | 'date_from' | 'date_to' | 'type' | 'amount_min' | 'amount_max'>;
};

export type TxAiSearchResponse = {
  success: true;
  filters: Pick<TxFilters, 'q' | 'date_from' | 'date_to' | 'type' | 'amount_min' | 'amount_max'>;
  explanation: string;
  summary: TxSummary;
  transactions: LedgerTransaction[];
  pagination: TxPagination;
  count: number;
};
