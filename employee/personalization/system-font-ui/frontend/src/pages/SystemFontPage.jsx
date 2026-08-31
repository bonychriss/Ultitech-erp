import { useCallback, useEffect, useMemo, useState } from 'react'
import { ArrowLeft, Check, Eye, Loader2, Type } from 'lucide-react'
import FontSelect from '../components/FontSelect.jsx'
import { CFG, companyDefaultLabel, labelForKey, stackForKey } from '../config.js'

const loadedGoogle = new Set()

function loadGoogleFont(url) {
  if (!url || loadedGoogle.has(url)) return
  loadedGoogle.add(url)
  const link = document.createElement('link')
  link.rel = 'stylesheet'
  link.href = url
  document.head.appendChild(link)
}

function preloadFonts(fonts, companyFont) {
  fonts.forEach((f) => loadGoogleFont(f.google))
  if (companyFont?.google) loadGoogleFont(companyFont.google)
}

export default function SystemFontPage() {
  const [selectedKey, setSelectedKey] = useState(CFG.selectedKey)
  const [activeFont, setActiveFont] = useState(CFG.effectiveFont)
  const [isPersonalChoice, setIsPersonalChoice] = useState(CFG.isPersonalChoice)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  const dropdownOptions = useMemo(() => [
    { id: '', label: companyDefaultLabel(), stack: CFG.companyFont.stack, google: '' },
    ...CFG.fonts,
  ], [])

  const previewStack = useMemo(() => stackForKey(selectedKey), [selectedKey])
  const previewLabel = useMemo(() => labelForKey(selectedKey), [selectedKey])

  useEffect(() => {
    preloadFonts(CFG.fonts, CFG.companyFont)
  }, [])

  useEffect(() => {
    const font = CFG.fonts.find((f) => f.id === selectedKey)
    loadGoogleFont(selectedKey === '' ? '' : (font?.google || ''))
  }, [selectedKey])

  const applyServerData = useCallback((data) => {
    if (!data) return
    setSelectedKey(typeof data.selectedKey === 'string' ? data.selectedKey : '')
    setActiveFont(data.effectiveFont || CFG.effectiveFont)
    setIsPersonalChoice(Boolean(data.isPersonalChoice))
  }, [])

  async function saveFont() {
    setSaving(true)
    setMessage('')
    setError('')
    try {
      const res = await fetch(CFG.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ userFont: selectedKey }),
      })
      const data = await res.json()
      if (!data?.ok) {
        setError(data?.error || 'Could not save your font preference.')
        return
      }
      applyServerData(data.data)
      setMessage(data.message || 'Your font preference was saved.')
    } catch {
      setError('Could not save your font preference. Please try again.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="sf-shell">
      <a href={CFG.backUrl} className="sf-back">
        <ArrowLeft size={14} aria-hidden="true" />
        Back to Personalization
      </a>

      <div className="sf-page-head">
        <h1>System Font</h1>
        <p>
          Pick a font for your account. Your choice applies across menus, forms, tables, and dashboards
          - only for you, not the whole company.
        </p>
      </div>

      {message ? <div className="sf-alert sf-alert-success">{message}</div> : null}
      {error ? <div className="sf-alert sf-alert-error">{error}</div> : null}

      <div className="sf-layout">
        <aside className="sf-panel sf-settings">
          <div className="sf-panel-title">Settings</div>

          <div className="sf-status">
            <div className="sf-status-icon"><Type size={18} aria-hidden="true" /></div>
            <div>
              <div className="sf-status-label">Active font</div>
              <div className="sf-status-value">{activeFont.label || 'Poppins'}</div>
              <div className="sf-status-source">{isPersonalChoice ? 'Your personal choice' : 'Company default'}</div>
            </div>
          </div>

          <div className="sf-field">
            <span className="sf-label">Font family</span>
            <FontSelect
              value={selectedKey}
              options={dropdownOptions}
              onChange={setSelectedKey}
              disabled={saving}
            />
          </div>

          <button type="button" className="sf-save-btn" onClick={saveFont} disabled={saving}>
            {saving ? <Loader2 size={16} className="sf-spin" aria-hidden="true" /> : <Check size={16} aria-hidden="true" />}
            {saving ? 'Saving...' : 'Save font preference'}
          </button>
        </aside>

        <section className="sf-panel sf-preview-wrap">
          <div className="sf-panel-title">Live preview</div>
          <span className="sf-preview-badge">
            <Eye size={12} aria-hidden="true" />
            {previewLabel}
          </span>

          <div
            className="sf-preview-box"
            style={{ fontFamily: previewStack, '--preview-font-stack': previewStack }}
          >
            <div className="sf-preview-chrome" aria-hidden="true">
              <span className="sf-dot sf-dot-red" />
              <span className="sf-dot sf-dot-yellow" />
              <span className="sf-dot sf-dot-green" />
            </div>
            <div className="sf-preview-content">
              <h2 className="sf-preview-heading">Sales Dashboard Overview</h2>
              <p className="sf-preview-sub">Quotation #1042 | Updated today</p>
              <p className="sf-preview-paragraph">
                Welcome back. This sample paragraph shows how body text will appear across the ERP
                - in list views, detail pages, notifications, and form descriptions. Good typography
                keeps long passages easy to scan while headings and labels stay crisp and readable.
              </p>
              <p className="sf-preview-paragraph sf-preview-paragraph-last">
                The quick brown fox jumps over the lazy dog. 0123456789 - Total due: TZS 1,245,000 - Customer: Acme Trading Ltd.
              </p>
              <div className="sf-preview-ui">
                <span className="sf-preview-btn">Primary action</span>
                <span className="sf-preview-btn sf-preview-btn-outline">Secondary</span>
                <span className="sf-preview-label">Form label | Status: Pending</span>
              </div>
              <div className="sf-preview-meta">
                <span>Heading weight: <strong>700</strong></span>
                <span>Body size: <strong>15px</strong></span>
                <span>Line height: <strong>1.65</strong></span>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  )
}
