import { CFG } from '../config.js'

async function parseJson(response) {
  const text = await response.text()
  try {
    return JSON.parse(text)
  } catch {
    throw new Error('Invalid server response.')
  }
}

export function resolveKpiAiAssistUrl() {
  return CFG.kpiAiAssistUrl || ''
}

export async function fetchKpiAiConfirmation(traceKey, trace) {
  const url = resolveKpiAiAssistUrl()
  if (!url) {
    return {
      confirmation: trace?.confirmation || '',
      viaAi: false,
    }
  }

  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      mode: 'confirm',
      traceKey,
      trace: {
        title: trace?.title || '',
        headline: trace?.headline || '',
        confirmation: trace?.confirmation || '',
        method: trace?.method || '',
        itemCount: Array.isArray(trace?.items) ? trace.items.length : 0,
      },
    }),
  })

  const data = await parseJson(res)
  if (!data?.ok) {
    throw new Error(data?.error || 'AI verification failed.')
  }
  return {
    confirmation: data.confirmation || trace?.confirmation || '',
    viaAi: Boolean(data.viaAi),
  }
}

export async function sendKpiChatMessage(traceKey, trace, question, messages = []) {
  const url = resolveKpiAiAssistUrl()
  if (!url) {
    throw new Error('AI assistant is not configured.')
  }

  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      mode: 'chat',
      traceKey,
      question,
      messages,
      trace: {
        title: trace?.title || '',
        headline: trace?.headline || '',
        confirmation: trace?.confirmation || '',
        method: trace?.method || '',
        items: trace?.items || [],
        itemCount: Array.isArray(trace?.items) ? trace.items.length : 0,
      },
    }),
  })

  const data = await parseJson(res)
  if (!data?.ok) {
    throw new Error(data?.error || 'AI assistant failed.')
  }
  return data
}
