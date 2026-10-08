import { useEffect } from 'react'

export function getCfg() {
  return window.__HOME_CFG__ || {}
}

export function getContact() {
  return getCfg().contact || {}
}

const DEFAULT_TEXTS = {
  contact_eyebrow: 'Contact us',
  contact_title: 'Talk to the UltiTech team',
  contact_lead:
    'Questions about UltiTech ERP, a demo for your team, or help with your account? Reach us on the channel that suits you.',
  call_label: 'Call us',
  call_action: 'Call now',
  whatsapp_label: 'WhatsApp',
  whatsapp_action: 'Open chat',
  whatsapp_greeting: 'Hello UltiTech, I would like to know more about UltiTech ERP.',
  email_label: 'Email',
  email_action: 'Send email',
  email_subject: 'UltiTech ERP enquiry',
  instagram_label: 'Instagram',
  instagram_action: 'Follow us',
  location_label: 'Location',
  hours_label: 'Opening hours',
  cta_title: 'Prefer to try it first?',
  cta_text: 'Start a 14-day free trial of the full suite. No card required.',
  cta_button: 'Start free trial',
  footer_tagline: 'One platform for finance, sales, stock, people, and delivery.',
}

export function getTexts() {
  return { ...DEFAULT_TEXTS, ...(getCfg().texts || {}) }
}

/** 0785653817 -> 0785 653 817; other formats are shown as entered. */
export function formatPhone(phone) {
  const value = String(phone || '').trim()
  const digits = value.replace(/\D/g, '')
  if (/^0\d{9}$/.test(digits) && /^[\d\s]+$/.test(value)) {
    return `${digits.slice(0, 4)} ${digits.slice(4, 7)} ${digits.slice(7)}`
  }
  return value
}

export function useAos() {
  useEffect(() => {
    let cancelled = false
    ;(async () => {
      try {
        if (!window.AOS) {
          await new Promise((resolve, reject) => {
            const s = document.createElement('script')
            s.src = 'https://unpkg.com/aos@next/dist/aos.js'
            s.onload = resolve
            s.onerror = reject
            document.body.appendChild(s)
          })
        }
        if (!cancelled && window.AOS) {
          window.AOS.init({ once: true, duration: 700, easing: 'ease-out-cubic' })
        }
      } catch {
        // Animation library is optional.
      }
    })()
    return () => {
      cancelled = true
    }
  }, [])
}
