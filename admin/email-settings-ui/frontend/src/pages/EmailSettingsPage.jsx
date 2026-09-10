import { useEffect, useMemo, useState } from 'react'
import { ArrowLeft, Loader2, Save, Send } from 'lucide-react'

function getCfg() {
  return window.__EMAIL_SETTINGS_CFG__ || {}
}

function emptyForm() {
  return {
    smtp: { host: '', port: '465', user: '', pass: '', passSet: false, secure: 'ssl' },
    systemMail: {
      fromEmail: '',
      fromName: '',
      mailboxPass: '',
      mailboxPassSet: false,
      syncSmtp: true,
      useSystemPayroll: true,
      useSystemSales: true,
      useSystemPurchases: true,
      useSystemExpenses: true,
      useSystemCrm: true,
      fromPayroll: '',
      fromPayrollName: '',
      fromSales: '',
      fromSalesName: '',
      fromPurchases: '',
      fromPurchasesName: '',
      fromExpenses: '',
      fromExpensesName: '',
      fromCrm: '',
      fromCrmName: '',
    },
    imap: { host: '', port: '993', user: '', pass: '', passSet: false, ssl: 'ssl' },
    bridges: {
      ultimateEnabled: false,
      ultimateUrl: '',
      ultimateApiKey: '',
      ultimateApiKeySet: false,
      roadmasterEnabled: false,
      roadmasterUrl: '',
      roadmasterApiKey: '',
      roadmasterApiKeySet: false,
    },
  }
}

async function parseJson(res) {
  const text = await res.text()
  try {
    return JSON.parse(text)
  } catch {
    throw new Error(text.slice(0, 180) || `Request failed (${res.status})`)
  }
}

function Field({ label, children, help }) {
  return (
    <div className="es-field">
      <label className="es-label">{label}</label>
      <div className="es-control">
        {children}
        {help ? <p className="es-help">{help}</p> : null}
      </div>
    </div>
  )
}

function Card({ id, title, subtitle, children }) {
  return (
    <section className="es-card" id={id}>
      <header className="es-card-head">
        <h2>{title}</h2>
        {subtitle ? <p>{subtitle}</p> : null}
      </header>
      <div className="es-card-body">{children}</div>
    </section>
  )
}

function Check({ checked, onChange, label }) {
  return (
    <label className="es-check">
      <input type="checkbox" checked={Boolean(checked)} onChange={(e) => onChange(e.target.checked)} />
      <span>{label}</span>
    </label>
  )
}

