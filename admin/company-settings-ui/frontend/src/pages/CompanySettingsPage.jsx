import { useEffect, useMemo, useRef, useState } from 'react'
import { Tabs } from '@animate-ui/react/primitives/radix-ui'
import {
  Accessibility,
  AlertTriangle,
  Bot,
  Briefcase,
  Building2,
  ChevronLeft,
  Clock3,
  Copy,
  Check,
  ExternalLink,
  FileSpreadsheet,
  FolderTree,
  Globe,
  Hash,
  LayoutGrid,
  Mail,
  MessageCircle,
  Network,
  Palette,
  PlusSquare,
  Receipt,
  RefreshCw,
  Search,
  Shield,
  ShoppingCart,
  Trash2,
  Type,
  UserPlus,
  Users,
} from 'lucide-react'
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

const NAV_META = {
  profile: {
    icon: Building2,
    title: 'Profile',
    subtitle: 'Name, contact, location, and company identity.',
    keywords: 'profile company name email phone website address location country timezone currency database',
  },
  departments: {
    icon: FolderTree,
    title: 'Departments',
    subtitle: 'Teams used when inviting and assigning employees.',
    keywords: 'departments warehouse fleet hr team org',
  },
  business_field: {
    icon: Briefcase,
    title: 'Company field',
    subtitle: 'Line of business for welcome-email characters.',
    keywords: 'company field business trading spares trucks ppe food logistics courier technology',
  },
  system_font: {
    icon: Type,
    title: 'System font',
    subtitle: 'Applies across modules, sidebars, forms, and dashboards.',
    keywords: 'system font typography reading style poppins dm sans',
  },
  accessibility: {
    icon: Accessibility,
    title: 'Accessibility',
    subtitle: 'Text size, contrast, motion, tap targets, and delivery-note QR.',
    keywords: 'accessibility contrast motion tap targets qr delivery note',
  },
  branding: {
    icon: Palette,
    title: 'Branding',
    subtitle: 'Logo and primary color for documents.',
    keywords: 'branding logo color appearance documents',
  },
  finance: {
    icon: Receipt,
    title: 'Tax & Finance',
    subtitle: 'VAT, TIN, bank details, and voucher workflow.',
    keywords: 'finance tax vat tin bank footer approval workflow currency',
  },
  modules: {
    icon: LayoutGrid,
    title: 'Modules',
    subtitle: 'Enable modules available for this company.',
    keywords: 'modules enable disable sales stock payroll attendance',
  },
  numbering: {
    icon: Hash,
    title: 'Numbering',
    subtitle: 'Document prefixes and next numbers.',
    keywords: 'numbering prefix suffix invoice voucher purchase order',
  },
  employees: {
    icon: Users,
    title: 'People',
    subtitle: 'Admins, employees, and registration rules.',
    keywords: 'employees register admin invite registration users',
  },
  register_employee: {
    icon: UserPlus,
    title: 'Register employee',
    subtitle: 'Add team members with roles and departments.',
    keywords: 'register employee admin invite',
  },
  permissions: {
    icon: Users,
    title: 'Permissions',
    subtitle: 'Who can use features such as website quote requests.',
    keywords: 'permissions quote access users roles',
  },
  security: {
    icon: Shield,
    title: 'Security',
    subtitle: 'Access controls for this company.',
    keywords: 'security password access login',
  },
  time: {
    icon: Clock3,
    title: 'Timezone & format',
    subtitle: 'System timezone, display format, and manual time override.',
    keywords: 'time attendance timezone format override',
  },
  time_format: {
    icon: Clock3,
    title: 'Timezone & format',
    subtitle: 'System timezone, display format, and manual time override.',
    keywords: 'time attendance timezone format override',
  },
  time_hours: {
    icon: Clock3,
    title: 'Working hours',
    subtitle: 'Work start/end times and late grace period.',
    keywords: 'working hours start end grace attendance',
  },
  time_network: {
    icon: Network,
    title: 'Network & location',
    subtitle: 'Office network IPs, geofencing, and biometric clock-in.',
    keywords: 'network location geofence ip biometric office',
  },
  sales: {
    icon: ShoppingCart,
    title: 'Sales settings',
    subtitle: 'Quotations, catalog display, and company logo for sales docs.',
    keywords: 'sales quotations catalog',
  },
  stock_purchase: {
    icon: FileSpreadsheet,
    title: 'Stock purchase & vouchers',
    subtitle: 'PO type, stock purchase vouchers, and finance approval.',
    keywords: 'stock purchase vouchers po',
  },
  whatsapp: {
    icon: MessageCircle,
    title: 'WhatsApp',
    subtitle: 'Messaging bot for customers/staff, plus voucher group link.',
    keywords: 'whatsapp messaging bot',
  },
  email: {
    icon: Mail,
    title: 'Email SMTP settings',
    subtitle: 'SMTP/IMAP servers and the system mailbox.',
    keywords: 'email smtp imap mailbox',
  },
  register_company: {
    icon: PlusSquare,
    title: 'Register new company',
    subtitle: 'Create a company tenant with default module setup.',
    keywords: 'register company tenant subdomain',
  },
  company_management: {
    icon: Network,
    title: 'Company management',
    subtitle: 'Monitor companies and switch active company context.',
    keywords: 'company management tenants',
  },
  company_users: {
    icon: Users,
    title: 'Company users & emails',
    subtitle: 'All tenant users and login emails per company.',
    keywords: 'company users emails login index',
  },
  sync_login: {
    icon: RefreshCw,
    title: 'Sync login index',
    subtitle: 'Rebuild user_company_index from tenant databases.',
    keywords: 'sync login index',
  },
  site_contact: {
    icon: Globe,
    title: 'Website contact page',
    subtitle: 'Contact details on the public Contact page and site footer.',
    keywords: 'website contact phone email instagram',
  },
  ai: {
    icon: Bot,
    title: 'AI Integration',
    subtitle: 'System-wide OpenAI key, usage limits, and connection test.',
    keywords: 'ai openai integration',
  },
  danger: {
    icon: AlertTriangle,
    title: 'Lifecycle',
    subtitle: 'Status and setup controls for this company.',
    keywords: 'danger status setup suspend activate lifecycle',
  },
  factory_reset: {
    icon: Trash2,
    title: 'Factory Reset',
    subtitle: 'Wipe operational transactions, stock, attendance, and uploads.',
    keywords: 'factory reset wipe danger irreversible',
  },
}

const ITEM_ICONS = {
  building: Building2,
  'folder-tree': FolderTree,
  briefcase: Briefcase,
  palette: Palette,
  receipt: Receipt,
  modules: LayoutGrid,
  hash: Hash,
  shield: Shield,
  type: Type,
  accessibility: Accessibility,
  users: Users,
  'user-plus': UserPlus,
  clock: Clock3,
  cart: ShoppingCart,
  invoice: FileSpreadsheet,
  whatsapp: MessageCircle,
  mail: Mail,
  'plus-square': PlusSquare,
  sitemap: Network,
  sync: RefreshCw,
  globe: Globe,
  robot: Bot,
  alert: AlertTriangle,
  trash: Trash2,
}

