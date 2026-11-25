const SHEET_WIDTH_PX = 1120

function buildVoucherSheet() {
  const element = document.getElementById('voucherFull')
  if (!element) throw new Error('Voucher preview not found')

  const root = document.createElement('div')
  root.className = 'pdf-export vv-voucher-sheet'
  root.style.cssText = [
    `width:${SHEET_WIDTH_PX}px`,
    'max-width:none',
    'margin:0',
    'padding:0',
    'background:#fff',
    'color:#000',
    'box-sizing:border-box',
    'font-family:"Courier New",Courier,monospace',
    'position:fixed',
    'left:0',
    'top:0',
    'z-index:1',
    'pointer-events:none',
  ].join(';')

  const header = element.querySelector('.pv-header')
  if (header) root.appendChild(header.cloneNode(true))

  const tablesWrap = element.querySelector('.tables-wrap')
  if (tablesWrap) {
    Array.from(tablesWrap.children)
      .filter((node) => node.tagName === 'TABLE')
      .slice(0, 3)
      .forEach((tbl) => {
        const clone = tbl.cloneNode(true)
        if (clone instanceof HTMLElement) {
          clone.style.width = '100%'
          clone.style.maxWidth = 'none'
          clone.style.minWidth = '0'
          clone.style.tableLayout = 'fixed'
          clone.style.borderCollapse = 'collapse'
          clone.style.boxSizing = 'border-box'
        }
        root.appendChild(clone)
      })
  }

  // Soften default <th> bold so the PDF matches the light on-screen weight
  const soften = document.createElement('style')
  soften.textContent = `
    .vv-voucher-sheet, .vv-voucher-sheet * { font-weight: 400 !important; }
    .vv-voucher-sheet h1,
    .vv-voucher-sheet th,
    .vv-voucher-sheet .vv-approval-label { font-weight: 500 !important; }
    .vv-voucher-sheet strong, .vv-voucher-sheet b { font-weight: 500 !important; }
  `
  root.prepend(soften)

  if (!root.querySelector('table')) throw new Error('Voucher tables not found')
  return root
}

async function inlineLogos(root) {
  const imgs = Array.from(root.querySelectorAll('img'))
  await Promise.all(imgs.map(async (img) => {
    const orig = img.getAttribute('data-logo-orig') || img.getAttribute('src') || ''
    if (!orig || orig.startsWith('data:')) return
    img.classList.remove('vv-logo-recolored')
    img.removeAttribute('data-logo-state')
    img.style.filter = 'none'
    img.style.mixBlendMode = 'normal'
    img.style.background = 'transparent'
    try {
      const res = await fetch(orig, { credentials: 'include' })
      if (!res.ok) throw new Error('logo fetch failed')
      const blob = await res.blob()
      const dataUrl = await new Promise((resolve, reject) => {
        const fr = new FileReader()
        fr.onload = () => resolve(fr.result)
        fr.onerror = reject
        fr.readAsDataURL(blob)
      })
      img.src = dataUrl
    } catch {
      img.src = orig
    }
    img.removeAttribute('srcset')
    img.loading = 'eager'
    if (!img.complete) {
      await new Promise((resolve) => {
        img.onload = resolve
        img.onerror = resolve
      })
    }
  }))
}

function buildOverlay(label) {
  const styleEl = document.createElement('style')
  styleEl.textContent = '@keyframes vvPdfSpin{to{transform:rotate(360deg)}}@keyframes vvPdfBar{0%{transform:translateX(-100%)}100%{transform:translateX(250%)}}'
  const ov = document.createElement('div')
  ov.setAttribute('data-pdf-overlay', '1')
  ov.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:#0b141a;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;color:#e9edef;font:600 16px system-ui,sans-serif;'
  ov.innerHTML = '<div style="width:56px;height:56px;border-radius:50%;border:4px solid #2a3942;border-top-color:#00a884;animation:vvPdfSpin .9s linear infinite"></div>'
    + `<div>${label}</div>`
    + '<div style="width:220px;height:4px;border-radius:4px;background:#2a3942;overflow:hidden"><div style="width:40%;height:100%;background:#00a884;animation:vvPdfBar 1.2s ease-in-out infinite"></div></div>'
  ov.appendChild(styleEl)
  document.body.appendChild(ov)
  return ov
}

