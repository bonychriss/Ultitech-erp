import { useMemo, useState } from 'react'
import { ExternalLink, Copy, Check } from 'lucide-react'
import voucherIcon from '../assets/modules/voucher-icon.png'
import salesIcon from '../assets/modules/sales-icon.png'
import stockIcon from '../assets/modules/stock-icon.png'
import accountingIcon from '../assets/modules/accounting-icon.png'
import payrollIcon from '../assets/modules/payroll-icon.png'
import attendanceIcon from '../assets/modules/attendance-icon.png'
import statementIcon from '../assets/modules/statement-icon.png'
import deliveryIcon from '../assets/modules/delivery-icon.png'
import backupIcon from '../assets/modules/backup-icon.png'
import crmIcon from '../assets/modules/crm-icon.png'
import letterIcon from '../assets/modules/letter-icon.png'
import budgetsIcon from '../assets/modules/budgets-icon.png'
import settingsIcon from '../assets/modules/settings-icon.png'

const MODULE_ICONS = {
  payment_voucher: voucherIcon,
  sales: salesIcon,
  stock: stockIcon,
  finance: budgetsIcon,
  accounting: accountingIcon,
  payroll: payrollIcon,
  attendance: attendanceIcon,
  revenue: statementIcon,
  logistics: deliveryIcon,
  'company-profile': letterIcon,
  backup: backupIcon,
  crm: crmIcon,
}

function getCfg() {
  return window.__COMPANY_SETTINGS_CFG__ || {}
}

function Field({ label, children, help }) {
  return (
    <div className="cs-field">
      <label className="cs-label">{label}</label>
      <div className="cs-control">
        {children}
        {help ? <p className="cs-help">{help}</p> : null}
      </div>
    </div>
  )
}

function Card({ title, subtitle, children }) {
  return (
    <section className="cs-card">
      <header className="cs-card-head">
        <h2>{title}</h2>
        {subtitle ? <p>{subtitle}</p> : null}
      </header>
      <div className="cs-card-body">{children}</div>
    </section>
  )
}

function seqPreview(prefix, suffix, next, padding, year) {
  const p = String(prefix || '').replace(/^\/+|\/+$/g, '')
  const s = String(suffix || '').replace(/^\/+|\/+$/g, '')
  const n = Math.max(1, Number(next) || 1)
  const pad = Math.max(1, Number(padding) || 3)
  const y = Number(year) || new Date().getFullYear()
  return `${p}/${y}/${s ? `${s}/` : ''}${String(n).padStart(pad, '0')}`
}

function CopyLink({ value }) {
  const [copied, setCopied] = useState(false)
  if (!value) return <p className="cs-help">No access link available yet.</p>
  return (
    <div className="cs-link-box">
      <input readOnly value={value} aria-label="Company access link" />
      <div className="cs-link-actions">
        <button
          type="button"
          className="cs-btn-ghost"
          onClick={async () => {
            try {
              await navigator.clipboard.writeText(value)
              setCopied(true)
              setTimeout(() => setCopied(false), 1500)
            } catch {
              /* ignore */
            }
          }}
        >
          {copied ? <Check size={16} /> : <Copy size={16} />}
          {copied ? 'Copied' : 'Copy'}
        </button>
        <a className="cs-btn-ghost" href={value} target="_blank" rel="noreferrer">
          <ExternalLink size={16} /> Open
        </a>
      </div>
    </div>
  )
}

