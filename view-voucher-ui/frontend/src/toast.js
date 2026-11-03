const ICONS = {
  success: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
  error: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>',
  info: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.5v.01"/></svg>',
}

export function showToast(message, type = 'success', duration = 4500) {
  const kind = ICONS[type] ? type : 'info'
  const el = document.createElement('div')
  el.className = `vv-toast vv-toast--${kind}`
  el.setAttribute('role', 'status')
  el.setAttribute('aria-live', 'polite')

  const icon = document.createElement('i')
  icon.innerHTML = ICONS[kind]
  const text = document.createElement('span')
  text.textContent = message
  const close = document.createElement('button')
  close.type = 'button'
  close.className = 'vv-toast__close'
  close.setAttribute('aria-label', 'Dismiss')
  close.innerHTML = '&times;'

  const dismiss = () => {
    el.classList.remove('is-in')
    setTimeout(() => el.remove(), 250)
  }
  close.addEventListener('click', dismiss)
  el.append(icon, text, close)
  document.body.appendChild(el)
  requestAnimationFrame(() => el.classList.add('is-in'))
  setTimeout(dismiss, duration)
}
