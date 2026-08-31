export interface FinancialAccount {
  id: number;
  name: string;
  currency: string;
  balance: number;
  type: string;
}

export interface KpiTraceLine {
  label: string;
  value: string;
}

export interface KpiTraceItem {
  id?: number;
  poNumber: string;
  payeeName: string;
  createdAt: string;
  currency: string;
  amountToPay: number;
  amountPaid: number;
  balanceDue: number;
  paymentStatus?: string;
  contribution: number;
  note?: string;
}

export interface KpiTrace {
  title: string;
  headline: string;
  currency?: string;
  source: string;
  method: string;
  criteria: KpiTraceLine[];
  items: KpiTraceItem[];
  footnote?: string;
}

export interface SummaryTraces {
  unpaidPurchaseOrders: KpiTrace;
  accountsPayable: KpiTrace;
  overduePayables: KpiTrace;
}

export type KpiTraceKey = 'unpaidPurchaseOrders' | 'accountsPayable' | 'overduePayables' | 'listedNow';

export interface DeskSummary {
  unpaidCount: number;
  currency: string;
  accountsPayable: number;
  accountsPayableCurrency: string;
  accountsPayableSource?: 'ledger' | 'unpaid_pos' | string;
  overduePayables: number;
  overduePayablesCount: number;
  overduePayablesCurrency: string;
}

export interface DeskFilters {
  q: string;
  date_from: string;
  date_to: string;
  payee: string;
  amount_min: string;
  amount_max: string;
}

export interface PurchaseOrderRow {
  id: number;
  poNumber: string;
  payeeName: string;
  description: string;
  currency: string;
  amountToPay: number;
  amountPaid: number;
  balanceDue: number;
  status: string;
  paymentStatus: string;
  paidByName: string;
  createdAt: string;
  viewUrl: string;
  editUrl: string;
  isLegacyDeskId?: boolean;
}

export interface PurchaseOrderPayment {
  id: number;
  paymentNumber: string;
  paymentDate: string;
  amount: number;
  currency: string;
  exchangeRate: number;
  paymentMethod: string;
  referenceNo: string;
  accountName: string;
  status: string;
  paidByName: string;
  notes: string;
  journalEntryNumber: string;
  proofUrl: string;
  createdAt: string;
}

export interface PurchaseOrderAttachment {
  id: number;
  name: string;
  url: string;
  fileType: string;
  fileSize: number;
  createdAt: string;
  kind: string;
}

export interface PurchaseOrderDetails {
  order: PurchaseOrderRow;
  payments: PurchaseOrderPayment[];
  latestPayment: PurchaseOrderPayment | null;
  attachments: PurchaseOrderAttachment[];
}

export interface DeskInit {
  tab: string;
  tabLabel: string;
  module: string;
  summary: DeskSummary;
  summaryTraces?: SummaryTraces;
  payeeOptions: string[];
  accounts: FinancialAccount[];
  paymentMethods: string[];
}
