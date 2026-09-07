import { useState } from 'react'

function getCfg() {
  return window.__MY_ACCOUNT_CFG__ || {}
}

function formatMoney(amount) {
  const n = Number(amount || 0)
  return `$${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

export default function MyAccountPage() {
  const cfg = getCfg()
  const homeUrl = cfg.homeUrl || './'
  const logoutUrl = cfg.logoutUrl || 'logout.php'
  const enterWorkspaceUrl = cfg.enterWorkspaceUrl || 'login.php'
  const viewAllUrl = cfg.viewAllPaymentsUrl || 'all-vouchers.php'
  const saveEmailUrl = cfg.saveEmailUrl || 'api/save_user_email_settings.php'
  const accountTitle = cfg.accountTitle || 'GUEST ACCOUNT'
  const accountUrl = cfg.accountUrl || ''
  const accountUpdated = cfg.accountUpdated || ''
  const accountStatus = cfg.accountStatus || 'active'
  const accountName = cfg.accountName || accountTitle
  const companiesReady = !!cfg.companiesReady
  const payments = Array.isArray(cfg.payments) ? cfg.payments : []
  const isAuthed = !!cfg.isAuthed
  const year = cfg.year || new Date().getFullYear()

  const initialEmail = cfg.emailSettings || {}
  const [emailForm, setEmailForm] = useState({
    imap_host: initialEmail.imap_host || '',
    imap_port: initialEmail.imap_port || '993',
    imap_user: initialEmail.imap_user || '',
    imap_pass: initialEmail.imap_pass || '',
    imap_ssl: initialEmail.imap_ssl ?? 'ssl',
    smtp_host: initialEmail.smtp_host || '',
    smtp_port: initialEmail.smtp_port || '465',
    smtp_user: initialEmail.smtp_user || '',
    smtp_pass: initialEmail.smtp_pass || '',
    smtp_ssl: initialEmail.smtp_ssl ?? 'ssl',
  })
  const [saving, setSaving] = useState(false)
  const [activeMenu, setActiveMenu] = useState('dashboard')

  const onChange = (key) => (e) => {
    setEmailForm((prev) => ({ ...prev, [key]: e.target.value }))
  }

  const saveEmailSettings = async (e) => {
    e.preventDefault()
    if (!isAuthed) {
      if (window.Swal) {
        window.Swal.fire('Sign in required', 'Please log in to save email settings.', 'info')
      }
      return
    }
    setSaving(true)
    try {
      const body = new FormData()
      Object.entries(emailForm).forEach(([k, v]) => body.append(k, v))
      const res = await fetch(saveEmailUrl, { method: 'POST', body })
      const data = await res.json()
      if (data.status === 'success') {
        if (window.Swal) {
          window.Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: 'Email settings saved successfully!',
            showConfirmButton: false,
            timer: 3000,
          })
        }
      } else if (window.Swal) {
        window.Swal.fire('Error', data.message || 'Failed to save settings', 'error')
      }
    } catch {
      if (window.Swal) {
        window.Swal.fire('Error', 'Network connection failed', 'error')
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="ma-page">
      <header className="ma-topbar">
        <a href={homeUrl} className="ma-logo">
          <span>UltiTech ERP</span>
        </a>
        <nav className="ma-nav">
          <a href={homeUrl}>Modules</a>
          <a href={homeUrl}>Industries</a>
          <a href={homeUrl}>Pricing</a>
          <a href={cfg.selfUrl || 'my-account.php'} className="ma-btn-account">
            <i className="fa-regular fa-user" aria-hidden="true" /> My Account
          </a>
        </nav>
      </header>

      <section className="ma-layout">
        <aside className="ma-sidebar">
          <button type="button" className={`ma-menu-item${activeMenu === 'dashboard' ? ' active' : ''}`} onClick={() => setActiveMenu('dashboard')}>
            <i className="fa-solid fa-house" aria-hidden="true" /> Dashboard
          </button>
          <button type="button" className={`ma-menu-item${activeMenu === 'payments' ? ' active' : ''}`} onClick={() => setActiveMenu('payments')}>
            <i className="fa-regular fa-credit-card" aria-hidden="true" /> My Payments
          </button>
          <button type="button" className={`ma-menu-item${activeMenu === 'subscription' ? ' active' : ''}`} onClick={() => setActiveMenu('subscription')}>
            <i className="fa-solid fa-crown" aria-hidden="true" /> Subscription
          </button>
          <button type="button" className={`ma-menu-item${activeMenu === 'profile' ? ' active' : ''}`} onClick={() => setActiveMenu('profile')}>
            <i className="fa-regular fa-user" aria-hidden="true" /> Profile
          </button>
          <a className="ma-menu-item" href={cfg.registerUrl || 'register.php'}>
            <i className="fa-solid fa-user-plus" aria-hidden="true" /> Create New Account
          </a>
          <div className="ma-menu-spacer" />
          {isAuthed ? (
            <a className="ma-menu-item" href={logoutUrl}>
              <i className="fa-solid fa-right-from-bracket" aria-hidden="true" /> Logout
            </a>
          ) : (
            <a className="ma-menu-item" href={cfg.loginUrl || 'login.php'}>
              <i className="fa-solid fa-right-to-bracket" aria-hidden="true" /> Login
            </a>
          )}
        </aside>

        <div className="ma-main">
          <div className="ma-account-card">
            <div className="ma-account-row">
              <div className="ma-account-left">
                <div className="ma-avatar">
                  <i className="fa-regular fa-user" aria-hidden="true" />
                </div>
                <div>
                  <h2 className="ma-acc-title">{accountTitle}</h2>
                  <div className="ma-acc-url">{accountUrl}</div>
                  <div className="ma-acc-meta">
                    Updated on: {accountUpdated}
                    <span className="ma-badge">{accountStatus}</span>
                  </div>
                </div>
              </div>
              <div className="ma-acc-actions">
                <a className="ma-btn ma-btn-upgrade" href={cfg.upgradeUrl || '#'}>
                  <i className="fa-regular fa-paper-plane" aria-hidden="true" /> Upgrade Plan
                </a>
                <a className="ma-btn ma-btn-enter" href={enterWorkspaceUrl}>
                  <i className="fa-solid fa-arrow-right-to-bracket" aria-hidden="true" /> Enter Workspace
                </a>
              </div>
            </div>
            <div className="ma-menu-dots">
              <i className="fa-solid fa-ellipsis-vertical" aria-hidden="true" />
            </div>
          </div>

          {!companiesReady ? (
            <div className="ma-notice">
              <i className="fa-regular fa-circle-info" aria-hidden="true" />
              System details are not available yet. Create or import your company to display live account data.
            </div>
          ) : null}

          <div className="ma-payments" id="payments">
            <div className="ma-payments-head">
              <div className="ma-payments-title">
                <i className="fa-solid fa-wallet" aria-hidden="true" /> Payments
              </div>
              <a className="ma-view-all" href={viewAllUrl}>
                <i className="fa-regular fa-list" aria-hidden="true" /> View All
              </a>
            </div>
            <table className="ma-table">
              <thead>
                <tr>
                  <th>Account</th>
                  <th>Date</th>
                  <th>Amount</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {payments.length > 0 ? (
                  payments.map((payment) => (
                    <tr key={payment.id || `${payment.voucher_no}-${payment.payment_date}`}>
                      <td>{accountName}</td>
                      <td>{payment.payment_date_label || '-'}</td>
                      <td>{formatMoney(payment.amount)}</td>
                      <td>
                        {payment.receipt_url ? (
                          <a className="ma-receipt-link" href={payment.receipt_url}>
                            View Receipt
                          </a>
                        ) : (
                          <span className="ma-muted-action">No receipt</span>
                        )}
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td>{accountName}</td>
                    <td>-</td>
                    <td>$0.00</td>
                    <td>
                      <span className="ma-muted-action">
                        <i className="fa-regular fa-clock" aria-hidden="true" /> No payments yet
                      </span>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <div className="ma-email-card" id="email">
            <h3>
              <i className="fa-regular fa-envelope" aria-hidden="true" /> Personal Email Settings
            </h3>
            <p>
              Configure your personal IMAP/SMTP credentials so you can send and receive emails securely inside the ERP.
            </p>

            <form onSubmit={saveEmailSettings}>
              <h4>Incoming Server (IMAP)</h4>
              <div className="ma-form-grid" style={{ marginBottom: 24 }}>
                <div className="ma-form-group">
                  <label htmlFor="imap_host">IMAP Host</label>
                  <input id="imap_host" type="text" placeholder="e.g. mail.domain.com" value={emailForm.imap_host} onChange={onChange('imap_host')} required />
                </div>
                <div className="ma-form-group">
                  <label>IMAP Port &amp; Encryption</label>
                  <div className="ma-port-row">
                    <input type="number" placeholder="993" value={emailForm.imap_port} onChange={onChange('imap_port')} required />
                    <select value={emailForm.imap_ssl} onChange={onChange('imap_ssl')}>
                      <option value="ssl">SSL (Recommended)</option>
                      <option value="tls">TLS</option>
                      <option value="">None</option>
                    </select>
                  </div>
                </div>
                <div className="ma-form-group">
                  <label htmlFor="imap_user">Email Address / Username</label>
                  <input id="imap_user" type="email" placeholder="you@domain.com" value={emailForm.imap_user} onChange={onChange('imap_user')} required />
                </div>
                <div className="ma-form-group">
                  <label htmlFor="imap_pass">Email Password</label>
                  <input id="imap_pass" type="password" placeholder="••••••••" value={emailForm.imap_pass} onChange={onChange('imap_pass')} required />
                </div>
              </div>

              <h4>Outgoing Server (SMTP)</h4>
              <div className="ma-form-grid">
                <div className="ma-form-group">
                  <label htmlFor="smtp_host">SMTP Host</label>
                  <input id="smtp_host" type="text" placeholder="e.g. mail.domain.com" value={emailForm.smtp_host} onChange={onChange('smtp_host')} required />
                </div>
                <div className="ma-form-group">
                  <label>SMTP Port &amp; Encryption</label>
                  <div className="ma-port-row">
                    <input type="number" placeholder="465" value={emailForm.smtp_port} onChange={onChange('smtp_port')} required />
                    <select value={emailForm.smtp_ssl} onChange={onChange('smtp_ssl')}>
                      <option value="ssl">SSL (Recommended)</option>
                      <option value="tls">TLS</option>
                      <option value="">None</option>
                    </select>
                  </div>
                </div>
                <div className="ma-form-group">
                  <label htmlFor="smtp_user">SMTP Username (Usually same as IMAP)</label>
                  <input id="smtp_user" type="text" placeholder="you@domain.com" value={emailForm.smtp_user} onChange={onChange('smtp_user')} required />
                </div>
                <div className="ma-form-group">
                  <label htmlFor="smtp_pass">SMTP Password</label>
                  <input id="smtp_pass" type="password" placeholder="••••••••" value={emailForm.smtp_pass} onChange={onChange('smtp_pass')} required />
                </div>
              </div>

              <button type="submit" className="ma-btn-save" disabled={saving}>
                <i className={`fa-solid ${saving ? 'fa-circle-notch fa-spin' : 'fa-cloud-arrow-up'}`} aria-hidden="true" />
                {saving ? 'Saving…' : 'Save Settings'}
              </button>
            </form>
          </div>
        </div>
      </section>

      <footer className="ma-footer">
        <div>
          <a className="ma-footer-brand" href={homeUrl}>
            UltiTech ERP
          </a>
          <div className="ma-footer-copy">Powerful ERP solutions to streamline your business and drive growth.</div>
          <div className="ma-social" aria-hidden="true">
            <i className="fa-brands fa-linkedin-in" />
            <i className="fa-brands fa-twitter" />
            <i className="fa-brands fa-facebook-f" />
            <i className="fa-brands fa-youtube" />
          </div>
        </div>
        <div>
          <h4>Product</h4>
          <a href={homeUrl}>Overview</a>
          <a href={homeUrl}>Pricing</a>
          <a href={homeUrl}>Features</a>
          <a href={homeUrl}>Updates</a>
        </div>
        <div>
          <h4>Modules</h4>
          <a href={homeUrl}>Billing &amp; Invoicing</a>
        </div>
        <div>
          <h4>Industries</h4>
          <a href={homeUrl}>Law Firms</a>
        </div>
        <div>
          <h4>Support</h4>
          <a href="#">Help Center</a>
          <a href="#">Contact Us</a>
          <a href="#">System Status</a>
          <h4 style={{ marginTop: 14 }}>Contact Us</h4>
          <a href="mailto:support@ultitecherp.com">support@ultitecherp.com</a>
          <a href="tel:+15551234567">+1 (555) 123-4567</a>
          <a href="#">123 Business Ave, Suite 100, New York, NY 10001</a>
        </div>
      </footer>
      <div className="ma-legal">
        <span>&copy; {year} UltiTech ERP. All rights reserved.</span>
        <a href="#">Privacy Policy</a>
        <a href="#">Terms of Service</a>
        <a href="#">Cookies</a>
      </div>
    </div>
  )
}
