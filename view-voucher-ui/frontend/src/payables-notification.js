import './payables-notification.css'

let activeNotification

export function showPayablesNotification(message) {
  if (activeNotification) activeNotification.dismiss()

  const notification = document.createElement('div')
  notification.className = 'payables-notification'
  notification.setAttribute('role', 'status')
  notification.setAttribute('aria-live', 'polite')

  const icon = document.createElement('span')
  icon.className = 'payables-notification__icon'
  icon.setAttribute('aria-hidden', 'true')
  icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m7.5 12 3 3 6-6"/></svg>'

  const content = document.createElement('div')
  content.className = 'payables-notification__content'
  const label = document.createElement('div')
  label.className = 'payables-notification__label'
  label.textContent = 'Success'
  const title = document.createElement('strong')
  title.className = 'payables-notification__title'
  title.textContent = 'Payables updated'
  const detail = document.createElement('div')
  detail.className = 'payables-notification__detail'
  detail.textContent = message
  content.append(label, title, detail)

  const close = document.createElement('button')
  close.type = 'button'
  close.className = 'payables-notification__close'
  close.setAttribute('aria-label', 'Dismiss notification')
  close.textContent = '\u00d7'
  const dismiss = () => {
    clearTimeout(timer)
    notification.remove()
    if (activeNotification?.element === notification) activeNotification = undefined
  }
  close.addEventListener('click', dismiss)
  notification.append(icon, content, close)
  document.body.appendChild(notification)
  const timer = setTimeout(dismiss, 6000)
  activeNotification = { element: notification, dismiss }
}
