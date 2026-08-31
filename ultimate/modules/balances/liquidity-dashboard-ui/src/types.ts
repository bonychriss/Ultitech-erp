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