const A11Y_DEFAULTS = {
  textScale: '100',
  highContrast: false,
  reduceMotion: false,
  underlineLinks: false,
  largeTargets: false,
  deliveryNoteQr: true,
}

function loadGoogleFont(google) {
  if (!google || typeof document === 'undefined') return
  const href = /^https?:\/\//i.test(String(google))
    ? String(google)
    : `https://fonts.googleapis.com/css2?family=${encodeURIComponent(String(google))}:wght@400;500;600;700&display=swap`
  const id = `cs-google-font-${href.replace(/[^a-zA-Z0-9]+/g, '-').slice(0, 120)}`
  if (document.getElementById(id)) return
  const link = document.createElement('link')
  link.id = id
  link.rel = 'stylesheet'
  link.href = href
  document.head.appendChild(link)
}

function applyA11yToDocument(prefs) {
  if (typeof document === 'undefined') return
  const next = { ...A11Y_DEFAULTS, ...(prefs || {}) }
  const root = document.documentElement
  root.setAttribute('data-a11y-text', String(next.textScale || '100'))
  root.setAttribute('data-a11y-contrast', next.highContrast ? '1' : '0')
  root.setAttribute('data-a11y-motion', next.reduceMotion ? '1' : '0')
  root.setAttribute('data-a11y-underline', next.underlineLinks ? '1' : '0')
  root.setAttribute('data-a11y-tap', next.largeTargets ? '1' : '0')
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

function EmbedPanel({ title, subtitle, icon: Icon, accent, src, frameClassName = '' }) {
  const [frameReady, setFrameReady] = useState(false)
  const [frameHeight, setFrameHeight] = useState(null)
  const iframeRef = useRef(null)
  const isSection = String(frameClassName || '').includes('cs-embed-frame--section')

  useEffect(() => {
    setFrameReady(false)
    setFrameHeight(null)
  }, [src])

  useEffect(() => {
    function onMessage(event) {
      const data = event?.data
      if (!data || data.type !== 'erp-embed-resize') return
      if (iframeRef.current && event.source && event.source !== iframeRef.current.contentWindow) return
      const next = Math.ceil(Number(data.height) || 0)
      if (next < 120) return
      setFrameHeight(next)
    }
    window.addEventListener('message', onMessage)
    return () => window.removeEventListener('message', onMessage)
  }, [])

  if (!src) {
    return (
      <Card className="cs-card--compact cs-card--profile" icon={Icon} accent={accent} title={title} subtitle={subtitle}>
        <p className="cs-help">This setting is unavailable.</p>
      </Card>
    )
  }

  const sizedStyle = frameHeight
    ? { height: `${frameHeight}px`, minHeight: `${frameHeight}px` }
    : undefined

  return (
    <Card className="cs-card--compact cs-card--profile cs-card--embed" icon={Icon} accent={accent} title={title} subtitle={subtitle}>
      <div
        className={`cs-embed-wrap${frameReady ? ' is-ready' : ''}${frameHeight ? ' is-sized' : ''}`}
        style={isSection ? sizedStyle : undefined}
      >
        {!frameReady ? (
          <div className="cs-embed-skeleton" aria-busy="true" aria-live="polite" style={isSection ? sizedStyle : undefined}>
            <span className="sr-only">Loading {title}...</span>
            <span className="cs-skel-bar cs-skel-bar--label" />
            <span className="cs-skel-field" />
            <span className="cs-skel-bar cs-skel-bar--label" />
            <span className="cs-skel-field" />
            <span className="cs-skel-bar cs-skel-bar--label" />
            <span className="cs-skel-field" />
            <div className="cs-skel-footer">
              <span className="cs-skel-btn" />
            </div>
          </div>
        ) : null}
        <iframe
          ref={iframeRef}
          className={`cs-embed-frame${frameClassName ? ` ${frameClassName}` : ''}${frameReady ? '' : ' is-loading'}${frameHeight ? ' is-sized' : ''}`}
          title={title}
          src={src}
          loading="lazy"
          referrerPolicy="same-origin"
          style={isSection ? sizedStyle : undefined}
          onLoad={() => setFrameReady(true)}
        />
      </div>
    </Card>
  )
}

function Card({ title, subtitle, icon: Icon, accent, children, className = '' }) {
  return (
    <section className={`cs-card${className ? ` ${className}` : ''}`}>
      <header className="cs-card-head">
        {Icon ? (
          <span className="cs-sec-icon" style={accent ? { '--cs-accent': accent } : undefined} aria-hidden="true">
            <Icon strokeWidth={1.75} />
          </span>
        ) : null}
        <div>
          <h2>{title}</h2>
          {subtitle ? <p>{subtitle}</p> : null}
        </div>
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

function readInitialTab(cfg) {
  const allowed = new Set((cfg.tabs || []).map((t) => t.id))
  const hash = String(window.location.hash || '').replace(/^#/, '')
  if (hash && allowed.has(hash)) return hash
  const fromCfg = cfg.activeTab || 'profile'
  return allowed.has(fromCfg) ? fromCfg : 'profile'
}

export default function CompanySettingsPage() {
  const cfg = useMemo(() => getCfg(), [])
  const company = cfg.company || {}
  const settings = cfg.settings || {}
  const tabs = cfg.tabs || []
  const [activeTab, setActiveTab] = useState(() => readInitialTab(cfg))
  const [view, setView] = useState(() => {
    const mobile = window.matchMedia('(max-width: 768px)').matches
    if (!mobile) return 'detail'
    const hash = String(window.location.hash || '').replace(/^#/, '')
    const tabParam = new URLSearchParams(window.location.search).get('tab')
    return hash || (tabParam && tabParam !== 'profile') ? 'detail' : 'menu'
  })
  const [navFilter, setNavFilter] = useState('')
  const [primaryColor, setPrimaryColor] = useState(settings.primary_color || '#2563eb')
  const [logoPreview, setLogoPreview] = useState(cfg.logoUrl || '')
  const [businessFieldSaved, setBusinessFieldSaved] = useState(cfg.businessField?.current || '')
  const [businessFieldPick, setBusinessFieldPick] = useState(cfg.businessField?.current || '')
  const [businessFieldBusy, setBusinessFieldBusy] = useState(false)
  const [businessFieldMessage, setBusinessFieldMessage] = useState('')
  const [businessFieldError, setBusinessFieldError] = useState('')
  const businessFieldOptions = cfg.businessField?.options || []
  const fontCatalog = cfg.font?.catalog || []
  const [fontKey, setFontKey] = useState(cfg.font?.current || 'dm_sans')
  const [fontBusy, setFontBusy] = useState(false)
  const [fontMessage, setFontMessage] = useState('')
  const [fontError, setFontError] = useState('')
  const selectedFont = fontCatalog.find((f) => f.id === fontKey) || {
    id: fontKey,
    label: cfg.font?.label || 'Poppins',
    stack: cfg.font?.stack || "'Poppins', sans-serif",
    google: '',
  }
  const [a11yForm, setA11yForm] = useState(() => ({ ...A11Y_DEFAULTS, ...(cfg.accessibility || {}) }))
  const [a11yBusy, setA11yBusy] = useState(false)
  const [a11yMessage, setA11yMessage] = useState('')
  const [a11yError, setA11yError] = useState('')
  const [quoteUsers] = useState(() => cfg.webQuoteAccess?.users || [])
  const [quoteSelected, setQuoteSelected] = useState(
    () => new Set((cfg.webQuoteAccess?.selectedIds || []).map((id) => Number(id))),
  )
  const [quoteFilter, setQuoteFilter] = useState('')
  const [quoteBusy, setQuoteBusy] = useState(false)
  const [quoteMessage, setQuoteMessage] = useState('')
  const [quoteError, setQuoteError] = useState('')
  const [resetConfirm, setResetConfirm] = useState('')
  const [resetBusy, setResetBusy] = useState(false)
  const [resetMessage, setResetMessage] = useState('')
  const [resetError, setResetError] = useState('')
  const [contactForm, setContactForm] = useState(() => ({ ...(cfg.siteContact || {}) }))
  const [contactBusy, setContactBusy] = useState(false)
  const [contactMessage, setContactMessage] = useState('')
  const [contactError, setContactError] = useState('')
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
  const hubUrl = cfg.hubUrl || '../select-module.php'
  const initial = String(company.company_name || 'C').trim().charAt(0).toUpperCase() || 'C'
  const railGroups = cfg.railGroups || []
  const activeRailItem = useMemo(() => {
    for (const group of railGroups) {
      const found = (group.items || []).find((item) => item.id === activeTab)
      if (found) return found
    }
    return null
  }, [railGroups, activeTab])
  const activeMeta = {
    ...(NAV_META[activeTab] || NAV_META.profile),
    ...(activeRailItem
      ? {
          title: activeRailItem.title || NAV_META[activeTab]?.title,
          subtitle: activeRailItem.subtitle || NAV_META[activeTab]?.subtitle,
        }
      : {}),
  }
  const activeSectionEmbeds = Array.isArray(activeRailItem?.sectionEmbeds)
    ? activeRailItem.sectionEmbeds.filter((item) => item?.embedUrl)
    : []
  const activeEmbedUrl = activeSectionEmbeds.length > 0 ? '' : (activeRailItem?.embedUrl || '')

  const businessFieldPreviewHref = () => {
    const option = businessFieldOptions.find((o) => o.id === businessFieldPick)
    const base = cfg.businessField?.previewUrl || ''
    if (!base || !option) return base
    return `${base}?character=${encodeURIComponent(option.character)}`
  }

  const onSaveBusinessField = async (e) => {
    e.preventDefault()
    if (!businessFieldPick) return
    setBusinessFieldBusy(true)
    setBusinessFieldMessage('')
    setBusinessFieldError('')
    try {
      const res = await fetch(cfg.businessField?.saveUrl || 'settings-ui/api/save-business-field.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ business_field: businessFieldPick }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok || !data.ok) {
        throw new Error(data.message || 'Could not save the company field.')
      }
      setBusinessFieldSaved(data.current || businessFieldPick)
      setBusinessFieldMessage(data.message || 'Company field updated.')
    } catch (err) {
      setBusinessFieldError(err.message || 'Could not save the company field.')
    } finally {
      setBusinessFieldBusy(false)
    }
  }

  const filteredGroups = useMemo(() => {
    const q = navFilter.trim().toLowerCase()
    return railGroups
      .map((group) => {
        const items = (group.items || []).filter((item) => {
          if (!q) return true
          return [group.title, item.title, item.subtitle, item.id]
            .filter(Boolean)
            .some((part) => String(part).toLowerCase().includes(q))
        })
        return { ...group, items }
      })
      .filter((group) => group.items.length > 0)
  }, [railGroups, navFilter])

  const switchTab = (tab, { openDetail = true } = {}) => {
    setActiveTab(tab)
    const url = new URL(window.location.href)
    url.searchParams.set('tab', tab)
    url.searchParams.delete('step')
    url.hash = tab
    window.history.replaceState({}, '', url.toString())
    if (openDetail && window.matchMedia('(max-width: 768px)').matches) {
      setView('detail')
    }
  }

  useEffect(() => {
    if (selectedFont.google) loadGoogleFont(selectedFont.google)
  }, [selectedFont.google, selectedFont.id])

  useEffect(() => {
    const onHash = () => {
      const hash = String(window.location.hash || '').replace(/^#/, '')
      if (!hash) return
      if ((cfg.tabs || []).some((t) => t.id === hash)) {
        setActiveTab(hash)
        if (window.matchMedia('(max-width: 768px)').matches) setView('detail')
      }
    }
    window.addEventListener('hashchange', onHash)
    return () => window.removeEventListener('hashchange', onHash)
  }, [cfg.tabs])

  useEffect(() => {
    const mq = window.matchMedia('(max-width: 768px)')
    const onChange = () => {
      if (!mq.matches) setView('detail')
    }
    mq.addEventListener('change', onChange)
    return () => mq.removeEventListener('change', onChange)
  }, [])

  const onSaveFont = async (e) => {
    e.preventDefault()
    setFontBusy(true)
    setFontMessage('')
    setFontError('')
    try {
      const res = await fetch(cfg.font?.saveUrl || 'settings-ui/api/save-font.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ system_font: fontKey }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok || !data.ok) throw new Error(data.message || 'Could not save font.')
      if (data.font?.stack) {
        document.documentElement.style.setProperty('--erp-font-family', data.font.stack)
      }
      setFontMessage(data.message || 'System font updated.')
    } catch (err) {
      setFontError(err.message || 'Could not save font.')
    } finally {
      setFontBusy(false)
    }
  }

  const onSaveAccessibility = async (e) => {
    e.preventDefault()
    setA11yBusy(true)
    setA11yMessage('')
    setA11yError('')
    try {
      const res = await fetch(cfg.accessibilitySaveUrl || 'settings-ui/api/save-accessibility.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(a11yForm),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok || !data.ok) throw new Error(data.message || 'Could not save accessibility settings.')
      const saved = { ...A11Y_DEFAULTS, ...(data.accessibility || a11yForm) }
      setA11yForm(saved)
      applyA11yToDocument(saved)
      setA11yMessage(data.message || 'Accessibility settings updated.')
    } catch (err) {
      setA11yError(err.message || 'Could not save accessibility settings.')
    } finally {
      setA11yBusy(false)
    }
  }

  const toggleQuoteUser = (id) => {
    const next = Number(id)
    setQuoteSelected((prev) => {
      const copy = new Set(prev)
      if (copy.has(next)) copy.delete(next)
      else copy.add(next)
      return copy
    })
  }

  const onSavePermissions = async (e) => {
    e.preventDefault()
    setQuoteBusy(true)
    setQuoteMessage('')
    setQuoteError('')
    try {
      const res = await fetch(cfg.webQuoteAccessSaveUrl || 'settings-ui/api/save-web-quote-access.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ user_ids: Array.from(quoteSelected) }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok || !data.ok) throw new Error(data.message || 'Could not save permissions.')
      setQuoteMessage(data.message || 'Permissions updated.')
    } catch (err) {
      setQuoteError(err.message || 'Could not save permissions.')
    } finally {
      setQuoteBusy(false)
    }
  }

  const onFactoryReset = async (e) => {
    e.preventDefault()
    setResetBusy(true)
    setResetMessage('')
    setResetError('')
    try {
      const res = await fetch(cfg.factoryResetUrl || 'settings-ui/api/factory-reset.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ confirm_reset: resetConfirm }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok || !data.ok) throw new Error(data.message || 'Factory reset failed.')
      setResetMessage(data.message || 'Factory reset completed.')
      setResetConfirm('')
    } catch (err) {
      setResetError(err.message || 'Factory reset failed.')
    } finally {
      setResetBusy(false)
    }
  }

  const filteredQuoteUsers = quoteUsers.filter((user) => {
    const q = quoteFilter.trim().toLowerCase()
    if (!q) return true
    return [user.name, user.email, user.role]
      .filter(Boolean)
      .some((part) => String(part).toLowerCase().includes(q))
  })

  const onSaveSiteContact = async (e) => {
    e.preventDefault()
    setContactBusy(true)
    setContactMessage('')
    setContactError('')
    try {
      const res = await fetch(cfg.siteContactSaveUrl || 'settings-ui/api/save-site-contact.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(contactForm),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok || !data.ok) throw new Error(data.message || 'Could not save contact details.')
      if (data.contact && typeof data.contact === 'object') {
        setContactForm((prev) => ({ ...prev, ...data.contact }))
      }
      setContactMessage(data.message || 'Website contact details saved.')
    } catch (err) {
      setContactError(err.message || 'Could not save contact details.')
    } finally {
      setContactBusy(false)
    }
  }

  const renderNav = (mobileList = false) => (
    <Tabs.List
      className={`cs-rail-nav${mobileList ? ' cs-rail-nav--mobile' : ''}`}
      aria-label="Company settings"
    >
      {filteredGroups.map((group) => (
        <div key={group.id} className="cs-rail-group" role="presentation">
          <p className={`cs-rail-section-label${group.id === 'danger' ? ' is-danger' : ''}`}>{group.title}</p>
          {group.items.map((item) => {
            const Icon = ITEM_ICONS[item.icon] || Building2
            const className = `cs-nav-link${item.danger ? ' is-danger' : ''}`
            return (
              <Tabs.Trigger key={item.id} value={item.id} className={className}>
                <span className="cs-nav-icon" aria-hidden="true">
                  <Icon strokeWidth={1.6} />
                </span>
                <span className="cs-nav-text">
                  <strong>
                    {item.title}
                    {item.badge ? <span className="cs-nav-badge">{item.badge}</span> : null}
                  </strong>
                  {mobileList && item.subtitle ? <em>{item.subtitle}</em> : null}
                </span>
              </Tabs.Trigger>
            )
          })}
        </div>
      ))}
      {filteredGroups.length === 0 ? <p className="cs-help cs-nav-empty">No matching settings.</p> : null}
    </Tabs.List>
  )

  return (
    <Tabs.Root
      className="cs-shell"
      data-cs-view={view}
      value={activeTab}
      orientation="vertical"
      onValueChange={(next) => switchTab(next)}
    >
      <aside className="cs-rail">
        <a className="cs-rail-back" href={hubUrl}>
          <ChevronLeft strokeWidth={2} aria-hidden="true" />
          <span>Modules</span>
        </a>

        <div className="cs-rail-hero">
          <div className="cs-rail-avatar" aria-hidden="true">
            {logoPreview ? <img src={logoPreview} alt="" /> : <span>{initial}</span>}
          </div>
          <div className="cs-rail-copy">
            <h1>{company.company_name || 'Company'}</h1>
            <p>{cfg.setupStatusLabel || company.setup_status || 'active'}</p>
          </div>
        </div>

        <div className="cs-rail-search">
          <label className="cs-search" htmlFor="csHeadSearch">
            <Search size={14} aria-hidden="true" />
            <input
              id="csHeadSearch"
              type="search"
              placeholder="Find a setting..."
              value={navFilter}
              onChange={(e) => setNavFilter(e.target.value)}
              autoComplete="off"
            />
          </label>
        </div>

        {renderNav(false)}
      </aside>

      <div className="cs-mobile-menu">
        <div className="cs-mobile-hero">
          <div className="cs-rail-avatar" aria-hidden="true">
            {logoPreview ? <img src={logoPreview} alt="" /> : <span>{initial}</span>}
          </div>
          <div className="cs-rail-copy">
            <h1>{company.company_name || 'Company'}</h1>
            <p>Company settings</p>
          </div>
        </div>
        <div className="cs-rail-search">
          <label className="cs-search" htmlFor="csMobileSearch">
            <Search size={14} aria-hidden="true" />
            <input
              id="csMobileSearch"
              type="search"
              placeholder="Find a setting..."
              value={navFilter}
              onChange={(e) => setNavFilter(e.target.value)}
              autoComplete="off"
            />
          </label>
        </div>
        {renderNav(true)}
        <a className="cs-mobile-hub" href={hubUrl}>
          Back to modules
        </a>
      </div>

      <div className="cs-mobile-back">
        <button type="button" className="cs-mobile-back-btn" onClick={() => setView('menu')} aria-label="Back to settings">
          <ChevronLeft strokeWidth={2} aria-hidden="true" />
        </button>
        <span>{activeMeta.title}</span>
      </div>

      <div className="cs-body">
        {cfg.message ? <div className="cs-alert cs-alert--ok">{cfg.message}</div> : null}
        {cfg.error ? <div className="cs-alert cs-alert--err">{cfg.error}</div> : null}
        {isPending ? (
          <div className="cs-alert cs-alert--info">
            Setup is pending. Complete each section, then activate the company from Danger zone.
          </div>
        ) : null}

        {activeTab === 'profile' ? (
          <>
            <Card
              className="cs-card--compact cs-card--profile"
              icon={Building2}
              accent="#2563eb"
              title="Profile"
              subtitle="Core company details used across your workspace."
            >
              <form method="post" action={actionUrl} encType="multipart/form-data">
                <input type="hidden" name="save_profile" value="1" />
                <div className="cs-profile-grid">
                  <label className="cs-stack-field">
                    <span>Company name</span>
                    <input className="cs-input" name="company_name" defaultValue={company.company_name || ''} />
                  </label>
                  <label className="cs-stack-field">
                    <span>Legal name</span>
                    <input className="cs-input" name="legal_name" defaultValue={company.legal_name || ''} />
                  </label>
                  <label className="cs-stack-field">
                    <span>Email</span>
                    <input className="cs-input" type="email" name="email" defaultValue={company.email || ''} />
                  </label>
                  <label className="cs-stack-field">
                    <span>Phone</span>
                    <input className="cs-input" name="phone" defaultValue={company.phone || ''} />
                  </label>
                  <label className="cs-stack-field cs-stack-field--wide">
                    <span>Company website</span>
                    <input
                      className="cs-input"
                      type="url"
                      name="company_website"
                      defaultValue={settings.company_website || company.domain || ''}
                      placeholder="https://www.example.com"
                      autoComplete="url"
                    />
                    <em className="cs-help">Shown on delivery notes and the public feedback page.</em>
                  </label>
                  <label className="cs-stack-field">
                    <span>Address</span>
                    <input className="cs-input" name="address" defaultValue={company.address || ''} />
                  </label>
                  <label className="cs-stack-field">
                    <span>Location</span>
                    <input
                      className="cs-input"
                      name="company_location"
                      defaultValue={settings.company_location || ''}
                      placeholder="e.g. Dar es Salaam, Tanzania"
                    />
                  </label>
                  <label className="cs-stack-field">
                    <span>Country</span>
                    <select className="cs-input" name="country" defaultValue={company.country || 'Tanzania'}>
                      {(cfg.countryOptions || []).map((c) => (
                        <option key={c} value={c}>
                          {c}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="cs-stack-field">
                    <span>Timezone</span>
                    <select className="cs-input" name="timezone" defaultValue={company.timezone || 'Africa/Dar_es_Salaam'}>
                      {(cfg.timezoneOptions || []).map((c) => (
                        <option key={c} value={c}>
                          {c}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="cs-stack-field">
                    <span>Base currency</span>
                    <select className="cs-input" name="base_currency" defaultValue={company.base_currency || 'TZS'}>
                      {(cfg.currencyOptions || []).map((c) => (
                        <option key={c} value={c}>
                          {c}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="cs-stack-field">
                    <span>Database name</span>
                    {isSuperAdmin ? (
                      <input className="cs-input" name="db_name" defaultValue={company.db_name || ''} />
                    ) : (
                      <input className="cs-input" defaultValue={company.db_name || ''} readOnly />
                    )}
                  </label>
                </div>
                <div className="cs-actions">
                  <button type="submit" className="cs-btn-primary" style={{ borderRadius: 9999 }}>
                    Save changes
                  </button>
                </div>
              </form>
            </Card>
            <Card
              className="cs-card--compact cs-card--access"
              icon={ExternalLink}
              accent="#0f766e"
              title="Company access link"
              subtitle="Share with your team to open the company portal."
            >
              <CopyLink value={cfg.companyAccessLink || ''} />
            </Card>
          </>
        ) : null}

        {activeTab === 'business_field' ? (
          <Card
            className="cs-card--compact cs-card--field"
            icon={Briefcase}
            accent="#7c3aed"
            title="Company field"
            subtitle="Pick your line of business. New employee welcome emails will use the matching character."
          >
            {businessFieldMessage ? <div className="cs-alert cs-alert--ok">{businessFieldMessage}</div> : null}
            {businessFieldError ? <div className="cs-alert cs-alert--err">{businessFieldError}</div> : null}
            <form onSubmit={onSaveBusinessField}>
              <div className="cs-field-grid" role="radiogroup" aria-label="Company field">
                {businessFieldOptions.map((option) => {
                  const active = option.id === businessFieldPick
                  return (
                    <button
                      key={option.id}
                      type="button"
                      role="radio"
                      aria-checked={active}
                      className={`cs-field-tile${active ? ' is-active' : ''}`}
                      onClick={() => setBusinessFieldPick(option.id)}
                    >
                      {option.id === businessFieldSaved ? <span className="cs-field-badge">Current</span> : null}
                      {active ? (
                        <span className="cs-field-check" aria-hidden="true">
                          <Check size={12} strokeWidth={3} />
                        </span>
                      ) : null}
                      <span className="cs-field-art">
                        {option.image ? <img src={option.image} alt="" loading="lazy" /> : null}
                      </span>
                      <span className="cs-field-label">{option.label}</span>
                      <span className="cs-field-desc">{option.description}</span>
                    </button>
                  )
                })}
              </div>
              <div className="cs-field-actions">
                {businessFieldPreviewHref() ? (
                  <a
                    className="cs-field-preview"
                    href={businessFieldPreviewHref()}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    Preview welcome email
                  </a>
                ) : null}
                <button
                  type="submit"
                  className="cs-btn-primary"
                  style={{ borderRadius: 9999 }}
                  disabled={businessFieldBusy || !businessFieldPick}
                >
                  {businessFieldBusy ? 'Saving…' : 'Save field'}
                </button>
              </div>
            </form>
          </Card>
        ) : null}

        {activeTab === 'system_font' ? (
          <Card
            className="cs-card--compact"
            icon={Type}
            accent="#4f46e5"
            title="System font"
            subtitle="Applies across modules, sidebars, forms, and dashboards for this company."
          >
            {fontMessage ? <div className="cs-alert cs-alert--ok">{fontMessage}</div> : null}
            {fontError ? <div className="cs-alert cs-alert--err">{fontError}</div> : null}
            <form onSubmit={onSaveFont}>
              <div className="cs-font-preview" style={{ fontFamily: selectedFont.stack }}>
                <p>
                  While resolving a conflict with a colleague, remember to keep a positive attitude and focus on
                  finding a solution that works for everyone.
                </p>
              </div>
              <div className="cs-font-pick-grid" role="listbox" aria-label="Font style">
                {fontCatalog.map((font) => {
                  const selected = font.id === fontKey
                  return (
                    <button
                      key={font.id}
                      type="button"
                      role="option"
                      aria-selected={selected}
                      className={`cs-font-pick${selected ? ' is-selected' : ''}`}
                      style={{ '--cs-font': font.stack }}
                      onClick={() => {
                        if (font.google) loadGoogleFont(font.google)
                        setFontKey(font.id)
                      }}
                    >
                      <span className="cs-font-pick-sample">{font.label}</span>
                      {selected ? <Check className="cs-font-pick-check" strokeWidth={2.25} aria-hidden="true" /> : null}
                    </button>
                  )
                })}
              </div>
              <p className="cs-help" style={{ marginTop: '0.7rem' }}>
                Current: {selectedFont.label}
              </p>
              <div className="cs-actions">
                <button type="submit" className="cs-btn-primary" style={{ borderRadius: 9999 }} disabled={fontBusy}>
                  {fontBusy ? 'Saving…' : 'Apply font'}
                </button>
              </div>
            </form>
          </Card>
        ) : null}

        {activeTab === 'accessibility' ? (
          <Card
            className="cs-card--compact"
            icon={Accessibility}
            accent="#0d9488"
            title="Accessibility"
            subtitle="Text size, contrast, motion, tap targets, and delivery-note QR visibility."
          >
            {a11yMessage ? <div className="cs-alert cs-alert--ok">{a11yMessage}</div> : null}
            {a11yError ? <div className="cs-alert cs-alert--err">{a11yError}</div> : null}
            <form onSubmit={onSaveAccessibility}>
              <label className="cs-stack-field" style={{ marginBottom: '0.75rem' }}>
                <span>Text size</span>
                <select
                  className="cs-input"
                  value={a11yForm.textScale}
                  onChange={(e) => setA11yForm((prev) => ({ ...prev, textScale: e.target.value }))}
                >
                  <option value="100">Default (100%)</option>
                  <option value="112">Comfortable (112%)</option>
                  <option value="125">Large (125%)</option>
                  <option value="150">Extra large (150%)</option>
                </select>
              </label>
              <div className="cs-a11y-toggles">
                {[
                  { key: 'highContrast', label: 'High contrast', hint: 'Stronger borders and clearer text' },
                  { key: 'reduceMotion', label: 'Reduce motion', hint: 'Turn off animations and transitions' },
                  { key: 'underlineLinks', label: 'Underline links', hint: 'Mark links by more than colour alone' },
                  { key: 'largeTargets', label: 'Larger tap targets', hint: 'Bigger buttons and controls' },
                ].map((row) => (
                  <label key={row.key} className="cs-a11y-toggle">
                    <span>
                      <strong>{row.label}</strong>
                      <em>{row.hint}</em>
                    </span>
                    <input
                      type="checkbox"
                      checked={Boolean(a11yForm[row.key])}
                      onChange={(e) => setA11yForm((prev) => ({ ...prev, [row.key]: e.target.checked }))}
                    />
                  </label>
                ))}
                <label className="cs-a11y-toggle">
                  <span>
                    <strong>Delivery note QR</strong>
                    <em>
                      {a11yForm.deliveryNoteQr
                        ? 'QR code is shown on printed delivery notes'
                        : 'QR code is hidden on printed delivery notes'}
                    </em>
                  </span>
                  <input
                    type="checkbox"
                    checked={Boolean(a11yForm.deliveryNoteQr)}
                    onChange={(e) => setA11yForm((prev) => ({ ...prev, deliveryNoteQr: e.target.checked }))}
                  />
                </label>
              </div>
              <div className="cs-actions">
                <button type="submit" className="cs-btn-primary" style={{ borderRadius: 9999 }} disabled={a11yBusy}>
                  {a11yBusy ? 'Saving…' : 'Save'}
                </button>
              </div>
            </form>
          </Card>
        ) : null}

        {activeTab === 'permissions' ? (
          <Card
            className="cs-card--compact"
            icon={Users}
            accent="#0f766e"
            title="Permissions"
            subtitle="Control who can use features such as website quote requests."
          >
            {quoteMessage ? <div className="cs-alert cs-alert--ok">{quoteMessage}</div> : null}
            {quoteError ? <div className="cs-alert cs-alert--err">{quoteError}</div> : null}
            <form onSubmit={onSavePermissions}>
              <label className="cs-stack-field" style={{ marginBottom: '0.65rem' }}>
                <span>Find a user</span>
                <input
                  className="cs-input"
                  type="search"
                  value={quoteFilter}
                  onChange={(e) => setQuoteFilter(e.target.value)}
                  placeholder="Name, email, or role"
                />
              </label>
              <div className="cs-quote-list" role="group" aria-label="Users with web quote access">
                {filteredQuoteUsers.map((user) => {
                  const id = Number(user.id)
                  const checked = quoteSelected.has(id)
                  const locked = Boolean(user.locked)
                  return (
                    <label key={id} className={`cs-quote-row${checked ? ' is-checked' : ''}${locked ? ' is-locked' : ''}`}>
                      <input
                        type="checkbox"
                        checked={checked}
                        disabled={locked}
                        onChange={() => toggleQuoteUser(id)}
                      />
                      <span className="cs-quote-meta">
                        <strong>{user.name || user.email || `User #${id}`}</strong>
                        <em>
                          {[user.email, user.role].filter(Boolean).join(' · ')}
                        </em>
                      </span>
                    </label>
                  )
                })}
                {filteredQuoteUsers.length === 0 ? <p className="cs-help">No matching users.</p> : null}
              </div>
              <div className="cs-actions">
                <button type="submit" className="cs-btn-primary" style={{ borderRadius: 9999 }} disabled={quoteBusy}>
                  {quoteBusy ? 'Saving…' : 'Save permissions'}
                </button>
              </div>
            </form>
          </Card>
        ) : null}

        {activeTab === 'departments' ? (
          <Card
            className="cs-card--compact"
            icon={FolderTree}
            accent="#7c3aed"
            title="Departments"
            subtitle="Departments appear when inviting employees and assigning users."
          >
            <ul className="cs-dept-list">
              {(cfg.departments || []).map((d) => {
                const locked = (cfg.lockedDepartments || ['Warehouse']).some(
                  (x) => String(x).toLowerCase() === String(d).toLowerCase(),
                )
                return (
                  <li key={d} className={`cs-dept-item${locked ? ' is-locked' : ''}`}>
                    <span>
                      {d}
                      {locked ? <em className="cs-dept-locked-tag">Default</em> : null}
                    </span>
                    {locked ? (
                      <span className="cs-help cs-dept-locked-note" title="System department">
                        Cannot remove
                      </span>
                    ) : (
                      <form method="post" action={actionUrl} className="cs-dept-remove-form">
                        <input type="hidden" name="remove_department" value="1" />
                        <input type="hidden" name="department_name" value={d} />
                        <button type="submit" className="cs-btn-ghost cs-dept-remove" title={`Remove ${d}`}>
                          Remove
                        </button>
                      </form>
                    )}
                  </li>
                )
              })}
              {(cfg.departments || []).length === 0 ? (
                <li className="cs-help">No departments yet. Add one below.</li>
              ) : null}
            </ul>
            <form method="post" action={actionUrl} className="cs-dept-add-form">
              <input type="hidden" name="add_department" value="1" />
              <Field label="New department" help="Example: Warehouse, Fleet, HR">
                <div className="cs-dept-add-row">
                  <input
                    className="cs-input"
                    name="department_name"
                    placeholder="Department name"
                    required
                    maxLength={80}
                    autoComplete="organization-title"
                  />
                  <button type="submit" className="cs-btn-primary">
                    Add department
                  </button>
                </div>
              </Field>
            </form>
          </Card>
        ) : null}

        {activeTab === 'branding' ? (
          <Card icon={Palette} accent="#db2777" title="Branding" subtitle="Customize workspace appearance and document headers.">
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
                <span className="cs-doc-preview-title" style={{ color: primaryColor }}>
                  Quotation / Invoice preview
                </span>
                <p>{company.company_name || 'Company name'}</p>
              </div>
              <div className="cs-actions">
                <button type="submit" className="cs-btn-primary">
                  Save branding
                </button>
              </div>
            </form>
          </Card>
        ) : null}

        {activeTab === 'finance' ? (
          <Card icon={Receipt} accent="#0d9488" title="Tax & Finance" subtitle="VAT, currency, and voucher workflow options for this company.">
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
              <Field
                label="Tax mode"
                help="Controls whether quotations and invoices add tax on top of prices or treat entered prices as tax-inclusive."
              >
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
                <textarea
                  className="cs-input"
                  name="document_footer_message"
                  rows={4}
                  defaultValue={settings.document_footer_message || ''}
                />
              </Field>
              <Field label="Workflow">
                <input type="hidden" name="approval_workflow_enabled" value="0" />
                <label className="cs-check">
                  <input
                    type="checkbox"
                    name="approval_workflow_enabled"
                    value="1"
                    defaultChecked={settings.approval_workflow_enabled === '1'}
                  />
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
                <button type="submit" className="cs-btn-primary">
                  Save changes
                </button>
              </div>
            </form>
          </Card>
        ) : null}

        {activeTab === 'modules' ? (
          <Card icon={LayoutGrid} accent="#2563eb" title="Modules" subtitle="Enable modules available for this company.">
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
                        <input type="checkbox" name={`module_enabled[${mod.key}]`} value="1" defaultChecked={!!mod.enabled} />
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
                <button type="submit" className="cs-btn-primary">
                  Save modules
                </button>
              </div>
            </form>
          </Card>
        ) : null}

        {activeTab === 'numbering' ? (
          <Card icon={Hash} accent="#4f46e5" title="Document Numbering" subtitle="Numbers follow PREFIX/YEAR/SUFFIX/###.">
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
                <button type="submit" className="cs-btn-primary">
                  Save numbering
                </button>
              </div>
            </form>
            {(cfg.legacyVoucherPrefixCount || 0) > 0 ? (
              <form method="post" action={actionUrl} className="cs-migrate">
                <input type="hidden" name="migrate_voucher_prefixes" value="1" />
                <p>
                  {cfg.legacyVoucherPrefixCount} legacy payment voucher number(s) do not match the current prefix (
                  {cfg.currentPvPrefix || 'n/a'}).
                </p>
                <button type="submit" className="cs-btn-ghost">
                  Migrate legacy voucher prefixes
                </button>
              </form>
            ) : null}
          </Card>
        ) : null}

        {activeTab === 'employees' ? (
          <>
            <Card icon={UserPlus} accent="#1d4ed8" title="Register company admin" subtitle="Creates a company admin and emails login details.">
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
                  <button type="submit" className="cs-btn-primary">
                    Register admin
                  </button>
                </div>
              </form>
            </Card>

            <Card icon={UserPlus} accent="#0f172a" title="Register employee" subtitle="Creates an employee account and emails login details.">
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
                      <option key={d} value={d}>
                        {d}
                      </option>
                    ))}
                  </select>
                </Field>
                <div className="cs-actions">
                  <button type="submit" className="cs-btn-primary">
                    Register employee
                  </button>
                </div>
              </form>
            </Card>

            <Card icon={Shield} accent="#0f766e" title="Registration settings" subtitle="Control how new employees join this company.">
              <form method="post" action={actionUrl}>
                <input type="hidden" name="save_profile" value="1" />
                <Field label="Registration mode">
                  <select
                    className="cs-input"
                    name="employee_registration_mode"
                    defaultValue={company.employee_registration_mode || 'admin_only'}
                  >
                    {Object.entries(cfg.employeeModeLabels || {}).map(([id, label]) => (
                      <option key={id} value={id}>
                        {label}
                      </option>
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
                  <button type="submit" className="cs-btn-primary">
                    Save registration settings
                  </button>
                </div>
              </form>
              <div style={{ marginTop: 16 }}>
                <p className="cs-help">Employee invite link</p>
                <CopyLink value={cfg.employeeInviteLink || ''} />
              </div>
            </Card>

            <Card icon={UserPlus} accent="#6366f1" title="Admins" subtitle="Company administrators">
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

            <Card icon={UserPlus} accent="#64748b" title="Recent employees" subtitle="Last 25 employees">
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
                <a className="cs-back" href={cfg.companyUsersUrl}>
                  View all company users
                </a>
              ) : null}
            </Card>
          </>
        ) : null}

        {activeTab === 'security' ? (
          <Card
            className="cs-card--compact cs-card--profile"
            icon={Shield}
            accent="#334155"
            title="Security"
            subtitle="Security controls for this company."
          >
            <p className="cs-help">
              Login passwords are managed per user. Use employee registration and company users tools to manage access.
              Multi-factor and SSO options can be added in a later release.
            </p>
          </Card>
        ) : null}

        {activeTab === 'register_employee' ? (
          <>
            <Card
              className="cs-card--compact cs-card--profile"
              icon={UserPlus}
              accent="#0f172a"
              title="Register employee"
              subtitle="Creates an employee account and emails login details."
            >
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
                      <option key={d} value={d}>
                        {d}
                      </option>
                    ))}
                  </select>
                </Field>
                <div className="cs-actions">
                  <button type="submit" className="cs-btn-primary" style={{ borderRadius: 9999 }}>
                    Register employee
                  </button>
                </div>
              </form>
            </Card>
            <Card
              className="cs-card--compact cs-card--profile"
              icon={UserPlus}
              accent="#1d4ed8"
              title="Register company admin"
              subtitle="Creates a company admin and emails login details."
            >
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
                  <button type="submit" className="cs-btn-primary" style={{ borderRadius: 9999 }}>
                    Register admin
                  </button>
                </div>
              </form>
            </Card>
          </>
        ) : null}

        {activeSectionEmbeds.length > 0 ? (
          <div className="cs-section-stack">
            {activeSectionEmbeds.map((section, index) => {
              const SectionIcon = ITEM_ICONS[section.icon] || activeMeta.icon || Building2
              return (
                <EmbedPanel
                  key={section.id || section.embedUrl}
                  icon={SectionIcon}
                  accent={index === 0 ? '#2563eb' : index === 1 ? '#0f766e' : '#0369a1'}
                  title={section.title}
                  subtitle={section.subtitle}
                  src={section.embedUrl}
                  frameClassName="cs-embed-frame--section"
                />
              )
            })}
          </div>
        ) : null}

        {activeEmbedUrl ? (
          <EmbedPanel
            icon={activeMeta.icon || Building2}
            accent={activeRailItem?.danger ? '#dc2626' : '#2563eb'}
            title={activeMeta.title}
            subtitle={activeMeta.subtitle}
            src={activeEmbedUrl}
          />
        ) : null}

        {activeTab === 'register_company' ? (
          <Card
            className="cs-card--compact cs-card--profile"
            icon={PlusSquare}
            accent="#0f766e"
            title="Register new company"
            subtitle="Create a company tenant with default module setup."
          >
            <form method="post" action={cfg.registerCompany?.formAction || 'management.php'}>
              <input type="hidden" name="create_company" value="1" />
              <input type="hidden" name="return_to" value={cfg.registerCompany?.returnTo || actionUrl} />
              <Field label="Company name">
                <input className="cs-input" name="company_name" placeholder="e.g. Acme Corp" required />
              </Field>
              <Field label="Subdomain">
                <input className="cs-input" name="subdomain" placeholder="e.g. acme" />
              </Field>
              <Field label="Database">
                <input className="cs-input" name="db_name" placeholder="e.g. acme_db" />
              </Field>
              <Field label="Currency">
                <select className="cs-input" name="base_currency" defaultValue="TZS">
                  <option value="TZS">TZS</option>
                  <option value="USD">USD</option>
                </select>
              </Field>
              <Field label="Timezone">
                <select className="cs-input" name="timezone" defaultValue="Africa/Dar_es_Salaam">
                  <option value="Africa/Dar_es_Salaam">Tanzania</option>
                  <option value="UTC">UTC</option>
                </select>
              </Field>
              <Field label="Industry">
                <select className="cs-input" name="industry_type" defaultValue="trading">
                  <option value="trading">Trading</option>
                  <option value="logistics">Logistics</option>
                </select>
              </Field>
              <div className="cs-actions">
                <button type="submit" className="cs-btn-primary" style={{ borderRadius: 9999 }}>
                  Create company
                </button>
              </div>
            </form>
          </Card>
        ) : null}

        {activeTab === 'site_contact' ? (
          <Card
            className="cs-card--compact cs-card--profile"
            icon={Globe}
            accent="#0369a1"
            title="Website contact page"
            subtitle="Contact details and wording on the public Contact page and site footer."
          >
            {contactMessage ? <div className="cs-alert cs-alert--ok">{contactMessage}</div> : null}
            {contactError ? <div className="cs-alert cs-alert--err">{contactError}</div> : null}
            <form onSubmit={onSaveSiteContact}>
              <div className="cs-profile-grid">
                {['phone', 'whatsapp', 'email', 'hours', 'address', 'instagram'].map((key) => (
                  <label key={key} className={`cs-stack-field${key === 'instagram' ? ' cs-stack-field--wide' : ''}`}>
                    <span>{key === 'hours' ? 'Opening hours' : key === 'address' ? 'Location' : key.charAt(0).toUpperCase() + key.slice(1)}</span>
                    <input
                      className="cs-input"
                      type={key === 'email' ? 'email' : 'text'}
                      value={contactForm[key] || ''}
                      onChange={(e) => setContactForm((prev) => ({ ...prev, [key]: e.target.value }))}
                      placeholder={key === 'instagram' ? 'official_ace84 or profile link' : ''}
                    />
                  </label>
                ))}
              </div>
              <p className="cs-help">Leave a field empty to hide it on the website.</p>
              <div className="cs-actions">
                {cfg.siteContactPageUrl ? (
                  <a className="cs-back" href={cfg.siteContactPageUrl} target="_blank" rel="noopener noreferrer">
                    View page
                  </a>
                ) : null}
                <button type="submit" className="cs-btn-primary" style={{ borderRadius: 9999 }} disabled={contactBusy}>
                  {contactBusy ? 'Saving…' : 'Save contact details'}
                </button>
              </div>
            </form>
          </Card>
        ) : null}

        {activeTab === 'factory_reset' ? (
          <Card
            className="cs-card--compact cs-card--profile"
            icon={Trash2}
            accent="#dc2626"
            title="Factory Reset"
            subtitle="Wipe all operational transactions, stock logs, attendance, and uploaded files for this company."
          >
            {resetMessage ? <div className="cs-alert cs-alert--ok">{resetMessage}</div> : null}
            {resetError ? <div className="cs-alert cs-alert--err">{resetError}</div> : null}
            <form onSubmit={onFactoryReset}>
              <p className="cs-help" style={{ marginBottom: '0.75rem' }}>
                This cannot be undone. Type <strong>RESET</strong> to confirm for {company.company_name || 'this company'}.
              </p>
              <label className="cs-stack-field">
                <span>Confirmation</span>
                <input
                  className="cs-input"
                  value={resetConfirm}
                  onChange={(e) => setResetConfirm(e.target.value)}
                  placeholder="RESET"
                  autoComplete="off"
                />
              </label>
              <div className="cs-actions">
                <button
                  type="submit"
                  className="cs-btn-danger"
                  style={{ borderRadius: 9999 }}
                  disabled={resetBusy || resetConfirm.trim().toUpperCase() !== 'RESET'}
                >
                  {resetBusy ? 'Resetting…' : 'Run factory reset'}
                </button>
              </div>
            </form>
          </Card>
        ) : null}

        {activeTab === 'danger' && isSuperAdmin ? (
          <Card
            className="cs-card--compact cs-card--profile"
            icon={AlertTriangle}
            accent="#dc2626"
            title="Lifecycle"
            subtitle="Status and setup controls for this company."
          >
            <form method="post" action={actionUrl}>
              <input type="hidden" name="save_profile" value="1" />
              <div className="cs-profile-grid">
                <label className="cs-stack-field">
                  <span>Status</span>
                  <select className="cs-input" name="status" defaultValue={company.status || 'active'}>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </label>
                <label className="cs-stack-field">
                  <span>Setup status</span>
                  <select className="cs-input" name="setup_status" defaultValue={company.setup_status || 'pending_setup'}>
                    <option value="active">Active</option>
                    <option value="pending_setup">Pending setup</option>
                    <option value="suspended">Suspended</option>
                  </select>
                </label>
              </div>
              {isPending ? (
                <label className="cs-check">
                  <input type="checkbox" name="complete_setup" value="1" />
                  Mark setup complete and continue to module selection
                </label>
              ) : null}
              <div className="cs-actions">
                <button type="submit" className="cs-btn-danger" style={{ borderRadius: 9999 }}>
                  Save lifecycle settings
                </button>
              </div>
            </form>
          </Card>
        ) : null}
      </div>
    </Tabs.Root>
  )
}
