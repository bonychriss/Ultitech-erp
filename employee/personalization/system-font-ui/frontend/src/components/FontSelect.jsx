import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { ChevronDown } from 'lucide-react'
import { companyDefaultLabel, labelForKey } from '../config.js'

function applyImportantFont(node, stack) {
  if (!node || !stack) return
  node.style.setProperty('font-family', stack, 'important')
}

export default function FontSelect({ value, options, onChange, disabled }) {
  const [open, setOpen] = useState(false)
  const [menuStyle, setMenuStyle] = useState({})
  const wrapRef = useRef(null)
  const btnRef = useRef(null)
  const valueRef = useRef(null)
  const optionRefs = useRef({})

  const selected = useMemo(() => {
    if (!value) return options.find((o) => o.id === '') || null
    return options.find((o) => o.id === value) || null
  }, [value, options])

  const selectedLabel = useMemo(() => {
    if (!value) return companyDefaultLabel()
    return selected ? selected.label : labelForKey(value)
  }, [value, selected])

  const selectedStack = selected?.stack || 'system-ui, sans-serif'

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

  useLayoutEffect(() => {
    const paint = () => {
      applyImportantFont(valueRef.current, selectedStack)
      options.forEach((opt) => {
        const key = opt.id === '' ? '__company__' : opt.id
        applyImportantFont(optionRefs.current[key], opt.stack || 'system-ui, sans-serif')
      })
    }
    paint()
    document.addEventListener('sf-fonts-ready', paint)
    return () => document.removeEventListener('sf-fonts-ready', paint)
  }, [options, open, selectedStack, value])

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

  // Late CSS override after erp-system-font-final
  useEffect(() => {
    let style = document.getElementById('sf-font-select-override')
    if (!style) {
      style = document.createElement('style')
      style.id = 'sf-font-select-override'
      document.body.appendChild(style)
    }
    const rules = options.map((opt) => {
      const stack = String(opt.stack || 'system-ui, sans-serif').replace(/;/g, '')
      const key = opt.id === '' ? '__company__' : String(opt.id).replace(/[^a-z0-9_-]/gi, '')
      if (!key) return ''
      return [
        `html body .sf-font-face[data-font="${key}"]`,
        `html body button.sf-dropdown-option[data-font="${key}"]`,
        `html body.dashboard .sf-font-face[data-font="${key}"]`,
        `html body.dashboard button.sf-dropdown-option[data-font="${key}"]`,
        `html body.page-system-font .sf-font-face[data-font="${key}"]`,
        `html body.page-system-font button.sf-dropdown-option[data-font="${key}"]`,
      ].join(',') + `{font-family:${stack}!important;}`
    }).filter(Boolean).join('\n')
    style.textContent = rules
  }, [options])

  const renderOption = (opt) => {
    const isSelected = value === opt.id
    const stack = opt.stack || 'system-ui, sans-serif'
    const key = opt.id === '' ? '__company__' : opt.id
    return (
      <li key={key}>
        <button
          type="button"
          className={`sf-dropdown-option${isSelected ? ' is-selected' : ''}`}
          data-font={key}
          style={{ '--sf-option-font': stack }}
          ref={(node) => {
            if (node) {
              optionRefs.current[key] = node
              applyImportantFont(node, stack)
            } else {
              delete optionRefs.current[key]
            }
          }}
          onClick={() => { onChange(opt.id); setOpen(false) }}
          role="option"
          aria-selected={isSelected}
        >
          <span
            className="sf-font-face"
            data-font={key}
            style={{ '--sf-option-font': stack }}
            ref={(node) => applyImportantFont(node, stack)}
          >
            {opt.id === '' ? companyDefaultLabel() : opt.label}
          </span>
        </button>
      </li>
    )
  }

  const menu = open
    ? createPortal(
        <ul id="sf-font-menu-portal" className="sf-dropdown-menu" style={menuStyle} role="listbox" aria-label="Font family">
          {options.find((o) => o.id === '') ? renderOption(options.find((o) => o.id === '')) : null}
          {options.filter((o) => o.id !== '').map((opt) => renderOption(opt))}
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
        <span
          ref={valueRef}
          className="sf-font-face"
          data-font={selected?.id === '' || !selected?.id ? '__company__' : selected.id}
          style={{ '--sf-option-font': selectedStack }}
        >
          {selectedLabel}
        </span>
        <ChevronDown size={16} className={open ? 'sf-chevron-open' : ''} aria-hidden="true" />
      </button>
      {menu}
    </div>
  )
}
