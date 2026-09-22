export type VatCategory = 'output' | 'purchases' | 'expenses';
export type VatSource = VatCategory;
export type VatPeriodStatus = 'open' | 'reconciled' | 'closed';
export type VatPosition = 'payable' | 'credit' | 'nil';

export type VatView =
  | { name: 'dashboard' }
  | { name: 'category'; category: VatCategory }
  | { name: 'year'; year: number }
  | { name: 'month'; ym: string }
  | { name: 'transactions'; ym: string; source: VatSource };

export type DashboardInit = {
  ok: boolean;
  todayLabel: string;
  companyDisplay: string;
  year: number;
  thisYm: string;
  thisMonthLabel: string;
  kpis: {
    ytdNet: number;
    ytdNetDisplay: string;
    ytdOutput: number;
    ytdOutputDisplay: string;
    ytdInput: number;
    ytdInputDisplay: string;
    allTimeNet: number;
    allTimeNetDisplay: string;
    thisMonthNet: number;
    thisMonthNetDisplay: string;
    thisMonthPosition: VatPosition;
    thisMonthStatus: VatPeriodStatus;
    openingBalance: number;
    openingBalanceDisplay: string;
    closingBalance: number;
    closingBalanceDisplay: string;
  };
  categories: Array<{
    key: VatCategory;
    label: string;
    total: number;
    totalDisplay: string;
    allTime: number;
    allTimeDisplay: string;
    thisMonth: number;
    thisMonthDisplay: string;
    tone: string;
  }>;
  history: Array<{
    ym: string;
    label: string;
    output: number;
    outputDisplay: string;
    inputPurchases: number;
    inputPurchasesDisplay: string;
    inputExpenses: number;
    inputExpensesDisplay: string;
    inputTotal: number;
    inputTotalDisplay: string;
    net: number;
    netDisplay: string;
    position: VatPosition;
    openingBalance: number;
    openingBalanceDisplay: string;
    closingBalance: number;
    closingBalanceDisplay: string;
    status: VatPeriodStatus;
    statusLabel: string;
  }>;
  historyCount: number;
};

export type CategoryYearsPayload = {
  ok: boolean;
  category: VatCategory;
  categoryLabel: string;
  years: Array<{
    year: number;
    label: string;
    total: number;
    totalDisplay: string;
    monthCount: number;
  }>;
  yearCount: number;
  grandTotal: number;
  grandTotalDisplay: string;
};

export type YearMonthsPayload = {
  ok: boolean;
  category?: VatCategory | null;
  categoryLabel?: string;
  year: number;
  months: Array<{
    ym: string;
    label: string;
    amount: number;
    amountDisplay: string;
    output?: number;
    outputDisplay?: string;
    inputPurchases?: number;
    inputPurchasesDisplay?: string;
    inputExpenses?: number;
    inputExpensesDisplay?: string;
    inputTotal?: number;
    inputTotalDisplay?: string;
    net: number;
    netDisplay: string;
    position: VatPosition;
    status: VatPeriodStatus;
    statusLabel: string;
  }>;
  monthCount: number;
  yearTotal: number;
  yearTotalDisplay: string;
  yearOutput?: number;
  yearOutputDisplay?: string;
  yearInput?: number;
  yearInputDisplay?: string;
  yearNet?: number;
  yearNetDisplay?: string;
};

export type MonthDetailPayload = {
  ok: boolean;
  ym: string;
  label: string;
  hasActivity: boolean;
  summary: {
    openingBalance: number;
    openingBalanceDisplay: string;
    output: number;
    outputDisplay: string;
    inputPurchases: number;
    inputPurchasesDisplay: string;
    inputExpenses: number;
    inputExpensesDisplay: string;
    inputTotal: number;
    inputTotalDisplay: string;
    net: number;
    netDisplay: string;
    closingBalance: number;
    closingBalanceDisplay: string;
    position: VatPosition;
    positionLabel: string;
  };
  activity: Array<{
    source: VatSource;
    label: string;
    amount: number;
    amountDisplay: string;
    description: string;
  }>;
  reconciliation: {
    system: Record<string, number | string>;
    module: Record<string, number | string>;
    difference: Record<string, number | string>;
    isBalanced: boolean;
  };
  period: {
    status: VatPeriodStatus;
    statusLabel: string;
    reconciledAt: string | null;
    closedAt: string | null;
    canReconcile: boolean;
    canClose: boolean;
  };
};

export type TransactionsPayload = {
  ok: boolean;
  ym: string;
  label: string;
  source: VatSource;
  sourceLabel: string;
  transactions: Array<{
    id: number;
    date: string;
    reference: string;
    party: string;
    description: string;
    taxableAmount?: number;
    taxableAmountDisplay?: string;
    vatRate?: number;
    vatRateDisplay?: string;
    vatAmount: number;
    vatAmountDisplay: string;
    grossAmount: number;
    grossAmountDisplay: string;
    status?: string;
    sourceUrl: string | null;
  }>;
  transactionCount: number;
  totalVat: number;
  totalVatDisplay: string;
};