export default function CompanySettingsPage() {
  const cfg = useMemo(() => getCfg(), [])
  const company = cfg.company || {}
  const settings = cfg.settings || {}
  const tabs = cfg.tabs || []
  const [activeTab, setActiveTab] = useState(cfg.activeTab || 'profile')
  const [primaryColor, setPrimaryColor] = useState(settings.primary_color || '#2563eb')
  const [logoPreview, setLogoPreview] = useState(cfg.logoUrl || '')
  const [seqState, setSeqState] = useState(() => {
    const out = {}
    ;(cfg.sequences || []).forEach((doc) => {
      out[doc.key] = {
        prefix: doc.prefixPart || '',
        suffix: doc.suffixPart || '',
        next: doc.nextNumber || 1,
        padding: doc.padding || 3,
        year: doc.year || new Date().getFullYear(),
      }
    })
    return out
  })

  const actionUrl = cfg.selfUrl || 'company-settings.php'
  const isSuperAdmin = !!cfg.isSuperAdmin
  const isPending = !!cfg.isPendingSetup

  const switchTab = (tab) => {
    setActiveTab(tab)
    const url = new URL(window.location.href)
    url.searchParams.set('tab', tab)
    url.searchParams.delete('step')
    window.history.replaceState({}, '', url.toString())
  }

  return (
    <div className="cs-page">
      <div className="cs-toolbar">
        <a className="cs-back" href={cfg.hubUrl || 'settings.php?module=settings'}>
          Back to Settings hub
        </a>
        <div className="cs-toolbar-meta">
          <span className="cs-company-name">{company.company_name || 'Company'}</span>
          <span className="cs-pill">{cfg.setupStatusLabel || company.setup_status || 'active'}</span>
        </div>
      </div>

      {cfg.message ? <div className="cs-alert cs-alert--ok">{cfg.message}</div> : null}
      {cfg.error ? <div className="cs-alert cs-alert--err">{cfg.error}</div> : null}

      {isPending ? (
        <div className="cs-alert cs-alert--info">
          Setup is pending. Complete each section, then activate the company from the Danger / Activate tab.
        </div>
      ) : null}

      <nav className="cs-tabs" aria-label="Company settings sections">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            type="button"
            className={`cs-tab${activeTab === tab.id ? ' is-active' : ''}`}
            onClick={() => switchTab(tab.id)}
          >
            {tab.label}
          </button>
        ))}
      </nav>

      {activeTab === 'profile' ? (
        <>
          <Card title="Profile" subtitle="Update core company details used across your workspace.">
            <form method="post" action={actionUrl} encType="multipart/form-data">
              <input type="hidden" name="save_profile" value="1" />
              <Field label="Company name">
                <input className="cs-input" name="company_name" defaultValue={company.company_name || ''} />
              </Field>
              <Field label="Legal name">
                <input className="cs-input" name="legal_name" defaultValue={company.legal_name || ''} />
              </Field>
              <Field label="Email">
                <input className="cs-input" type="email" name="email" defaultValue={company.email || ''} />
              </Field>
              <Field label="Phone">
                <input className="cs-input" name="phone" defaultValue={company.phone || ''} />
              </Field>
              <Field label="Address">
                <input className="cs-input" name="address" defaultValue={company.address || ''} />
              </Field>
              <Field label="Location">
                <input
                  className="cs-input"
                  name="company_location"
                  defaultValue={settings.company_location || ''}
                  placeholder="e.g. Dar es Salaam, Tanzania"
                />
              </Field>
              <Field label="Country">
                <select className="cs-input" name="country" defaultValue={company.country || 'Tanzania'}>
                  {(cfg.countryOptions || []).map((c) => (
                    <option key={c} value={c}>{c}</option>
                  ))}
                </select>
              </Field>
              <Field label="Timezone">
                <select className="cs-input" name="timezone" defaultValue={company.timezone || 'Africa/Dar_es_Salaam'}>
                  {(cfg.timezoneOptions || []).map((c) => (
                    <option key={c} value={c}>{c}</option>
                  ))}
                </select>
              </Field>
              <Field label="Base currency">
                <select className="cs-input" name="base_currency" defaultValue={company.base_currency || 'TZS'}>
                  {(cfg.currencyOptions || []).map((c) => (
                    <option key={c} value={c}>{c}</option>
                  ))}
                </select>
              </Field>
              <Field label="Database name">
                {isSuperAdmin ? (
                  <input className="cs-input" name="db_name" defaultValue={company.db_name || ''} />
                ) : (
                  <input className="cs-input" defaultValue={company.db_name || ''} readOnly />
                )}
              </Field>
              <div className="cs-actions">
                <button type="submit" className="cs-btn-primary">Save changes</button>
              </div>
            </form>
          </Card>
          <Card title="Company access link" subtitle="Share this link with your team to access the company portal.">
            <CopyLink value={cfg.companyAccessLink || ''} />
          </Card>
        </>
      ) : null}

      {activeTab === 'branding' ? (
        <Card title="Branding" subtitle="Customize workspace appearance and document headers.">
          <form method="post" action={actionUrl} encType="multipart/form-data">
            <input type="hidden" name="save_profile" value="1" />
            <input type="hidden" name="company_logo" value={settings.company_logo || ''} />
            <Field label="Company logo">
              <div className="cs-logo-box">
                {logoPreview ? (
                  <img src={logoPreview} alt="Company logo preview" />
                ) : (
                  <span className="cs-help">No logo uploaded</span>
                )}
              </div>
              <input
                className="cs-input"
                type="file"
                name="company_logo_file"
                accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"
                onChange={(e) => {
                  const file = e.target.files?.[0]
                  if (!file) return
                  setLogoPreview(URL.createObjectURL(file))
                }}
              />
            </Field>
            <Field label="Primary color" help="Used for accents on documents and branded UI.">
              <div className="cs-color-row">
                <input
                  type="color"
                  value={primaryColor}
                  onChange={(e) => setPrimaryColor(e.target.value)}
                  aria-label="Primary color picker"
                />
                <input
                  className="cs-input"
                  name="primary_color"
                  value={primaryColor}
                  onChange={(e) => setPrimaryColor(e.target.value)}
                />
              </div>
            </Field>
            <div className="cs-doc-preview" style={{ borderTopColor: primaryColor }}>
              <div className="cs-doc-preview-bar" style={{ background: primaryColor }} />
              <span className="cs-doc-preview-title" style={{ color: primaryColor }}>Quotation / Invoice preview</span>
              <p>{company.company_name || 'Company name'}</p>
            </div>
            <div className="cs-actions">
              <button type="submit" className="cs-btn-primary">Save branding</button>
            </div>
          </form>
        </Card>
      ) : null}

      {activeTab === 'finance' ? (
        <Card title="Tax & Finance" subtitle="VAT, currency, and voucher workflow options for this company.">
          <form method="post" action={actionUrl}>
            <input type="hidden" name="save_profile" value="1" />
            <Field label="VAT rate (%)">
              <input className="cs-input" name="vat_rate" defaultValue={settings.vat_rate || '18'} />
            </Field>
            <Field label="Date format">
              <input className="cs-input" name="date_format" defaultValue={settings.date_format || 'Y-m-d'} />
            </Field>
            <Field label="Base currency">
              <input className="cs-input" name="base_currency" defaultValue={company.base_currency || 'TZS'} />
            </Field>
            <Field label="Financial year start (MM-DD)">
              <input className="cs-input" name="financial_year_start" defaultValue={settings.financial_year_start || '01-01'} />
            </Field>
            <Field label="Tax mode" help="Controls whether quotations and invoices add tax on top of prices or treat entered prices as tax-inclusive.">
              <select className="cs-input" name="tax_calculation_mode" defaultValue={settings.tax_calculation_mode || 'exclusive'}>
                <option value="exclusive">Tax exclusive</option>
                <option value="inclusive">Tax inclusive</option>
              </select>
            </Field>
            <Field label="TIN">
              <input className="cs-input" name="company_tin" defaultValue={settings.company_tin || ''} />
            </Field>
            <Field label="VRN / VAT registration">
              <input className="cs-input" name="company_vat" defaultValue={settings.company_vat || ''} />
            </Field>
            <Field label="Location">
              <input className="cs-input" name="company_location" defaultValue={settings.company_location || ''} />
            </Field>
            <Field label="Payment details" help="Shown on quotations and invoices for customer payments.">
              <textarea className="cs-input" name="bank_details" rows={5} defaultValue={settings.bank_details || ''} />
            </Field>
            <Field label="Document footer" help="Printed at the bottom of quotations and invoices.">
              <textarea className="cs-input" name="document_footer_message" rows={4} defaultValue={settings.document_footer_message || ''} />
            </Field>
            <Field label="Workflow">
              <input type="hidden" name="approval_workflow_enabled" value="0" />
              <label className="cs-check">
                <input type="checkbox" name="approval_workflow_enabled" value="1" defaultChecked={settings.approval_workflow_enabled === '1'} />
                Approval workflow enabled
              </label>
              <input type="hidden" name="allow_edit_approved_voucher_classification" value="0" />
              <label className="cs-check">
                <input
                  type="checkbox"
                  name="allow_edit_approved_voucher_classification"
                  value="1"
                  defaultChecked={settings.allow_edit_approved_voucher_classification === '1'}
                />
                Allow limited edit of approved payment vouchers
              </label>
            </Field>
            <div className="cs-actions">
              <button type="submit" className="cs-btn-primary">Save changes</button>
            </div>
          </form>
        </Card>
      ) : null}

      {activeTab === 'modules' ? (
        <Card title="Modules" subtitle="Enable modules available for this company.">
          <form method="post" action={actionUrl}>
            <input type="hidden" name="save_modules" value="1" />
            <div className="cs-module-grid">
              {(cfg.modules || []).map((mod) => {
                const iconSrc = MODULE_ICONS[mod.key] || settingsIcon
                return (
                  <label key={mod.key} className="cs-module-card">
                    <div className="cs-module-top">
                      <span className="cs-module-title">
                        <img src={iconSrc} alt="" className="cs-module-icon" width={28} height={28} />
                        <span>{mod.label}</span>
                      </span>
                      <input
                        type="checkbox"
                        name={`module_enabled[${mod.key}]`}
                        value="1"
                        defaultChecked={!!mod.enabled}
                      />
                    </div>
                    <p>{mod.description}</p>
                    <input type="hidden" name={`module_name[${mod.key}]`} value={mod.label} />
                    <input
                      className="cs-input"
                      name={`custom_label[${mod.key}]`}
                      defaultValue={mod.customLabel || ''}
                      placeholder="Custom label (optional)"
                    />
                  </label>
                )
              })}
            </div>
            <div className="cs-actions">
              <button type="submit" className="cs-btn-primary">Save modules</button>
            </div>
          </form>
        </Card>
      ) : null}

      {activeTab === 'numbering' ? (
        <Card title="Document Numbering" subtitle="Numbers follow PREFIX/YEAR/SUFFIX/###.">
          <form method="post" action={actionUrl}>
            <input type="hidden" name="save_sequences" value="1" />
            {(cfg.sequences || []).map((doc) => {
              const state = seqState[doc.key] || {}
              return (
                <div key={doc.key} className="cs-seq-block">
                  <h3>{doc.label}</h3>
                  <p className="cs-help">{doc.subtitle}</p>
                  <Field label="Next number preview">
                    <input
                      className="cs-input"
                      readOnly
                      value={seqPreview(state.prefix, state.suffix, state.next, state.padding, state.year)}
                    />
                  </Field>
                  <Field label="Number format">
                    <div className="cs-seq-row">
                      <input
                        className="cs-input"
                        name={`${doc.key}_prefix_part`}
                        value={state.prefix}
                        onChange={(e) =>
                          setSeqState((prev) => ({ ...prev, [doc.key]: { ...prev[doc.key], prefix: e.target.value } }))
                        }
                        placeholder="e.g. PV"
                      />
                      <span>/{'{YEAR}'}/</span>
                      <input
                        className="cs-input"
                        name={`${doc.key}_suffix_part`}
                        value={state.suffix}
                        onChange={(e) =>
                          setSeqState((prev) => ({ ...prev, [doc.key]: { ...prev[doc.key], suffix: e.target.value } }))
                        }
                        placeholder="optional"
                      />
                    </div>
                  </Field>
                  <div className="cs-seq-grid">
                    <Field label="Next number">
                      <input
                        className="cs-input"
                        type="number"
                        min="1"
                        name={`${doc.key}_next_number`}
                        value={state.next}
                        onChange={(e) =>
                          setSeqState((prev) => ({ ...prev, [doc.key]: { ...prev[doc.key], next: e.target.value } }))
                        }
                      />
                    </Field>
                    <Field label="Padding">
                      <input
                        className="cs-input"
                        type="number"
                        min="1"
                        name={`${doc.key}_padding`}
                        value={state.padding}
                        onChange={(e) =>
                          setSeqState((prev) => ({ ...prev, [doc.key]: { ...prev[doc.key], padding: e.target.value } }))
                        }
                      />
                    </Field>
                    <Field label="Year">
                      <input
                        className="cs-input"
                        type="number"
                        name={`${doc.key}_year`}
                        value={state.year}
                        onChange={(e) =>
                          setSeqState((prev) => ({ ...prev, [doc.key]: { ...prev[doc.key], year: e.target.value } }))
                        }
                      />
                    </Field>
                  </div>
                </div>
              )
            })}
            <div className="cs-actions">
              <button type="submit" className="cs-btn-primary">Save numbering</button>
            </div>
          </form>
          {(cfg.legacyVoucherPrefixCount || 0) > 0 ? (
            <form method="post" action={actionUrl} className="cs-migrate">
              <input type="hidden" name="migrate_voucher_prefixes" value="1" />
              <p>
                {cfg.legacyVoucherPrefixCount} legacy payment voucher number(s) do not match the current prefix
                ({cfg.currentPvPrefix || 'n/a'}).
              </p>
              <button type="submit" className="cs-btn-ghost">Migrate legacy voucher prefixes</button>
            </form>
          ) : null}
        </Card>
      ) : null}

      {activeTab === 'employees' ? (
        <>
          <Card title="Register company admin" subtitle="Creates a company admin and emails login details.">
            <form method="post" action={actionUrl}>
              <input type="hidden" name="register_admin_by_email" value="1" />
              <Field label="Email">
                <input className="cs-input" type="email" name="admin_email" required />
              </Field>
              <Field label="Full name">
                <input className="cs-input" name="admin_full_name" />
              </Field>
              <Field label="Phone">
                <input className="cs-input" name="admin_phone" />
              </Field>
              <div className="cs-actions">
                <button type="submit" className="cs-btn-primary">Register admin</button>
              </div>
            </form>
          </Card>

          <Card title="Register employee" subtitle="Creates an employee account and emails login details.">
            <form method="post" action={actionUrl}>
              <input type="hidden" name="register_employee_by_email" value="1" />
              <Field label="Email">
                <input className="cs-input" type="email" name="employee_email" required />
              </Field>
              <Field label="Full name">
                <input className="cs-input" name="employee_full_name" />
              </Field>
              <Field label="Department">
                <select className="cs-input" name="employee_department" defaultValue="General">
                  {(cfg.departments || []).map((d) => (
                    <option key={d} value={d}>{d}</option>
                  ))}
                </select>
              </Field>
              <div className="cs-actions">
                <button type="submit" className="cs-btn-primary">Register employee</button>
              </div>
            </form>
          </Card>

          <Card title="Registration settings" subtitle="Control how new employees join this company.">
            <form method="post" action={actionUrl}>
              <input type="hidden" name="save_profile" value="1" />
              <Field label="Registration mode">
                <select
                  className="cs-input"
                  name="employee_registration_mode"
                  defaultValue={company.employee_registration_mode || 'admin_only'}
                >
                  {Object.entries(cfg.employeeModeLabels || {}).map(([id, label]) => (
                    <option key={id} value={id}>{label}</option>
                  ))}
                </select>
              </Field>
              <Field label="Invite code">
                <input className="cs-input" name="invite_code" defaultValue={company.invite_code || ''} />
                <label className="cs-check" style={{ marginTop: 8 }}>
                  <input type="checkbox" name="regenerate_invite_code" value="1" />
                  Regenerate invite code on save
                </label>
              </Field>
              <Field label="Options">
                <input type="hidden" name="allow_employee_self_registration" value="0" />
                <label className="cs-check">
                  <input
                    type="checkbox"
                    name="allow_employee_self_registration"
                    value="1"
                    defaultChecked={Number(company.allow_employee_self_registration) === 1}
                  />
                  Allow employee self-registration
                </label>
                <input type="hidden" name="require_admin_approval_for_new_users" value="0" />
                <label className="cs-check">
                  <input
                    type="checkbox"
                    name="require_admin_approval_for_new_users"
                    value="1"
                    defaultChecked={Number(company.require_admin_approval_for_new_users) === 1}
                  />
                  Require admin approval for new users
                </label>
              </Field>
              <Field label="Users to register (wizard)">
                <input
                  className="cs-input"
                  type="number"
                  min="0"
                  name="users_to_register_count"
                  defaultValue={settings.users_to_register_count || '0'}
                />
              </Field>
              <div className="cs-actions">
                <button type="submit" className="cs-btn-primary">Save registration settings</button>
              </div>
            </form>
            <div style={{ marginTop: 16 }}>
              <p className="cs-help">Employee invite link</p>
              <CopyLink value={cfg.employeeInviteLink || ''} />
            </div>
          </Card>

          <Card title="Admins" subtitle="Company administrators">
            <ul className="cs-user-list">
              {(cfg.admins || []).map((u) => (
                <li key={u.id}>
                  <span>{u.full_name || u.username}</span>
                  <span>{u.email}</span>
                  <em>{u.role}</em>
                </li>
              ))}
              {(cfg.admins || []).length === 0 ? <li className="cs-help">No admins yet.</li> : null}
            </ul>
          </Card>

          <Card title="Recent employees" subtitle="Last 25 employees">
            <ul className="cs-user-list">
              {(cfg.employees || []).map((u) => (
                <li key={u.id}>
                  <span>{u.full_name || u.username}</span>
                  <span>{u.email}</span>
                  <em>{u.department || 'General'}</em>
                </li>
              ))}
              {(cfg.employees || []).length === 0 ? <li className="cs-help">No employees yet.</li> : null}
            </ul>
            {cfg.companyUsersUrl ? (
              <a className="cs-back" href={cfg.companyUsersUrl}>View all company users</a>
            ) : null}
          </Card>
        </>
      ) : null}

      {activeTab === 'security' ? (
        <Card title="Security" subtitle="Security controls for this company.">
          <p className="cs-help">
            Login passwords are managed per user. Use employee registration and company users tools to manage access.
            Multi-factor and SSO options can be added in a later release.
          </p>
        </Card>
      ) : null}

      {activeTab === 'danger' && isSuperAdmin ? (
        <Card title="Danger zone" subtitle="Lifecycle controls for this company tenant.">
          <form method="post" action={actionUrl}>
            <input type="hidden" name="save_profile" value="1" />
            <Field label="Status">
              <select className="cs-input" name="status" defaultValue={company.status || 'active'}>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </Field>
            <Field label="Setup status">
              <select className="cs-input" name="setup_status" defaultValue={company.setup_status || 'pending_setup'}>
                <option value="active">Active</option>
                <option value="pending_setup">Pending setup</option>
                <option value="suspended">Suspended</option>
              </select>
            </Field>
            {isPending ? (
              <label className="cs-check">
                <input type="checkbox" name="complete_setup" value="1" />
                Mark setup complete and continue to module selection
              </label>
            ) : null}
            <div className="cs-actions">
              <button type="submit" className="cs-btn-danger">Save lifecycle settings</button>
            </div>
          </form>
        </Card>
      ) : null}
    </div>
  )
}
