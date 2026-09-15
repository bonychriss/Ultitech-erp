export type InsightItem = {
  label: string;
  class: string;
  text: string;
  link?: string;
};

export type DashboardInit = {
  success: boolean;
  todayLabel: string;
  companyDisplay: string;
  year: number;
  canManageAccount: boolean;
  coaCreateUrl: string;
  aiInsightsUrl: string;
  kpis: {
    totalLiquidity: number;
    totalLiquidityDisplay: string;
    cashTotal: number;
    cashTotalDisplay: string;
    bankTotal: number;
    bankTotalDisplay: string;
    mobileTotal: number;
    mobileTotalDisplay: string;
    accountCount: number;
    hasCash: boolean;
    hasBank: boolean;
    hasMobile: boolean;
  };
  trend: {
    labels: string[];
    credits: number[];
    debits: number[];
  };
  accountStats: {
    counts: { cash: number; bank: number; mobile: number };
    pct: { cash: number; bank: number; mobile: number };
    total: number;
  };
  topAccounts: {
    labels: string[];
    values: number[];
    colors: string[];
    displays: string[];
  };
  insights: {
    aiConnected: boolean;
    visible: InsightItem[];
    hidden: InsightItem[];
    hiddenCount: number;
  };
};

export type LiquidityAccountRow = {
  id: number;
  name: string;
  code: string;
  fullName: string;
  type: string;
  typeLabel: string;
  bucket: string;
  bucketLabel: string;
  currency: string;
  openingBalance: number;
  txCredits: number;
  txDebits: number;
  balance: number;
  balanceDisplay: string;
  parentId: number;
};

export type LiquidityAccountsPayload = {
  success: boolean;
  totalLiquidity: number;
  totalLiquidityDisplay: string;
  accountCount: number;
  formula: string;
  groups: Array<{
    key: string;
    label: string;
    total: number;
    totalDisplay: string;
    accountCount: number;
    accounts: LiquidityAccountRow[];
  }>;
  accounts: LiquidityAccountRow[];
};

export type AccountMonthRow = {
  ym: string;
  label: string;
  openingBalance: number;
  openingBalanceDisplay: string;
  moneyIn: number;
  moneyInDisplay: string;
  moneyOut: number;
  moneyOutDisplay: string;
  closingBalance: number;
  closingBalanceDisplay: string;
  transactionCount: number;
};

export type AccountMonthsPayload = {
  success: boolean;
  account: {
    id: number;
    name: string;
    code: string;
    fullName: string;
    type: string;
    typeLabel: string;
    bucket: string;
    bucketLabel: string;
    currency: string;
    status: string;
    openingBalance: number;
    openingBalanceDisplay: string;
    balance: number;
    balanceDisplay: string;
    reconcilingClose: number;
    reconcilingCloseDisplay: string;
    balanceMatchesLedger: boolean;
  };
  months: AccountMonthRow[];
  monthsChronological: AccountMonthRow[];
};

export type MonthTransactionRow = {
  id: number;
  transactionDate: string;
  description: string;
  type: 'credit' | 'debit';
  typeLabel: string;
  amount: number;
  debit: number;
  credit: number;
  debitDisplay: string;
  creditDisplay: string;
  amountDisplay: string;
  runningBalance: number;
  runningBalanceDisplay: string;
  referenceType: string;
  referenceId: number | null;
  referenceLabel: string;
  sourceUrl: string | null;
  createdBy: string;
  viewUrl: string;
};

export type MonthTransactionsPayload = {
  success: boolean;
  account: AccountMonthsPayload['account'];
  month: AccountMonthRow;
  filters: { type: string; q: string };
  transactions: MonthTransactionRow[];
  transactionCount: number;
};

export type TransactionDetailPayload = {
  success: boolean;
  transaction: {
    id: number;
    accountId: number;
    accountName: string;
    accountType: string;
    currency: string;
    transactionDate: string;
    ym: string;
    monthLabel: string;
    description: string;
    type: 'credit' | 'debit';
    typeLabel: string;
    amount: number;
    amountDisplay: string;
    referenceType: string;
    referenceId: number | null;
    referenceLabel: string;
    sourceUrl: string | null;
    createdBy: string;
    runningBalance: number;
    runningBalanceDisplay: string;
    viewUrl: string;
    whyIncluded: string;
  };
};

export type LiquidityBucket = 'cash' | 'bank' | 'mobile' | 'other';

export type LdView =
  | { name: 'dashboard' }
  | { name: 'liquidity'; bucket?: LiquidityBucket | null }
  | { name: 'account'; accountId: number }
  | { name: 'month'; accountId: number; ym: string }
  | { name: 'transaction'; accountId: number; ym: string; txId: number };
