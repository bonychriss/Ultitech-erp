import { useCallback, useState } from 'react'
import { exportUrl } from '../config.js'
import { downloadReportExport, exportStatusLabel } from './downloadReportExport.js'

export function useReportExport() {
  const [exporting, setExporting] = useState(false)
  const [exportMessage, setExportMessage] = useState('Downloading...')

  const runExport = useCallback(async (reportId, format) => {
    if (!reportId || exporting) return

    setExporting(true)
    setExportMessage(exportStatusLabel(format))

    try {
      await downloadReportExport(exportUrl(reportId, format), {
        format,
        onStatus: setExportMessage,
      })
    } catch {
      setExportMessage('Download failed. Please try again.')
      await new Promise((resolve) => window.setTimeout(resolve, 1400))
    } finally {
      setExporting(false)
      setExportMessage('Downloading...')
    }
  }, [exporting])

  return { exporting, exportMessage, runExport }
}
