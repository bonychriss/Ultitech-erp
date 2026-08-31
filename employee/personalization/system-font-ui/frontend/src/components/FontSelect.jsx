import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { ChevronDown } from 'lucide-react'
import { companyDefaultLabel, labelForKey } from '../config.js'

export default function FontSelect({ value, options, onChange, disabled }) {
  const [open, setOpen] = useState(false)
  const [menuStyle, setMenuStyle] = useState({})
  const wrapRef = useRef(null)
  const btnRef = useRef(null)

  const selectedLabel = useMemo(() => {
    if (!value) return companyDefaultLabel()
    const match = options.find((o) => o.id === value)
    return match ? match.label : labelForKey(value)
  }, [value, options])

  const positionMenu = () => {
    const btn = btnRef.current
    if (!btn) return
    const rect = btn.getBoundingClientRect()
    const gap = 6
    const padding = 12
    const spaceBelow = window.innerHeight - rect.bottom - padding
    const spaceAbove = rect.top - padding
    const openUp = spaceBelow < 180 && spaceAbove > spaceBelow
    const maxHeight = Math.max(140, Math.min(280, openUp ? spaceAbove - gap : spaceBelow - gap))

    setMenuStyle({
      position: 'fixed',
      left: `${Math.round(rect.left)}px`,
      width: `${Math.round(rect.width)}px`,
      maxHeight: `${Math.round(maxHeight)}px`,
      zIndex: 10060,
      ...(openUp
        ? { bottom: `${Math.round(window.innerHeight - rect.top + gap)}px`, top: 'auto' }
        : { top: `${Math.round(rect.bottom + gap)}px`, bottom: 'auto' }),
    })
  }

  useEffect(() => {
    if (!open) return undefined
    positionMenu()
    const onScroll = () => positionMenu()
    const onResize = () => positionMenu()
    window.addEventListener('scroll', onScroll, true)
    window.addEventListener('resize', onResize)
    return () => {
      window.removeEventListener('scroll', onScroll, true)
      window.removeEventListener('resize', onResize)
    }
  }, [open])

  useEffect(() => {
    if (!open) return undefined
    function onDocClick(e) {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) {
        const menu = document.getElementById('sf-font-menu-portal')
        if (menu && menu.contains(e.target)) return
        setOpen(false)
      }
    }
    function onKey(e) {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDocClick)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDocClick)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const menu = open
    ? createPortal(
        <ul id="sf-font-menu-portal" className="sf-dropdown-menu" style={menuStyle} role="listbox" aria-label="Font family">
          <li>
            <button
              type="button"
              className={`sf-dropdown-option${value === '' ? ' is-selected' : ''}`}
              style={{ fontFamily: options.find((o) => o.id === '')?.stack || undefined }}
              onClick={() => { onChange(''); setOpen(false) }}
              role="option"
              aria-selected={value === ''}
            >
              {companyDefaultLabel()}
            </button>
          </li>
          {options.filter((o) => o.id !== '').map((opt) => (
            <li key={opt.id}>
              <button
                type="button"
                className={`sf-dropdown-option${value === opt.id ? ' is-selected' : ''}`}
                style={{ fontFamily: opt.stack }}
                onClick={() => { onChange(opt.id); setOpen(false) }}
                role="option"
                aria-selected={value === opt.id}
              >
                {opt.label}
              </button>
            </li>
          ))}
        </ul>,
        document.body,
      )
    : null

  return (
    <div className="sf-dropdown" ref={wrapRef}>
      <button
        ref={btnRef}
        type="button"
        className="sf-dropdown-toggle"
        aria-haspopup="listbox"
        aria-expanded={open}
        disabled={disabled}
        onClick={() => setOpen((v) => !v)}
      >
        <span>{selectedLabel}</span>
        <ChevronDown size={16} className={open ? 'sf-chevron-open' : ''} aria-hidden="true" />
      </button>
      {menu}
    </div>
  )
}
