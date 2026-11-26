export type SettingsTab = 'profile' | 'security' | 'approvals' | 'preferences' | 'activity';

export interface ProfileIdentity {
  firstName: string;
  lastName: string;
  email: string;
  emailVerified: boolean;
  phone: string;
  extension: string;
  jobTitle: string;
  bio: string;
  avatarUrl: string | null;
}

export interface OrganizationAttribution {
  employeeId: string;
  legalEntity: string;
  department: string;
  costCenterCode: string;
  costCenterName: string;
  siteLocation: string;
  reportingManager: string;
  erpRole: string;
  hireDate: string;
}

export interface RoleChangeRequest {
  id: string;
  requestedRole: string;
  requestedCostCenter: string;
  justification: string;
  submittedAt: string;
  status: 'Pending Manager Review';
}

export interface SessionRecord {
  id: string;
  device: string;
  browser: string;
  deviceType: 'desktop' | 'mobile';
  location: string;
  ipAddress: string;
  lastActive: string;
  isCurrent: boolean;
}

export interface ApiTokenRecord {
  id: string;
  name: string;
  prefix: string;
  scope: 'Read-Only Ledger' | 'Procurement & POs' | 'Inventory Sync';
  createdAt: string;
  expiresAt: string;
  lastUsed: string;
}

export interface SecuritySettings {
  mfaAuthenticator: boolean;
  mfaHardwareKey: boolean;
  mfaSmsFallback: boolean;
  ssoEnforced: boolean;
  backupCodes: string[];
  sessions: SessionRecord[];
  apiTokens: ApiTokenRecord[];
}

export interface DelegationSettings {
  active: boolean;
  delegateId: string;
  startDate: string;
  endDate: string;
  scopes: {
    purchaseOrders: boolean;
    vendorOnboarding: boolean;
    invoiceApprovals: boolean;
    capexRequests: boolean;
  };
  escalationBackupId: string;
  escalationSlaHours: '24' | '48' | '72';
  handoffNote: string;
}

export interface NotificationChannelPref {
  id: string;
  category: string;
  description: string;
  inApp: boolean;
  email: boolean;
  digest: boolean;
}

export interface RegionalPreferences {
  language: string;
  timezone: string;
  dateFormat: 'YYYY-MM-DD' | 'DD/MM/YYYY' | 'MM/DD/YYYY';
  numberFormat: '1,234.56' | '1.234,56' | '1 234.56';
  currency: 'USD' | 'GBP' | 'EUR' | 'JPY';
  fiscalYearStart: 'January' | 'April' | 'July' | 'October';
  theme: 'light' | 'dark';
  tableDensity: 'compact' | 'comfortable';
  keyboardShortcuts: boolean;
  notifications: NotificationChannelPref[];
}

export interface AuditLogEntry {
  id: string;
  timestamp: string;
  category: 'Profile' | 'Security' | 'Approvals' | 'Preferences';
  action: string;
  target: string;
  ipAddress: string;
}

export interface ErpProfileState {
  identity: ProfileIdentity;
  organization: OrganizationAttribution;
  roleChangeRequest: RoleChangeRequest | null;
  security: SecuritySettings;
  delegation: DelegationSettings;
  preferences: RegionalPreferences;
  auditLog: AuditLogEntry[];
}

export interface DirectoryColleague {
  id: string;
  name: string;
  title: string;
  department: string;
  costCenter: string;
}
