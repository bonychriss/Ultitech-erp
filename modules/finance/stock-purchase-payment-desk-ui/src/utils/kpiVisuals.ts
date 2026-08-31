import { AlertTriangle, Clock, Receipt, Wallet, type LucideIcon } from 'lucide-react';
import type { KpiTraceKey } from '../types';

export interface KpiVisual {
  Icon: LucideIcon;
  iconClass: string;
}

export const KPI_VISUALS: Record<KpiTraceKey, KpiVisual> = {
  unpaidPurchaseOrders: { Icon: Clock, iconClass: 'sppd-kpi-icon--indigo' },
  accountsPayable: { Icon: Receipt, iconClass: 'sppd-kpi-icon--amber' },
  overduePayables: { Icon: AlertTriangle, iconClass: 'sppd-kpi-icon--rose' },
  listedNow: { Icon: Wallet, iconClass: 'sppd-kpi-icon--violet' },
};