/**
 * Same landscape A4 PDF bytes used by Download PDF and Print.
 */
async function buildVoucherPdfBlob(onProgress) {
  const h2c = typeof window.html2canvas === 'function' ? window.html2canvas : null
  const JsPdfCtor = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : (window.jsPDF || null)
  if (!h2c || !JsPdfCtor) throw new Error('PDF engine not available')

  let exportRoot = null
  try {
    exportRoot = buildVoucherSheet()
    document.body.appendChild(exportRoot)
    await inlineLogos(exportRoot)
    if (typeof onProgress === 'function') onProgress(45)

    const canvas = await h2c(exportRoot, {
      scale: 3,
      useCORS: true,
      letterRendering: true,
      windowWidth: SHEET_WIDTH_PX,
      windowHeight: Math.max(900, exportRoot.scrollHeight + 40),
      backgroundColor: '#ffffff',
      onclone: (clonedDoc) => {
        clonedDoc.documentElement.setAttribute('data-theme', 'light')
      },
    })

    if (typeof onProgress === 'function') onProgress(85)

    const pdf = new JsPdfCtor({ unit: 'mm', format: 'a4', orientation: 'landscape' })
    const pageW = pdf.internal.pageSize.getWidth()
    const pageH = pdf.internal.pageSize.getHeight()
    const margin = 6
    const maxW = pageW - margin * 2
    const maxH = pageH - margin * 2
    let imgW = maxW
    let imgH = (canvas.height * imgW) / canvas.width
    if (imgH > maxH) {
      imgH = maxH
      imgW = (canvas.width * imgH) / canvas.height
    }
    const x = (pageW - imgW) / 2
    const y = (pageH - imgH) / 2
    pdf.addImage(canvas.toDataURL('image/jpeg', 1.0), 'JPEG', x, y, imgW, imgH, undefined, 'FAST')
    return pdf.output('blob')
  } finally {
    if (exportRoot && exportRoot.parentNode) exportRoot.parentNode.removeChild(exportRoot)
  }
}

function printPdfBlob(blob) {
  const url = URL.createObjectURL(blob)
  const iframe = document.createElement('iframe')
  iframe.setAttribute('title', 'voucher-print')
  iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;opacity:0;pointer-events:none;'
  document.body.appendChild(iframe)

  const cleanup = () => {
    try { URL.revokeObjectURL(url) } catch { /* ignore */ }
    if (iframe.parentNode) iframe.parentNode.removeChild(iframe)
  }

  iframe.onload = () => {
    try {
      const win = iframe.contentWindow
      if (!win) throw new Error('print frame missing')
      const done = () => {
        cleanup()
        win.removeEventListener('afterprint', done)
      }
      win.addEventListener('afterprint', done)
      win.focus()
      win.print()
      // Fallback cleanup if afterprint never fires
      window.setTimeout(cleanup, 60_000)
    } catch {
      cleanup()
      window.open(url, '_blank')
    }
  }
  iframe.src = url
}

export async function downloadVoucherPdf(voucherNo, onProgress) {
  if (typeof onProgress === 'function') onProgress(10)
  await new Promise((r) => setTimeout(r, 400))

  const overlay = buildOverlay('Downloading PDF…')
  try {
    const blob = await buildVoucherPdfBlob(onProgress)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `Voucher-${voucherNo || 'voucher'}.pdf`
    document.body.appendChild(a)
    a.click()
    a.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 2000)
    if (typeof onProgress === 'function') onProgress(100)
  } finally {
    if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay)
  }
}

/**
 * Print the exact same landscape PDF as Download PDF.
 */
export async function printVoucher() {
  const overlay = buildOverlay('Preparing print…')
  try {
    const blob = await buildVoucherPdfBlob()
    printPdfBlob(blob)
  } catch {
    // Last resort: browser print of the on-screen voucher
    window.print()
  } finally {
    if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay)
  }
}

if (typeof window !== 'undefined') {
  window.printVoucher = printVoucher
  window.downloadVoucherPdf = downloadVoucherPdf
}
