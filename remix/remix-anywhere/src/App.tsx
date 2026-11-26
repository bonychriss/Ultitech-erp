import { useEffect, useMemo, useState } from 'react';
import { CheckCircle2, RotateCcw } from 'lucide-react';
import TopBar from './components/TopBar';
import SettingsSidebar from './components/SettingsSidebar';
import ProfileSection from './components/sections/ProfileSection';
import SecuritySection from './components/sections/SecuritySection';
import ApprovalsSection from './components/sections/ApprovalsSection';
import PreferencesSection from './components/sections/PreferencesSection';
import ActivityLogSection from './components/sections/ActivityLogSection';
import { INITIAL_ERP_PROFILE } from './data/erpDefaults';
import {
  AuditLogEntry,
  DelegationSettings,
  ErpProfileState,
  ProfileIdentity,
  RegionalPreferences,
  RoleChangeRequest,
  SecuritySettings,
  SettingsTab,
} from './types/erpProfile';

const STORAGE_KEY = 'meridian_erp_profile_v1';

function loadStoredProfile(): ErpProfileState {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return INITIAL_ERP_PROFILE;
    const parsed = JSON.parse(raw) as Partial<ErpProfileState>;
    return {
      ...INITIAL_ERP_PROFILE,
      ...parsed,
      identity: { ...INITIAL_ERP_PROFILE.identity, ...parsed.identity },
      organization: { ...INITIAL_ERP_PROFILE.organization, ...parsed.organization },
      security: { ...INITIAL_ERP_PROFILE.security, ...parsed.security },
      delegation: {
        ...INITIAL_ERP_PROFILE.delegation,
        ...parsed.delegation,
        scopes: {
          ...INITIAL_ERP_PROFILE.delegation.scopes,
          ...parsed.delegation?.scopes,
        },
      },
      preferences: { ...INITIAL_ERP_PROFILE.preferences, ...parsed.preferences },
      auditLog: Array.isArray(parsed.auditLog) ? parsed.auditLog : INITIAL_ERP_PROFILE.auditLog,
    };
  } catch {
    return INITIAL_ERP_PROFILE;
  }
}

function nowUtcLabel(): string {
  return new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
}

const TAB_LABELS: Record<SettingsTab, string> = {
  profile: 'Profile & Identity',
  security: 'Security & Access',
  approvals: 'Approvals & Delegation',
  preferences: 'Regional & Notifications',
  activity: 'Activity Log',
};

