export async function downloadVoucherPdf(voucherNo, onProgress) {
  const element = document.getElementById('voucherFull')
  if (!element) throw new Error('Voucher preview not found')

  if (typeof onProgress === 'function') onProgress(10)
  await new Promise((r) => setTimeout(r, 800))

  const h2c = typeof window.html2canvas === 'function' ? window.html2canvas : null
  const JsPdfCtor = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : (window.jsPDF || null)

  const inlineLogos = async (root) => {
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

  const buildPdfExportRoot = () => {
    const root = document.createElement('div')
    root.className = 'pdf-export'
    root.style.cssText = 'position:fixed;left:0;top:0;width:1120px;background:#fff;z-index:1;pointer-events:none;'
    const header = element.querySelector('.pv-header')
    if (header) root.appendChild(header.cloneNode(true))
    const tablesWrap = element.querySelector('.tables-wrap')
    if (tablesWrap) {
      Array.from(tablesWrap.children)
        .filter((node) => node.tagName === 'TABLE')
        .slice(0, 3)
        .forEach((tbl) => root.appendChild(tbl.cloneNode(true)))
    }
    document.body.appendChild(root)
    if (!root.querySelector('table')) throw new Error('PDF export tables not found')
    return root
  }

  const buildOverlay = () => {
    const styleEl = document.createElement('style')
    styleEl.textContent = '@keyframes vvPdfSpin{to{transform:rotate(360deg)}}@keyframes vvPdfBar{0%{transform:translateX(-100%)}100%{transform:translateX(250%)}}'
    const ov = document.createElement('div')
    ov.setAttribute('data-pdf-overlay', '1')
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:#0b141a;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;color:#e9edef;font:600 16px system-ui,sans-serif;'
    ov.innerHTML = '<div style="width:56px;height:56px;border-radius:50%;border:4px solid #2a3942;border-top-color:#00a884;animation:vvPdfSpin .9s linear infinite"></div>'
      + '<div>Downloading PDF…</div>'
      + '<div style="width:220px;height:4px;border-radius:4px;background:#2a3942;overflow:hidden"><div style="width:40%;height:100%;background:#00a884;animation:vvPdfBar 1.2s ease-in-out infinite"></div></div>'
    ov.appendChild(styleEl)
    document.body.appendChild(ov)
    return ov
  }

  let exportRoot = null
  let overlay = null
  let restoreTheme = null
  try {
    overlay = buildOverlay()
    exportRoot = buildPdfExportRoot()
    await inlineLogos(exportRoot)
    if (typeof onProgress === 'function') onProgress(45)

    let canvas = null
    if (h2c) {
      canvas = await h2c(exportRoot, {
        scale: 3,
        useCORS: true,
        letterRendering: true,
        windowWidth: 1120,
        windowHeight: Math.max(900, exportRoot.scrollHeight + 40),
        backgroundColor: '#ffffff',
        onclone: (clonedDoc) => {
          // Exports are always white-theme, regardless of the on-screen theme.
          clonedDoc.documentElement.setAttribute('data-theme', 'light')
        },
      })
    }

    if (typeof onProgress === 'function') onProgress(85)

    if (canvas && JsPdfCtor) {
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
      pdf.save(`Voucher-${voucherNo}.pdf`)
      if (typeof onProgress === 'function') onProgress(100)
      return
    }

    if (typeof window.html2pdf === 'function') {
      const prevTheme = document.documentElement.getAttribute('data-theme')
      document.documentElement.setAttribute('data-theme', 'light')
      restoreTheme = () => {
        if (prevTheme === null) document.documentElement.removeAttribute('data-theme')
        else document.documentElement.setAttribute('data-theme', prevTheme)
      }
      await window.html2pdf().set({
        margin: [6, 6, 6, 6],
        filename: `Voucher-${voucherNo}.pdf`,
        image: { type: 'jpeg', quality: 1.0 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
        pagebreak: { mode: [] },
      }).from(exportRoot).save()
      if (typeof onProgress === 'function') onProgress(100)
      return
    }

    throw new Error('PDF engine not available')
  } finally {
    if (restoreTheme) restoreTheme()
    if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay)
    if (exportRoot && exportRoot.parentNode) exportRoot.parentNode.removeChild(exportRoot)
  }
}

export function printVoucher() {
  window.print()
}
