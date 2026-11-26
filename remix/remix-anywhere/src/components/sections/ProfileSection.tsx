import { useRef, useState } from 'react';
import { CheckCircle2, Upload, Trash2, Building2, X } from 'lucide-react';
import Avatar from '../Avatar';
import {
  OrganizationAttribution,
  ProfileIdentity,
  RoleChangeRequest,
} from '../../types/erpProfile';

interface ProfileSectionProps {
  identity: ProfileIdentity;
  organization: OrganizationAttribution;
  roleChangeRequest: RoleChangeRequest | null;
  onUpdateIdentity: (patch: Partial<ProfileIdentity>) => void;
  onSubmitRoleRequest: (req: RoleChangeRequest) => void;
  onWithdrawRoleRequest: () => void;
}

export default function ProfileSection({
  identity,
  organization,
  roleChangeRequest,
  onUpdateIdentity,
  onSubmitRoleRequest,
  onWithdrawRoleRequest,
}: ProfileSectionProps) {
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [isRoleModalOpen, setIsRoleModalOpen] = useState(false);
  const [requestedRole, setRequestedRole] = useState(organization.erpRole);
  const [requestedCostCenter, setRequestedCostCenter] = useState(organization.costCenterCode);
  const [justification, setJustification] = useState('');
  const [modalError, setModalError] = useState<string | null>(null);

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) {
      setUploadError('Please select a PNG, JPG, or WebP image file.');
      return;
    }
    if (file.size > 4 * 1024 * 1024) {
      setUploadError('Image must be smaller than 4 MB.');
      return;
    }
    setUploadError(null);
    const reader = new FileReader();
    reader.onload = () => {
      if (typeof reader.result === 'string') {
        onUpdateIdentity({ avatarUrl: reader.result });
      }
    };
    reader.readAsDataURL(file);
    e.target.value = '';
  };

  const handleRoleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!justification.trim() || justification.trim().length < 10) {
      setModalError('Please provide a brief business justification (at least 10 characters).');
      return;
    }
    onSubmitRoleRequest({
      id: `req-${Date.now().toString().slice(-6)}`,
      requestedRole: requestedRole.trim(),
      requestedCostCenter: requestedCostCenter.trim(),
      justification: justification.trim(),
      submittedAt: new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC',
      status: 'Pending Manager Review',
    });
    setModalError(null);
    setJustification('');
    setIsRoleModalOpen(false);
  };

  return (
    <div className="space-y-8">
      {/* Page Header */}
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
          Profile &amp; Identity
        </h1>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Manage your employee directory details, corporate contact lines, and ERP organizational attribution.
        </p>
      </div>

      {/* Primary Profile Card: Avatar + Identity Strip */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-6">
          <div className="flex items-center gap-5">
            <Avatar
              src={identity.avatarUrl}
              firstName={identity.firstName}
              lastName={identity.lastName}
              size="lg"
            />
            <div className="min-w-0">
              <h2 className="text-lg font-semibold text-slate-900 dark:text-slate-100 truncate">
                {identity.firstName} {identity.lastName}
              </h2>
              <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-600 dark:text-slate-400">
                <span>{identity.jobTitle}</span>
                <span aria-hidden="true">·</span>
                <span className="font-mono text-xs tabular-nums">{organization.employeeId}</span>
                <span aria-hidden="true">·</span>
                <span className="font-mono text-xs tabular-nums">{organization.costCenterCode}</span>
              </div>
              <p className="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                JPG, PNG, or WebP up to 4 MB. Visible across purchase order approvals and vendor correspondence.
              </p>
              {uploadError && (
                <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">{uploadError}</p>
              )}
            </div>
          </div>

          <div className="flex items-center gap-3 shrink-0">
            <input
              ref={fileInputRef}
              type="file"
              accept="image/png,image/jpeg,image/webp"
              onChange={handleFileChange}
              className="hidden"
            />
            <button
              type="button"
              onClick={() => fileInputRef.current?.click()}
              className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700/70 transition-colors whitespace-nowrap shrink-0 cursor-pointer"
            >
              <Upload className="w-4 h-4" />
              <span>Upload Photo</span>
            </button>
            {identity.avatarUrl && (
              <button
                type="button"
                onClick={() => onUpdateIdentity({ avatarUrl: null })}
                aria-label="Remove profile photo"
                className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg text-slate-600 dark:text-slate-400 hover:text-red-700 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors whitespace-nowrap shrink-0 cursor-pointer"
              >
                <Trash2 className="w-4 h-4" />
                <span>Remove</span>
              </button>
            )}
          </div>
        </div>
      </section>

      {/* Split 2-Column Section: Personal & Contact Details */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
          <div className="lg:col-span-4">
            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
              Personal &amp; Contact Information
            </h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
              Your name and direct lines appear on issued purchase orders, supplier inquiries, and internal audit trails.
            </p>
          </div>

          <div className="lg:col-span-8 grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label
                htmlFor="first-name"
                className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
              >
                First Name
              </label>
              <input
                id="first-name"
                type="text"
                value={identity.firstName}
                onChange={(e) => onUpdateIdentity({ firstName: e.target.value })}
                className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600 dark:focus:border-blue-500"
              />
            </div>

            <div>
              <label
                htmlFor="last-name"
                className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
              >
                Last Name
              </label>
              <input
                id="last-name"
                type="text"
                value={identity.lastName}
                onChange={(e) => onUpdateIdentity({ lastName: e.target.value })}
                className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600 dark:focus:border-blue-500"
              />
            </div>

            <div className="sm:col-span-2">
              <div className="flex items-center justify-between mb-1.5">
                <label
                  htmlFor="corp-email"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300"
                >
                  Corporate Email Address
                </label>
                {identity.emailVerified && (
                  <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                    <CheckCircle2 className="w-3.5 h-3.5" />
                    <span>Verified via SSO</span>
                  </span>
                )}
              </div>
              <input
                id="corp-email"
                type="email"
                value={identity.email}
                onChange={(e) => onUpdateIdentity({ email: e.target.value })}
                className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600 dark:focus:border-blue-500"
              />
            </div>

            <div>
              <label
                htmlFor="job-title"
                className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
              >
                Job Title
              </label>
              <input
                id="job-title"
                type="text"
                value={identity.jobTitle}
                onChange={(e) => onUpdateIdentity({ jobTitle: e.target.value })}
                className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600 dark:focus:border-blue-500"
              />
            </div>

            <div className="grid grid-cols-3 gap-3">
              <div className="col-span-2">
                <label
                  htmlFor="phone"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Direct Phone
                </label>
                <input
                  id="phone"
                  type="tel"
                  value={identity.phone}
                  onChange={(e) => onUpdateIdentity({ phone: e.target.value })}
                  className="w-full px-3.5 py-2 text-sm font-mono tabular-nums bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600 dark:focus:border-blue-500"
                />
              </div>
              <div>
                <label
                  htmlFor="ext"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Ext.
                </label>
                <input
                  id="ext"
                  type="text"
                  value={identity.extension}
                  onChange={(e) => onUpdateIdentity({ extension: e.target.value })}
                  className="w-full px-3 py-2 text-sm font-mono tabular-nums bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600 dark:focus:border-blue-500"
                />
              </div>
            </div>

            <div className="sm:col-span-2">
              <label
                htmlFor="bio"
                className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
              >
                Directory Scope &amp; Responsibilities
              </label>
              <textarea
                id="bio"
                rows={3}
                value={identity.bio}
                onChange={(e) => onUpdateIdentity({ bio: e.target.value })}
                className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600 dark:focus:border-blue-500 resize-y"
              />
            </div>
          </div>
        </div>
      </section>

      {/* Split 2-Column Section: Organization & ERP Attribution */}
      <section className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
          <div className="lg:col-span-4">
            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
              Organization &amp; Cost Center
            </h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
              Managed by Human Resources and Finance Operations. These attributes determine ledger posting defaults and approval routing.
            </p>
            <button
              type="button"
              onClick={() => setIsRoleModalOpen(true)}
              className="mt-4 inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer whitespace-nowrap"
            >
              <Building2 className="w-4 h-4" />
              <span>Request Assignment Change</span>
            </button>
          </div>

          <div className="lg:col-span-8">
            <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5 border-t sm:border-t-0 border-slate-200 dark:border-slate-800 pt-4 sm:pt-0">
              <div className="pb-4 border-b border-slate-100 dark:border-slate-800">
                <dt className="text-xs font-medium text-slate-500 dark:text-slate-400">
                  Employee Identifier
                </dt>
                <dd className="mt-1 text-sm font-mono tabular-nums font-medium text-slate-900 dark:text-slate-100">
                  {organization.employeeId}
                </dd>
              </div>

              <div className="pb-4 border-b border-slate-100 dark:border-slate-800">
                <dt className="text-xs font-medium text-slate-500 dark:text-slate-400">
                  Legal Entity
                </dt>
                <dd className="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">
                  {organization.legalEntity}
                </dd>
              </div>

              <div className="pb-4 border-b border-slate-100 dark:border-slate-800">
                <dt className="text-xs font-medium text-slate-500 dark:text-slate-400">
                  Primary Cost Center
                </dt>
                <dd className="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">
                  <span className="font-mono tabular-nums">{organization.costCenterCode}</span>
                  <span className="text-slate-400 mx-1.5" aria-hidden="true">·</span>
                  <span>{organization.costCenterName}</span>
                </dd>
              </div>

              <div className="pb-4 border-b border-slate-100 dark:border-slate-800">
                <dt className="text-xs font-medium text-slate-500 dark:text-slate-400">
                  Assigned ERP Role
                </dt>
                <dd className="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">
                  {organization.erpRole}
                </dd>
              </div>

              <div className="pb-4 border-b border-slate-100 dark:border-slate-800">
                <dt className="text-xs font-medium text-slate-500 dark:text-slate-400">
                  Reporting Manager
                </dt>
                <dd className="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">
                  {organization.reportingManager}
                </dd>
              </div>

              <div className="pb-4 border-b border-slate-100 dark:border-slate-800">
                <dt className="text-xs font-medium text-slate-500 dark:text-slate-400">
                  Primary Facility &amp; Effective Date
                </dt>
                <dd className="mt-1 text-sm font-medium text-slate-900 dark:text-slate-100">
                  <span>{organization.siteLocation}</span>
                  <span className="text-slate-400 mx-1.5" aria-hidden="true">·</span>
                  <span className="font-mono text-xs tabular-nums text-slate-600 dark:text-slate-400">
                    Since {organization.hireDate}
                  </span>
                </dd>
              </div>
            </dl>

            {/* Active Role Change Request Notice (flat inline banner, no left accent stripe, no pill) */}
            {roleChangeRequest && (
              <div className="mt-6 p-4 rounded-lg border border-amber-200 dark:border-amber-900/60 bg-amber-50/70 dark:bg-amber-950/30 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div className="text-sm">
                  <div className="font-medium text-amber-900 dark:text-amber-200">
                    Assignment change pending review · {roleChangeRequest.requestedRole} ({roleChangeRequest.requestedCostCenter})
                  </div>
                  <div className="mt-0.5 text-xs text-amber-800/90 dark:text-amber-300/80">
                    Submitted <span className="font-mono tabular-nums">{roleChangeRequest.submittedAt}</span> — &ldquo;{roleChangeRequest.justification}&rdquo;
                  </div>
                </div>
                <button
                  type="button"
                  onClick={onWithdrawRoleRequest}
                  className="text-xs font-medium text-amber-900 dark:text-amber-200 hover:underline shrink-0 cursor-pointer self-start sm:self-center"
                >
                  Withdraw Request
                </button>
              </div>
            )}
          </div>
        </div>
      </section>

      {/* Role / Cost Center Change Request Modal */}
      {isRoleModalOpen && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="role-modal-title"
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-[1px]"
        >
          <div className="w-full max-w-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-xl">
            <div className="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
              <h3 id="role-modal-title" className="text-base font-semibold text-slate-900 dark:text-slate-100">
                Request Role or Cost Center Change
              </h3>
              <button
                type="button"
                onClick={() => setIsRoleModalOpen(false)}
                aria-label="Close modal"
                className="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleRoleSubmit} className="mt-5 space-y-4">
              <div>
                <label
                  htmlFor="req-role"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Requested ERP Role
                </label>
                <select
                  id="req-role"
                  value={requestedRole}
                  onChange={(e) => setRequestedRole(e.target.value)}
                  className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                >
                  <option value="Procurement Approver L3">Procurement Approver L3</option>
                  <option value="Executive Finance Approver L4">Executive Finance Approver L4</option>
                  <option value="Global Inventory Controller">Global Inventory Controller</option>
                  <option value="Vendor Master Administrator">Vendor Master Administrator</option>
                </select>
              </div>

              <div>
                <label
                  htmlFor="req-cc"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Target Cost Center
                </label>
                <select
                  id="req-cc"
                  value={requestedCostCenter}
                  onChange={(e) => setRequestedCostCenter(e.target.value)}
                  className="w-full px-3.5 py-2 text-sm font-mono bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                >
                  <option value="CC-4100">CC-4100 — Strategic Sourcing</option>
                  <option value="CC-4310">CC-4310 — Logistics &amp; Distribution</option>
                  <option value="CC-2200">CC-2200 — Corporate Finance EMEA</option>
                  <option value="CC-1000">CC-1000 — Executive Operations</option>
                </select>
              </div>

              <div>
                <label
                  htmlFor="req-just"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Business Justification
                </label>
                <textarea
                  id="req-just"
                  rows={3}
                  value={justification}
                  onChange={(e) => setJustification(e.target.value)}
                  placeholder="Describe the operational reason for this assignment update..."
                  className="w-full px-3.5 py-2 text-sm bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-900 dark:text-slate-100 focus:outline-none focus:border-blue-600"
                />
              </div>

              {modalError && (
                <p className="text-xs text-red-600 dark:text-red-400">{modalError}</p>
              )}

              <div className="pt-2 flex items-center justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setIsRoleModalOpen(false)}
                  className="px-4 py-2 text-sm font-medium rounded-lg text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 text-sm font-medium rounded-lg bg-blue-700 text-white hover:bg-blue-800 cursor-pointer"
                >
                  Submit for Approval
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