export default function EmailSettingsPage() {
  const cfg = useMemo(() => getCfg(), [])
  const apiBase = String(cfg.apiBase || '').replace(/\/$/, '')
  const [form, setForm] = useState(() => ({
    ...emptyForm(),
    ...(cfg.initial?.form || {}),
    smtp: { ...emptyForm().smtp, ...(cfg.initial?.form?.smtp || {}) },
    systemMail: { ...emptyForm().systemMail, ...(cfg.initial?.form?.systemMail || {}) },
    imap: { ...emptyForm().imap, ...(cfg.initial?.form?.imap || {}) },
    bridges: { ...emptyForm().bridges, ...(cfg.initial?.form?.bridges || {}) },
  }))
  const [links, setLinks] = useState(cfg.initial?.links || {})
  const [loading, setLoading] = useState(!cfg.initial)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [section, setSection] = useState('smtp')

  useEffect(() => {
    if (cfg.initial) return undefined
    let cancelled = false
    ;(async () => {
      try {
        const res = await fetch(`${apiBase}/init.php`, { credentials: 'same-origin' })
        const data = await parseJson(res)
        if (!res.ok || data.success === false) throw new Error(data.error || 'Failed to load')
        if (cancelled) return
        const next = data.data || {}
        setForm({
          ...emptyForm(),
          ...(next.form || {}),
          smtp: { ...emptyForm().smtp, ...(next.form?.smtp || {}) },
          systemMail: { ...emptyForm().systemMail, ...(next.form?.systemMail || {}) },
          imap: { ...emptyForm().imap, ...(next.form?.imap || {}) },
          bridges: { ...emptyForm().bridges, ...(next.form?.bridges || {}) },
        })
        setLinks(next.links || {})
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load settings')
      } finally {
        if (!cancelled) setLoading(false)
      }
    })()
    return () => { cancelled = true }
  }, [apiBase, cfg.initial])

  function patch(path, value) {
    setForm((prev) => {
      const next = JSON.parse(JSON.stringify(prev))
      const parts = path.split('.')
      let cursor = next
      for (let i = 0; i < parts.length - 1; i += 1) cursor = cursor[parts[i]]
      cursor[parts[parts.length - 1]] = value
      return next
    })
  }

  async function handleSave(event) {
    event.preventDefault()
    setSaving(true)
    setError('')
    setNotice('')
    try {
      const res = await fetch(`${apiBase}/save.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(form),
      })
      const data = await parseJson(res)
      if (!res.ok || data.success === false) throw new Error(data.error || 'Save failed')
      const next = data.data || {}
      setForm({
        ...emptyForm(),
        ...(next.form || {}),
        smtp: { ...emptyForm().smtp, ...(next.form?.smtp || {}) },
        systemMail: { ...emptyForm().systemMail, ...(next.form?.systemMail || {}) },
        imap: { ...emptyForm().imap, ...(next.form?.imap || {}) },
        bridges: { ...emptyForm().bridges, ...(next.form?.bridges || {}) },
      })
      setLinks(next.links || links)
      setNotice(data.message || 'Saved.')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  async function handleTest(type) {
    const url = links.testApi
    if (!url) {
      setError('Test API URL missing.')
      return
    }
    setTesting(type)
    setError('')
    setNotice('')
    try {
      const body = new FormData()
      body.set('test_type', type)
      if (type === 'smtp') {
        body.set('email_smtp_host', form.smtp.host)
        body.set('email_smtp_port', form.smtp.port)
        body.set('email_smtp_user', form.smtp.user)
        body.set('email_smtp_pass', form.smtp.pass)
        body.set('email_smtp_secure', form.smtp.secure)
      } else {
        body.set('email_imap_host', form.imap.host)
        body.set('email_imap_port', form.imap.port)
        body.set('email_imap_user', form.imap.user)
        body.set('email_imap_pass', form.imap.pass)
        body.set('email_imap_ssl', form.imap.ssl)
      }
      const res = await fetch(url, { method: 'POST', credentials: 'same-origin', body })
      const data = await parseJson(res)
      if (!res.ok || data.success === false) throw new Error(data.error || data.message || 'Test failed')
      setNotice(data.message || `${type.toUpperCase()} connection OK.`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Connection test failed')
    } finally {
      setTesting('')
    }
  }

  if (loading) {
    return (
      <div className="es-page es-loading">
        <Loader2 className="es-spin" size={18} />
        <span>Loading email settings...</span>
      </div>
    )
  }

  const nav = [
    { id: 'smtp', label: 'SMTP' },
    { id: 'system', label: 'System mail' },
    { id: 'imap', label: 'IMAP' },
    { id: 'bridges', label: 'Bridges' },
  ]

  return (
    <div className="es-page">
      <div className="es-topbar">
        <a className="es-back" href={links.settingsHub || '#'}>
          <ArrowLeft size={16} />
          Settings
        </a>
      </div>

      {error ? <div className="es-flash es-flash-error" role="alert">{error}</div> : null}
      {notice ? <div className="es-flash es-flash-ok" role="status">{notice}</div> : null}

      <div className="es-layout">
        <nav className="es-nav" aria-label="Email sections">
          <ul>
            {nav.map((item) => (
              <li key={item.id}>
                <a
                  href={`#${item.id}`}
                  className={section === item.id ? 'is-active' : undefined}
                  onClick={() => setSection(item.id)}
                >
                  {item.label}
                </a>
              </li>
            ))}
          </ul>
        </nav>

        <form id="es-form" className="es-main" onSubmit={handleSave}>
          <Card id="smtp" title="SMTP outbound" subtitle="Used when the ERP sends mail directly.">
            <div className="es-grid es-grid--smtp">
              <Field label="SMTP host">
                <input className="es-input" value={form.smtp.host} onChange={(e) => patch('smtp.host', e.target.value)} placeholder="mail.example.com" />
              </Field>
              <Field label="Port">
                <input className="es-input" value={form.smtp.port} onChange={(e) => patch('smtp.port', e.target.value)} />
              </Field>
              <Field label="Encryption">
                <select className="es-input" value={form.smtp.secure} onChange={(e) => patch('smtp.secure', e.target.value)}>
                  <option value="ssl">SSL</option>
                  <option value="tls">TLS</option>
                  <option value="">None</option>
                </select>
              </Field>
              <Field label="Username">
                <input className="es-input" value={form.smtp.user} onChange={(e) => patch('smtp.user', e.target.value)} autoComplete="off" />
              </Field>
              <Field label="Password">
                <input
                  className="es-input"
                  type="password"
                  value={form.smtp.pass}
                  onChange={(e) => patch('smtp.pass', e.target.value)}
                  autoComplete="new-password"
                  placeholder={form.smtp.passSet ? 'Leave blank to keep' : ''}
                  title={form.smtp.passSet ? 'Leave blank to keep the saved password.' : 'No password stored yet.'}
                />
              </Field>
              <div className="es-field es-field--action">
                <label className="es-label">&nbsp;</label>
                <div className="es-btn-pair">
                  <button type="submit" className="es-btn es-btn-primary" disabled={saving}>
                    {saving ? <Loader2 className="es-spin" size={16} /> : <Save size={16} />}
                    Save
                  </button>
                  <button type="button" className="es-btn es-btn-secondary" disabled={Boolean(testing) || saving} onClick={() => handleTest('smtp')}>
                    {testing === 'smtp' ? <Loader2 className="es-spin" size={16} /> : <Send size={16} />}
                    Test
                  </button>
                </div>
              </div>
            </div>
          </Card>

          <Card id="system" title="System mailing identity" subtitle="Default From address and which modules use the system mailbox.">
            <div className="es-grid es-grid--system">
              <Field label="System from email">
                <input className="es-input" value={form.systemMail.fromEmail} onChange={(e) => patch('systemMail.fromEmail', e.target.value)} placeholder="noreply@ultimate.co.tz" />
              </Field>
              <Field label="System from name">
                <input className="es-input" value={form.systemMail.fromName} onChange={(e) => patch('systemMail.fromName', e.target.value)} />
              </Field>
              <Field label="Mailbox password">
                <input
                  className="es-input"
                  type="password"
                  value={form.systemMail.mailboxPass}
                  onChange={(e) => patch('systemMail.mailboxPass', e.target.value)}
                  autoComplete="new-password"
                  placeholder={form.systemMail.mailboxPassSet ? 'Leave blank to keep' : ''}
                  title={form.systemMail.mailboxPassSet ? 'Leave blank to keep the saved password.' : ''}
                />
              </Field>
            </div>
            <div className="es-checks es-checks--system">
              <Check checked={form.systemMail.syncSmtp} onChange={(v) => patch('systemMail.syncSmtp', v)} label="Sync mailbox into SMTP login" />
              <div className="es-checks-modules">
                <Check checked={form.systemMail.useSystemPayroll} onChange={(v) => patch('systemMail.useSystemPayroll', v)} label="Payroll" />
                <Check checked={form.systemMail.useSystemSales} onChange={(v) => patch('systemMail.useSystemSales', v)} label="Sales" />
                <Check checked={form.systemMail.useSystemPurchases} onChange={(v) => patch('systemMail.useSystemPurchases', v)} label="Purchases" />
                <Check checked={form.systemMail.useSystemExpenses} onChange={(v) => patch('systemMail.useSystemExpenses', v)} label="Expenses" />
                <Check checked={form.systemMail.useSystemCrm} onChange={(v) => patch('systemMail.useSystemCrm', v)} label="CRM" />
              </div>
              <button type="submit" className="es-btn es-btn-primary es-card-save" disabled={saving}>
                {saving ? <Loader2 className="es-spin" size={16} /> : <Save size={16} />}
                Save
              </button>
            </div>
            {form.systemMail.useSystemPayroll ? (
              <p className="es-help es-help--block">
                Payroll is on: publishing payslips emails employees from {form.systemMail.fromEmail || 'System from email'} using SMTP above.
              </p>
            ) : (
              <p className="es-help es-help--block">
                Payroll is off: payslips can still be published to accounts, but system mail will not email employees.
              </p>
            )}
          </Card>

          <Card id="imap" title="IMAP inbound" subtitle="Optional mailbox polling settings.">
            <div className="es-grid es-grid--smtp">
              <Field label="IMAP host">
                <input className="es-input" value={form.imap.host} onChange={(e) => patch('imap.host', e.target.value)} />
              </Field>
              <Field label="Port">
                <input className="es-input" value={form.imap.port} onChange={(e) => patch('imap.port', e.target.value)} />
              </Field>
              <Field label="Encryption">
                <select className="es-input" value={form.imap.ssl} onChange={(e) => patch('imap.ssl', e.target.value)}>
                  <option value="ssl">SSL</option>
                  <option value="tls">TLS</option>
                  <option value="">None</option>
                </select>
              </Field>
              <Field label="Username">
                <input className="es-input" value={form.imap.user} onChange={(e) => patch('imap.user', e.target.value)} autoComplete="off" />
              </Field>
              <Field label="Password">
                <input
                  className="es-input"
                  type="password"
                  value={form.imap.pass}
                  onChange={(e) => patch('imap.pass', e.target.value)}
                  autoComplete="new-password"
                  placeholder={form.imap.passSet ? 'Leave blank to keep' : ''}
                  title={form.imap.passSet ? 'Leave blank to keep the saved password.' : ''}
                />
              </Field>
              <div className="es-field es-field--action">
                <label className="es-label">&nbsp;</label>
                <div className="es-btn-pair">
                  <button type="submit" className="es-btn es-btn-primary" disabled={saving}>
                    {saving ? <Loader2 className="es-spin" size={16} /> : <Save size={16} />}
                    Save
                  </button>
                  <button type="button" className="es-btn es-btn-secondary" disabled={Boolean(testing) || saving} onClick={() => handleTest('imap')}>
                    {testing === 'imap' ? <Loader2 className="es-spin" size={16} /> : <Send size={16} />}
                    Test
                  </button>
                </div>
              </div>
            </div>
          </Card>

          <Card id="bridges" title="Company email bridges" subtitle="Remote send bridges for Ultimate / Roadmaster mail routing.">
            <div className="es-bridge">
              <h3>Ultimate</h3>
              <Check checked={form.bridges.ultimateEnabled} onChange={(v) => patch('bridges.ultimateEnabled', v)} label="Enabled" />
              <Field label="Bridge URL"><input className="es-input" value={form.bridges.ultimateUrl} onChange={(e) => patch('bridges.ultimateUrl', e.target.value)} placeholder="https://..." /></Field>
              <Field label="API key" help={form.bridges.ultimateApiKeySet ? 'Leave blank to keep the saved key.' : ''}>
                <input className="es-input" type="password" value={form.bridges.ultimateApiKey} onChange={(e) => patch('bridges.ultimateApiKey', e.target.value)} autoComplete="new-password" />
              </Field>
            </div>
            <div className="es-bridge">
              <h3>Roadmaster</h3>
              <Check checked={form.bridges.roadmasterEnabled} onChange={(v) => patch('bridges.roadmasterEnabled', v)} label="Enabled" />
              <Field label="Bridge URL"><input className="es-input" value={form.bridges.roadmasterUrl} onChange={(e) => patch('bridges.roadmasterUrl', e.target.value)} placeholder="https://..." /></Field>
              <Field label="API key" help={form.bridges.roadmasterApiKeySet ? 'Leave blank to keep the saved key.' : ''}>
                <input className="es-input" type="password" value={form.bridges.roadmasterApiKey} onChange={(e) => patch('bridges.roadmasterApiKey', e.target.value)} autoComplete="new-password" />
              </Field>
            </div>
            <div className="es-card-actions">
              <button type="submit" className="es-btn es-btn-primary" disabled={saving}>
                {saving ? <Loader2 className="es-spin" size={16} /> : <Save size={16} />}
                Save
              </button>
            </div>
          </Card>
        </form>
      </div>
    </div>
  )
}
