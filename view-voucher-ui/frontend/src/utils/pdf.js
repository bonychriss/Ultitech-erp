export async function downloadVoucherPdf(voucherNo, onProgress) {
  const element = document.getElementById('voucherFull')
  if (!element) throw new Error('Voucher preview not found')

  if (typeof onProgress === 'function') onProgress(10)
  await new Promise((r) => setTimeout(r, 800))

  const h2c = typeof window.html2canvas === 'function' ? window.html2canvas : null
  const JsPdfCtor = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : (window.jsPDF || null)

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

  let exportRoot = null
  try {
    exportRoot = buildPdfExportRoot()
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
    if (exportRoot && exportRoot.parentNode) exportRoot.parentNode.removeChild(exportRoot)
  }
}

export function printVoucher() {
  window.print()
}
