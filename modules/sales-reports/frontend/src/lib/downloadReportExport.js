function parseFilename(contentDisposition, fallback = 'report') {
  if (!contentDisposition) return fallback

  const utfMatch = /filename\*=UTF-8''([^;]+)/i.exec(contentDisposition)
  if (utfMatch?.[1]) {
    try {
      return decodeURIComponent(utfMatch[1].replace(/["']/g, ''))
    } catch {
      return utfMatch[1]
    }
  }

  const match = /filename="?([^";]+)"?/i.exec(contentDisposition)
  return match?.[1]?.trim() || fallback
}

const FORMAT_FALLBACK = {
  pdf: 'report.pdf',
  word: 'report.doc',
  docx: 'report.doc',
  excel: 'report.csv',
  csv: 'report.csv',
}

export async function downloadReportExport(url, { format = 'pdf', onStatus } = {}) {
  const notify = (message) => {
    if (typeof onStatus === 'function') onStatus(message)
  }

  notify('Preparing download...')

  if (format === 'print') {
    window.open(url, '_blank', 'noopener,noreferrer')
    notify('Opening print view...')
    await new Promise((resolve) => window.setTimeout(resolve, 900))
    return
  }

  notify('Generating file...')

  const response = await fetch(url, { credentials: 'same-origin' })
  if (!response.ok) {
    throw new Error(`Export failed (${response.status})`)
  }

  const blob = await response.blob()
  const filename = parseFilename(
    response.headers.get('Content-Disposition'),
    FORMAT_FALLBACK[format] || 'report'
  )

  notify('Saving file...')

  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = filename
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)

  notify('Download complete')
  await new Promise((resolve) => window.setTimeout(resolve, 700))
}

export function exportStatusLabel(format) {
  switch (format) {
    case 'pdf':
      return 'Downloading PDF...'
    case 'word':
    case 'docx':
      return 'Downloading Word document...'
    case 'excel':
    case 'csv':
      return 'Downloading Excel file...'
    case 'print':
      return 'Opening print view...'
    default:
      return 'Downloading...'
  }
}
