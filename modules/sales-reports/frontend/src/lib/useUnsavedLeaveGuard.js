import { useCallback, useEffect, useRef, useState } from 'react'

function shouldConfirmLeave(anchor) {
  if (!anchor?.href) return false
  if (anchor.target === '_blank' || anchor.hasAttribute('download')) return false
  if (anchor.closest('.word-export-wrap')) return false

  const href = anchor.getAttribute('href') || ''
  if (!href || href.startsWith('#') || href.startsWith('javascript:')) return false

  try {
    const next = new URL(anchor.href, window.location.href)
    const current = new URL(window.location.href)
    if (next.pathname === current.pathname && next.search === current.search) return false
    if (next.pathname.includes('/api/export.php')) return false
    return true
  } catch {
    return false
  }
}

export function useUnsavedLeaveGuard(dirty, fallbackHref) {
  const allowLeaveRef = useRef(false)
  const leavePromptOpenRef = useRef(false)
  const [leavePromptHref, setLeavePromptHref] = useState(null)
  leavePromptOpenRef.current = Boolean(leavePromptHref)

  const requestNavigation = useCallback((href) => {
    if (!dirty || allowLeaveRef.current) {
      window.location.href = href
      return
    }
    setLeavePromptHref(href)
  }, [dirty])

  const confirmLeave = useCallback(() => {
    if (!leavePromptHref) return
    allowLeaveRef.current = true
    const href = leavePromptHref
    setLeavePromptHref(null)
    window.location.href = href
  }, [leavePromptHref])

  const cancelLeave = useCallback(() => {
    setLeavePromptHref(null)
  }, [])

  useEffect(() => {
    if (!dirty) return undefined

    const onDocumentClick = (event) => {
      if (allowLeaveRef.current || leavePromptOpenRef.current) return
      const anchor = event.target.closest?.('a[href]')
      if (!shouldConfirmLeave(anchor)) return
      event.preventDefault()
      event.stopPropagation()
      setLeavePromptHref(anchor.href)
    }

    const onPopState = () => {
      if (allowLeaveRef.current) return
      window.history.pushState({ srEditorGuard: true }, '', window.location.href)
      setLeavePromptHref(fallbackHref)
    }

    window.history.pushState({ srEditorGuard: true }, '', window.location.href)
    document.addEventListener('click', onDocumentClick, true)
    window.addEventListener('popstate', onPopState)

    return () => {
      document.removeEventListener('click', onDocumentClick, true)
      window.removeEventListener('popstate', onPopState)
    }
  }, [dirty, fallbackHref])

  return {
    leavePromptOpen: Boolean(leavePromptHref),
    requestNavigation,
    confirmLeave,
    cancelLeave,
  }
}
