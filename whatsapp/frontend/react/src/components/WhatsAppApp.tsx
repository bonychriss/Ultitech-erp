import { useEffect, useState, type FormEvent } from 'react'
import {
  MdCampaign,
  MdContacts,
  MdHistory,
  MdLogout,
  MdSend,
  MdSettings,
  MdWhatsapp,
} from 'react-icons/md'
import {
  api,
  type Campaign,
  type Contact,
  type MessageItem,
  type Settings,
} from '../api'

type Tab = 'compose' | 'contacts' | 'broadcast' | 'history' | 'settings'

type Props = {
  user: { id: number; username: string; email: string }
  settingsConfigured: boolean
  stats: { customers: number; staff: number; sentToday: number }
  onLogout: () => void
  onStatsRefresh: () => Promise<void>
}

const MATTERS = [
  { value: 'general', label: 'General' },
  { value: 'order', label: 'Order / delivery' },
  { value: 'payment', label: 'Payment' },
  { value: 'promo', label: 'Promotion' },
  { value: 'staff', label: 'Staff notice' },
  { value: 'alert', label: 'Urgent alert' },
]

export function WhatsAppApp({
  user,
  settingsConfigured,
  stats,
  onLogout,
  onStatsRefresh,
}: Props) {
  const [tab, setTab] = useState<Tab>(settingsConfigured ? 'compose' : 'settings')
  const [contacts, setContacts] = useState<Contact[]>([])
  const [messages, setMessages] = useState<MessageItem[]>([])
  const [campaigns, setCampaigns] = useState<Campaign[]>([])
  const [settings, setSettings] = useState<Settings | null>(null)
  const [webhookUrl, setWebhookUrl] = useState('')
  const [flash, setFlash] = useState('')
  const [error, setError] = useState('')

  const [contactFilter, setContactFilter] = useState('')
  const [contactType, setContactType] = useState('')
  const [contactForm, setContactForm] = useState({
    name: '',
    phone: '',
    type: 'customer' as 'customer' | 'staff',
    tags: '',
  })

  const [sendForm, setSendForm] = useState({
    contact_id: '',
    phone: '',
    matter: 'general',
    body: '',
  })

  const [campaignForm, setCampaignForm] = useState({
    title: '',
    audience: 'customers',
    body: '',
  })

  const [settingsForm, setSettingsForm] = useState({
    phone_number_id: '',
    business_account_id: '',
    display_phone: '',
    access_token: '',
    webhook_verify_token: '',
    auto_reply_enabled: false,
    auto_reply_text: '',
  })

  useEffect(() => {
    void refreshTab(tab)
  }, [tab])

  async function refreshTab(t: Tab) {
    setError('')
    try {
      if (t === 'contacts' || t === 'compose') {
        const res = await api.contacts(contactType || undefined, contactFilter || undefined)
        setContacts(res.contacts)
      }
      if (t === 'history') {
        const res = await api.messages()
        setMessages(res.messages)
      }
      if (t === 'broadcast') {
        const res = await api.campaigns()
        setCampaigns(res.campaigns)
      }
      if (t === 'settings') {
        const res = await api.settings()
        setSettings(res.settings)
        setWebhookUrl(res.webhookUrl)
        setSettingsForm({
          phone_number_id: res.settings.phone_number_id,
          business_account_id: res.settings.business_account_id,
          display_phone: res.settings.display_phone,
          access_token: '',
          webhook_verify_token: res.settings.webhook_verify_token,
          auto_reply_enabled: res.settings.auto_reply_enabled,
          auto_reply_text: res.settings.auto_reply_text || '',
        })
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load')
    }
  }

  async function handleLogout() {
    await api.logout()
    onLogout()
  }

  async function saveContact(e: FormEvent) {
    e.preventDefault()
    setError('')
    setFlash('')
    try {
      await api.createContact(contactForm)
      setContactForm({ name: '', phone: '', type: 'customer', tags: '' })
      setFlash('Contact saved.')
      await refreshTab('contacts')
      await onStatsRefresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not save contact')
    }
  }

  async function removeContact(id: number) {
    if (!confirm('Delete this contact?')) return
    await api.deleteContact(id)
    await refreshTab('contacts')
    await onStatsRefresh()
  }

  async function sendMessage(e: FormEvent) {
    e.preventDefault()
    setError('')
    setFlash('')
    try {
      const payload: {
        body: string
        matter: string
        contact_id?: number
        phone?: string
      } = {
        body: sendForm.body,
        matter: sendForm.matter,
      }
      if (sendForm.contact_id) payload.contact_id = Number(sendForm.contact_id)
      else payload.phone = sendForm.phone

      const res = await api.send(payload)
      setFlash(res.message)
      if (res.ok) {
        setSendForm((s) => ({ ...s, body: '' }))
        await onStatsRefresh()
      } else {
        setError(res.message)
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Send failed')
    }
  }

  async function createCampaign(e: FormEvent) {
    e.preventDefault()
    setError('')
    setFlash('')
    try {
      const res = await api.createCampaign(campaignForm)
      setFlash('Campaign created. Click Send to deliver.')
      setCampaignForm({ title: '', audience: 'customers', body: '' })
      setCampaigns((prev) => [res.campaign, ...prev])
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not create campaign')
    }
  }

  async function sendCampaign(id: number) {
    if (!confirm('Send this broadcast to the selected audience now?')) return
    setError('')
    setFlash('')
    try {
      const res = await api.sendCampaign(id)
      setFlash(res.message)
      setCampaigns((prev) => prev.map((c) => (c.id === id ? res.campaign : c)))
      await onStatsRefresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Broadcast failed')
    }
  }

  async function saveSettings(e: FormEvent) {
    e.preventDefault()
    setError('')
    setFlash('')
    try {
      const payload: Record<string, unknown> = { ...settingsForm }
      if (!settingsForm.access_token) delete payload.access_token
      const res = await api.saveSettings(payload)
      setSettings(res.settings)
      setWebhookUrl(res.webhookUrl)
      setSettingsForm((s) => ({ ...s, access_token: '' }))
      setFlash('Settings saved.')
      await onStatsRefresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not save settings')
    }
  }

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="brand">
          <div className="brand-mark">
            <MdWhatsapp />
          </div>
          <div>
            <strong>WhatsApp Bot</strong>
            <span>{user.username}</span>
          </div>
        </div>
        {(
          [
            ['compose', 'Compose', MdSend],
            ['contacts', 'Contacts', MdContacts],
            ['broadcast', 'Broadcast', MdCampaign],
            ['history', 'History', MdHistory],
            ['settings', 'Settings', MdSettings],
          ] as const
        ).map(([id, label, Icon]) => (
          <button
            key={id}
            type="button"
            className={`nav-btn${tab === id ? ' active' : ''}`}
            onClick={() => setTab(id)}
          >
            <Icon size={18} />
            {label}
          </button>
        ))}
        <div className="sidebar-foot">
          <button type="button" className="btn btn-ghost" style={{ width: '100%' }} onClick={handleLogout}>
            <MdLogout /> Sign out
          </button>
        </div>
      </aside>

      <main className="main">
        {!settingsConfigured && tab !== 'settings' ? (
          <div className="warn-banner">
            Connect Meta WhatsApp Cloud API in Settings before sending messages.
          </div>
        ) : null}

        {error ? <div className="error-text">{error}</div> : null}
        {flash ? <div className="success-text">{flash}</div> : null}

        {tab === 'compose' ? (
          <>
            <div className="page-head">
              <div>
                <h2>Compose</h2>
                <p>Message one customer or staff member about a specific matter.</p>
              </div>
            </div>
            <div className="stats">
              <div className="stat">
                <div className="label">Customers</div>
                <div className="value">{stats.customers}</div>
              </div>
              <div className="stat">
                <div className="label">Staff</div>
                <div className="value">{stats.staff}</div>
              </div>
              <div className="stat">
                <div className="label">Sent today</div>
                <div className="value">{stats.sentToday}</div>
              </div>
            </div>
            <form className="panel" onSubmit={sendMessage}>
              <h3>New message</h3>
              <div className="grid-2">
                <div className="field">
                  <label>Saved contact</label>
                  <select
                    value={sendForm.contact_id}
                    onChange={(e) =>
                      setSendForm((s) => ({ ...s, contact_id: e.target.value, phone: '' }))
                    }
                  >
                    <option value=""> or enter phone below </option>
                    {contacts.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name} ({c.type})  {c.phone}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="field">
                  <label>Or phone (country code, no +)</label>
                  <input
                    placeholder="2557XXXXXXXX"
                    value={sendForm.phone}
                    disabled={!!sendForm.contact_id}
                    onChange={(e) => setSendForm((s) => ({ ...s, phone: e.target.value }))}
                  />
                </div>
              </div>
              <div className="field">
                <label>Matter</label>
                <select
                  value={sendForm.matter}
                  onChange={(e) => setSendForm((s) => ({ ...s, matter: e.target.value }))}
                >
                  {MATTERS.map((m) => (
                    <option key={m.value} value={m.value}>
                      {m.label}
                    </option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label>Message</label>
                <textarea
                  rows={6}
                  required
                  value={sendForm.body}
                  onChange={(e) => setSendForm((s) => ({ ...s, body: e.target.value }))}
                  placeholder="Write the WhatsApp message"
                />
              </div>
              <button className="btn btn-primary" type="submit" style={{ width: 'auto', minWidth: 160 }}>
                <MdSend /> Send WhatsApp
              </button>
            </form>
          </>
        ) : null}

        {tab === 'contacts' ? (
          <>
            <div className="page-head">
              <div>
                <h2>Contacts</h2>
                <p>Keep customers and staff numbers ready for quick sends.</p>
              </div>
            </div>
            <div className="grid-2">
              <form className="panel" onSubmit={saveContact}>
                <h3>Add contact</h3>
                <div className="field">
                  <label>Name</label>
                  <input
                    required
                    value={contactForm.name}
                    onChange={(e) => setContactForm((s) => ({ ...s, name: e.target.value }))}
                  />
                </div>
                <div className="field">
                  <label>Phone</label>
                  <input
                    required
                    placeholder="2557XXXXXXXX"
                    value={contactForm.phone}
                    onChange={(e) => setContactForm((s) => ({ ...s, phone: e.target.value }))}
                  />
                </div>
                <div className="field">
                  <label>Type</label>
                  <select
                    value={contactForm.type}
                    onChange={(e) =>
                      setContactForm((s) => ({
                        ...s,
                        type: e.target.value as 'customer' | 'staff',
                      }))
                    }
                  >
                    <option value="customer">Customer</option>
                    <option value="staff">Staff</option>
                  </select>
                </div>
                <div className="field">
                  <label>Tags</label>
                  <input
                    placeholder="vip, dar, warehouse"
                    value={contactForm.tags}
                    onChange={(e) => setContactForm((s) => ({ ...s, tags: e.target.value }))}
                  />
                </div>
                <button className="btn btn-primary" type="submit">
                  Save contact
                </button>
              </form>

              <div className="panel">
                <h3>Directory</h3>
                <div className="toolbar">
                  <input
                    placeholder="Search"
                    value={contactFilter}
                    onChange={(e) => setContactFilter(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') void refreshTab('contacts')
                    }}
                  />
                  <select
                    value={contactType}
                    onChange={(e) => {
                      setContactType(e.target.value)
                      setTimeout(() => void refreshTab('contacts'), 0)
                    }}
                  >
                    <option value="">All</option>
                    <option value="customer">Customers</option>
                    <option value="staff">Staff</option>
                  </select>
                  <button type="button" className="btn btn-ghost" onClick={() => void refreshTab('contacts')}>
                    Filter
                  </button>
                </div>
                <table className="table">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Phone</th>
                      <th>Type</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {contacts.map((c) => (
                      <tr key={c.id}>
                        <td>
                          {c.name}
                          {c.tags ? (
                            <div style={{ color: 'var(--muted)', fontSize: '0.75rem' }}>{c.tags}</div>
                          ) : null}
                        </td>
                        <td>{c.phone}</td>
                        <td>
                          <span className={`badge badge-${c.type}`}>{c.type}</span>
                        </td>
                        <td>
                          <button type="button" className="btn btn-danger" onClick={() => void removeContact(c.id)}>
                            Delete
                          </button>
                        </td>
                      </tr>
                    ))}
                    {contacts.length === 0 ? (
                      <tr>
                        <td colSpan={4} style={{ color: 'var(--muted)' }}>
                          No contacts yet.
                        </td>
                      </tr>
                    ) : null}
                  </tbody>
                </table>
              </div>
            </div>
          </>
        ) : null}

        {tab === 'broadcast' ? (
          <>
            <div className="page-head">
              <div>
                <h2>Broadcast</h2>
                <p>Send the same update to all customers, all staff, or everyone.</p>
              </div>
            </div>
            <form className="panel" onSubmit={createCampaign}>
              <h3>New broadcast</h3>
              <div className="grid-2">
                <div className="field">
                  <label>Title</label>
                  <input
                    required
                    value={campaignForm.title}
                    onChange={(e) => setCampaignForm((s) => ({ ...s, title: e.target.value }))}
                  />
                </div>
                <div className="field">
                  <label>Audience</label>
                  <select
                    value={campaignForm.audience}
                    onChange={(e) => setCampaignForm((s) => ({ ...s, audience: e.target.value }))}
                  >
                    <option value="customers">All customers</option>
                    <option value="staff">All staff</option>
                    <option value="all">Everyone</option>
                  </select>
                </div>
              </div>
              <div className="field">
                <label>Message</label>
                <textarea
                  rows={5}
                  required
                  value={campaignForm.body}
                  onChange={(e) => setCampaignForm((s) => ({ ...s, body: e.target.value }))}
                />
              </div>
              <button className="btn btn-primary" type="submit" style={{ width: 'auto' }}>
                Create broadcast
              </button>
            </form>
            <div className="panel">
              <h3>Campaigns</h3>
              <table className="table">
                <thead>
                  <tr>
                    <th>Title</th>
                    <th>Audience</th>
                    <th>Status</th>
                    <th>Results</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {campaigns.map((c) => (
                    <tr key={c.id}>
                      <td>
                        <strong>{c.title}</strong>
                        <div className="msg-body" style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>
                          {c.body.slice(0, 80)}
                          {c.body.length > 80 ? '' : ''}
                        </div>
                      </td>
                      <td>{c.audience}</td>
                      <td>{c.status}</td>
                      <td>
                        {c.total
                          ? `${c.sent_count} sent / ${c.failed_count} failed / ${c.total} total`
                          : ''}
                      </td>
                      <td>
                        {c.status === 'draft' ? (
                          <button type="button" className="btn btn-primary" style={{ width: 'auto' }} onClick={() => void sendCampaign(c.id)}>
                            Send now
                          </button>
                        ) : null}
                      </td>
                    </tr>
                  ))}
                  {campaigns.length === 0 ? (
                    <tr>
                      <td colSpan={5} style={{ color: 'var(--muted)' }}>
                        No broadcasts yet.
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          </>
        ) : null}

        {tab === 'history' ? (
          <>
            <div className="page-head">
              <div>
                <h2>History</h2>
                <p>Outbound and inbound WhatsApp messages.</p>
              </div>
              <button type="button" className="btn btn-ghost" onClick={() => void refreshTab('history')}>
                Refresh
              </button>
            </div>
            <div className="panel">
              <table className="table">
                <thead>
                  <tr>
                    <th>When</th>
                    <th>Dir</th>
                    <th>Phone</th>
                    <th>Matter</th>
                    <th>Message</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {messages.map((m) => (
                    <tr key={m.id}>
                      <td>{new Date(m.created_at * 1000).toLocaleString()}</td>
                      <td>{m.direction}</td>
                      <td>{m.phone}</td>
                      <td>{m.matter}</td>
                      <td className="msg-body">{m.body}</td>
                      <td>
                        <span className={`badge badge-${m.status === 'sent' ? 'sent' : m.status === 'failed' ? 'failed' : 'pending'}`}>
                          {m.status}
                        </span>
                        {m.error_message ? (
                          <div style={{ color: 'var(--danger)', fontSize: '0.75rem' }}>{m.error_message}</div>
                        ) : null}
                      </td>
                    </tr>
                  ))}
                  {messages.length === 0 ? (
                    <tr>
                      <td colSpan={6} style={{ color: 'var(--muted)' }}>
                        No messages yet.
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          </>
        ) : null}

        {tab === 'settings' ? (
          <>
            <div className="page-head">
              <div>
                <h2>Settings</h2>
                <p>Meta WhatsApp Cloud API credentials and auto-reply bot.</p>
              </div>
            </div>
            <form className="panel" onSubmit={saveSettings}>
              <h3>Cloud API</h3>
              <div className="grid-2">
                <div className="field">
                  <label>Phone number ID</label>
                  <input
                    value={settingsForm.phone_number_id}
                    onChange={(e) => setSettingsForm((s) => ({ ...s, phone_number_id: e.target.value }))}
                    required
                  />
                </div>
                <div className="field">
                  <label>Display phone</label>
                  <input
                    value={settingsForm.display_phone}
                    onChange={(e) => setSettingsForm((s) => ({ ...s, display_phone: e.target.value }))}
                    placeholder="+255"
                  />
                </div>
              </div>
              <div className="field">
                <label>Business account ID (optional)</label>
                <input
                  value={settingsForm.business_account_id}
                  onChange={(e) => setSettingsForm((s) => ({ ...s, business_account_id: e.target.value }))}
                />
              </div>
              <div className="field">
                <label>
                  Access token
                  {settings?.access_token_set ? ` (saved: ${settings.access_token_masked})` : ''}
                </label>
                <input
                  type="password"
                  placeholder={settings?.access_token_set ? 'Leave blank to keep current' : 'Paste permanent token'}
                  value={settingsForm.access_token}
                  onChange={(e) => setSettingsForm((s) => ({ ...s, access_token: e.target.value }))}
                />
              </div>
              <div className="field">
                <label>Webhook verify token</label>
                <input
                  value={settingsForm.webhook_verify_token}
                  onChange={(e) => setSettingsForm((s) => ({ ...s, webhook_verify_token: e.target.value }))}
                />
              </div>
              <div className="field">
                <label>Webhook URL (paste into Meta Developer Console)</label>
                <input readOnly value={webhookUrl} />
              </div>
              <h3 style={{ marginTop: '1.25rem' }}>Auto-reply bot</h3>
              <div className="field">
                <label>
                  <input
                    type="checkbox"
                    checked={settingsForm.auto_reply_enabled}
                    onChange={(e) =>
                      setSettingsForm((s) => ({ ...s, auto_reply_enabled: e.target.checked }))
                    }
                  />{' '}
                  Reply automatically to inbound messages
                </label>
              </div>
              <div className="field">
                <label>Auto-reply text</label>
                <textarea
                  rows={3}
                  value={settingsForm.auto_reply_text}
                  onChange={(e) => setSettingsForm((s) => ({ ...s, auto_reply_text: e.target.value }))}
                  placeholder="Thanks  our team will get back to you shortly."
                />
              </div>
              <button className="btn btn-primary" type="submit" style={{ width: 'auto' }}>
                Save settings
              </button>
            </form>
          </>
        ) : null}
      </main>
    </div>
  )
}