export default function App() {
  const [savedProfile, setSavedProfile] = useState<ErpProfileState>(() => loadStoredProfile());
  const [draftProfile, setDraftProfile] = useState<ErpProfileState>(() => loadStoredProfile());
  const [activeTab, setActiveTab] = useState<SettingsTab>('profile');
  const [searchQuery, setSearchQuery] = useState('');
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  // Sync Tailwind v4 dark class on <html>
  useEffect(() => {
    const isDark = draftProfile.preferences.theme === 'dark';
    document.documentElement.classList.toggle('dark', isDark);
  }, [draftProfile.preferences.theme]);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => {
      setToastMessage((prev) => (prev === msg ? null : prev));
    }, 3200);
  };

  const hasUnsavedChanges = useMemo(() => {
    return (
      JSON.stringify({
        identity: draftProfile.identity,
        delegation: draftProfile.delegation,
        preferences: draftProfile.preferences,
      }) !==
      JSON.stringify({
        identity: savedProfile.identity,
        delegation: savedProfile.delegation,
        preferences: savedProfile.preferences,
      })
    );
  }, [draftProfile, savedProfile]);

  const appendAudit = (
    state: ErpProfileState,
    category: AuditLogEntry['category'],
    action: string,
    target: string
  ): AuditLogEntry[] => {
    const newEntry: AuditLogEntry = {
      id: `aud-${Date.now()}`,
      timestamp: nowUtcLabel(),
      category,
      action,
      target,
      ipAddress: '194.72.18.104',
    };
    return [newEntry, ...state.auditLog].slice(0, 60);
  };

  const persistState = (next: ErpProfileState) => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    } catch {
      // Ignore quota errors if large uploaded avatar exceeds localStorage
    }
  };

  const handleSaveChanges = () => {
    if (!hasUnsavedChanges) return;

    const changedCategories: { cat: AuditLogEntry['category']; action: string; target: string }[] = [];

    if (JSON.stringify(draftProfile.identity) !== JSON.stringify(savedProfile.identity)) {
      changedCategories.push({
        cat: 'Profile',
        action: 'Updated personal identity & directory contact details',
        target: `${draftProfile.identity.firstName} ${draftProfile.identity.lastName}`,
      });
    }
    if (JSON.stringify(draftProfile.delegation) !== JSON.stringify(savedProfile.delegation)) {
      changedCategories.push({
        cat: 'Approvals',
        action: draftProfile.delegation.active
          ? 'Updated and activated out-of-office delegation'
          : 'Updated approval delegation & SLA rules',
        target: 'Approval Routing',
      });
    }
    if (JSON.stringify(draftProfile.preferences) !== JSON.stringify(savedProfile.preferences)) {
      changedCategories.push({
        cat: 'Preferences',
        action: 'Saved regional formatting, theme, or alert preferences',
        target: `${draftProfile.preferences.currency} / ${draftProfile.preferences.dateFormat}`,
      });
    }

    let updatedLog = draftProfile.auditLog;
    for (const item of changedCategories) {
      updatedLog = appendAudit(
        { ...draftProfile, auditLog: updatedLog },
        item.cat,
        item.action,
        item.target
      );
    }

    const committed: ErpProfileState = {
      ...draftProfile,
      auditLog: updatedLog,
    };

    setSavedProfile(committed);
    setDraftProfile(committed);
    persistState(committed);
    showToast('Profile settings saved');
  };

  const handleDiscardChanges = () => {
    setDraftProfile((prev) => ({
      ...prev,
      identity: savedProfile.identity,
      delegation: savedProfile.delegation,
      preferences: savedProfile.preferences,
    }));
    showToast('Reverted unsaved changes');
  };

  const handleUpdateIdentity = (patch: Partial<ProfileIdentity>) => {
    setDraftProfile((prev) => ({
      ...prev,
      identity: { ...prev.identity, ...patch },
    }));
  };

  const handleUpdateDelegation = (patch: Partial<DelegationSettings>) => {
    setDraftProfile((prev) => ({
      ...prev,
      delegation: { ...prev.delegation, ...patch },
    }));
  };

  const handleUpdatePreferences = (patch: Partial<RegionalPreferences>) => {
    setDraftProfile((prev) => ({
      ...prev,
      preferences: { ...prev.preferences, ...patch },
    }));
  };

  // Immediate governance actions (security, role requests) persist directly and log to auditLog
  const handleUpdateSecurity = (
    patch: Partial<SecuritySettings>,
    auditAction?: string,
    auditTarget?: string
  ) => {
    const applyUpdate = (base: ErpProfileState): ErpProfileState => {
      const withSecurity: ErpProfileState = {
        ...base,
        security: { ...base.security, ...patch },
      };
      if (auditAction && auditTarget) {
        withSecurity.auditLog = appendAudit(withSecurity, 'Security', auditAction, auditTarget);
      }
      return withSecurity;
    };

    const nextSaved = applyUpdate(savedProfile);
    const nextDraft = applyUpdate(draftProfile);
    setSavedProfile(nextSaved);
    setDraftProfile(nextDraft);
    persistState(nextSaved);
    if (auditAction) showToast(auditAction);
  };

  const handlePasswordUpdated = () => {
    const logUpdate = (base: ErpProfileState): ErpProfileState => ({
      ...base,
      auditLog: appendAudit(base, 'Security', 'Rotated account fallback password', 'Credentials'),
    });
    const nextSaved = logUpdate(savedProfile);
    const nextDraft = logUpdate(draftProfile);
    setSavedProfile(nextSaved);
    setDraftProfile(nextDraft);
    persistState(nextSaved);
    showToast('Password updated');
  };

  const handleSubmitRoleRequest = (req: RoleChangeRequest) => {
    const applyReq = (base: ErpProfileState): ErpProfileState => ({
      ...base,
      roleChangeRequest: req,
      auditLog: appendAudit(
        base,
        'Profile',
        `Submitted assignment change request (${req.requestedRole})`,
        req.requestedCostCenter
      ),
    });
    const nextSaved = applyReq(savedProfile);
    const nextDraft = applyReq(draftProfile);
    setSavedProfile(nextSaved);
    setDraftProfile(nextDraft);
    persistState(nextSaved);
    showToast('Assignment change request submitted');
  };

  const handleWithdrawRoleRequest = () => {
    const applyWithdraw = (base: ErpProfileState): ErpProfileState => ({
      ...base,
      roleChangeRequest: null,
      auditLog: appendAudit(
        base,
        'Profile',
        'Withdrew pending role change request',
        'Organization Attribution'
      ),
    });
    const nextSaved = applyWithdraw(savedProfile);
    const nextDraft = applyWithdraw(draftProfile);
    setSavedProfile(nextSaved);
    setDraftProfile(nextDraft);
    persistState(nextSaved);
    showToast('Request withdrawn');
  };

  return (
    <div className="min-h-screen flex flex-col bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100">
      <TopBar
        activeTab={activeTab}
        onSelectTab={setActiveTab}
        hasUnsavedChanges={hasUnsavedChanges}
        onSave={handleSaveChanges}
        onOpenMobileMenu={() => setMobileMenuOpen(true)}
      />

      <div className="flex-1 flex w-full">
        <SettingsSidebar
          profile={draftProfile}
          activeTab={activeTab}
          onSelectTab={setActiveTab}
          searchQuery={searchQuery}
          onSearchChange={setSearchQuery}
          mobileOpen={mobileMenuOpen}
          onCloseMobile={() => setMobileMenuOpen(false)}
        />

        <main className="flex-1 min-w-0 px-4 sm:px-8 lg:px-12 py-8">
          <div className="max-w-5xl mx-auto">
            {/* Contextual Breadcrumbs & Unsaved Toolbar */}
            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
              <nav aria-label="Breadcrumb" className="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <span>Workspace</span>
                <span aria-hidden="true">/</span>
                <span>Administration</span>
                <span aria-hidden="true">/</span>
                <span className="font-medium text-slate-800 dark:text-slate-200">
                  {TAB_LABELS[activeTab]}
                </span>
              </nav>

              {hasUnsavedChanges && (
                <div className="flex items-center gap-3 text-xs">
                  <span className="font-medium text-amber-700 dark:text-amber-400">
                    Unsaved edits pending
                  </span>
                  <button
                    type="button"
                    onClick={handleDiscardChanges}
                    className="inline-flex items-center gap-1 font-medium text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white cursor-pointer"
                  >
                    <RotateCcw className="w-3.5 h-3.5" />
                    <span>Discard</span>
                  </button>
                </div>
              )}
            </div>

            {/* Active Settings View */}
            {activeTab === 'profile' && (
              <ProfileSection
                identity={draftProfile.identity}
                organization={draftProfile.organization}
                roleChangeRequest={draftProfile.roleChangeRequest}
                onUpdateIdentity={handleUpdateIdentity}
                onSubmitRoleRequest={handleSubmitRoleRequest}
                onWithdrawRoleRequest={handleWithdrawRoleRequest}
              />
            )}

            {activeTab === 'security' && (
              <SecuritySection
                security={draftProfile.security}
                tableDensity={draftProfile.preferences.tableDensity}
                onUpdateSecurity={handleUpdateSecurity}
                onPasswordUpdated={handlePasswordUpdated}
              />
            )}

            {activeTab === 'approvals' && (
              <ApprovalsSection
                delegation={draftProfile.delegation}
                onUpdateDelegation={handleUpdateDelegation}
              />
            )}

            {activeTab === 'preferences' && (
              <PreferencesSection
                preferences={draftProfile.preferences}
                onUpdatePreferences={handleUpdatePreferences}
              />
            )}

            {activeTab === 'activity' && (
              <ActivityLogSection
                entries={draftProfile.auditLog}
                tableDensity={draftProfile.preferences.tableDensity}
              />
            )}

            {/* Inline Save/Discard Bar at bottom of form when modified */}
            {hasUnsavedChanges && (
              <div className="mt-8 p-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <p className="text-sm text-slate-600 dark:text-slate-300">
                  You have unsaved changes to your ERP profile configuration.
                </p>
                <div className="flex items-center gap-3 shrink-0">
                  <button
                    type="button"
                    onClick={handleDiscardChanges}
                    className="px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer whitespace-nowrap"
                  >
                    Discard Changes
                  </button>
                  <button
                    type="button"
                    onClick={handleSaveChanges}
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-blue-700 text-white hover:bg-blue-800 dark:bg-blue-600 dark:hover:bg-blue-500 cursor-pointer whitespace-nowrap"
                  >
                    Save Changes
                  </button>
                </div>
              </div>
            )}
          </div>
        </main>
      </div>

      {/* Confirmation Toast */}
      {toastMessage && (
        <div
          role="status"
          aria-live="polite"
          className="fixed bottom-5 right-5 z-50 flex items-center gap-2.5 px-4 py-3 rounded-lg bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 text-sm font-medium shadow-lg"
        >
          <CheckCircle2 className="w-4 h-4 text-emerald-400 dark:text-emerald-600 shrink-0" />
          <span>{toastMessage}</span>
        </div>
      )}
    </div>
  );
}
