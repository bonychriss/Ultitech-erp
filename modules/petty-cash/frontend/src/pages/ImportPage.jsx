import { useEffect, useState } from 'react'
import { ArrowLeft, Download, Loader2, Upload } from 'lucide-react'
import { booksUrl, deskUrl, fetchBooks, importSpreadsheet } from '../api/cashbook.js'

function downloadTemplate() {
  const csv = [
    'Date,Notes,Cash In,Cash Out,Balance',
    ',Previous Balance,,,0',
    '08-Jul-2026,petty cash,"1,000,000",0,"1,000,000"',
    '08-Jul-2026,wages to charity,0,"200,000","800,000"',
    '09-Jul-2026,water for office,0,"7,000","793,000"',
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'cashbook-import-template.csv'
  a.click()
  URL.revokeObjectURL(url)
}

export default function ImportPage() {
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [books, setBooks] = useState([])
  const [bookId, setBookId] = useState('')
  const [file, setFile] = useState(null)
  const [saving, setSaving] = useState(false)
  const [result, setResult] = useState(null)

  useEffect(() => {
    let cancelled = false
    ;(async () => {
      setLoading(true)
      setError('')
      try {
        const data = await fetchBooks('active')
        if (cancelled) return
        const list = data.books || []
        setBooks(list)
        if (list.length === 1) setBookId(String(list[0].id))
      } catch (e) {
        if (!cancelled) setError(e.message || 'Failed to load cash books')
      } finally {
        if (!cancelled) setLoading(false)
      }
    })()
    return () => {
      cancelled = true
    }
  }, [])

  async function onSubmit(e) {
    e.preventDefault()
    setError('')
    setResult(null)
    if (!bookId) {
      setError('Select a cash book.')
      return
    }
    if (!file) {
      setError('Choose an Excel (.xlsx) or CSV file.')
      return
    }
    setSaving(true)
    try {
      const data = await importSpreadsheet(Number(bookId), file)
      setResult(data.result || null)
      setFile(null)
      const input = document.getElementById('cb-import-file')
      if (input) input.value = ''
    } catch (err) {
      setError(err.message || 'Import failed')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="cb-page">
      <div className="cb-toolbar">
        <a className="cb-back-link" href={booksUrl()}>
          <ArrowLeft size={16} /> All books
        </a>
        <button type="button" className="cb-btn cb-btn-pill cb-btn-sm" onClick={downloadTemplate}>
          <Download size={14} /> Template
        </button>
      </div>

      {error ? <div className="cb-error">{error}</div> : null}

      {loading ? (
        <div className="cb-loading">
          <Loader2 className="cb-spin" size={20} /> Loading...
        </div>
      ) : (
        <div className="cb-split">
          <section className="cb-split-card">
            <div className="cb-split-card-head">
              <h3>Import Excel</h3>
            </div>
            <form className="cb-create-form" onSubmit={onSubmit}>
              <div className="cb-field">
                <label>Cash book</label>
                <select
                  className="cb-select"
                  required
                  value={bookId}
                  onChange={(e) => setBookId(e.target.value)}
                >
                  <option value="">Select book</option>
                  {books.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="cb-field">
                <label>Excel / CSV file</label>
                <input
                  id="cb-import-file"
                  className="cb-input"
                  type="file"
                  accept=".xlsx,.csv,.txt"
                  onChange={(e) => setFile(e.target.files?.[0] || null)}
                />
              </div>
              <p className="cb-book-meta" style={{ marginBottom: '0.85rem' }}>
                Use the Cash Book Excel format: Date, Notes, Cash In, Cash Out, Balance
              </p>
              <div className="cb-create-actions">
                <button type="submit" className="cb-btn cb-btn-primary cb-btn-pill cb-btn-sm" disabled={saving}>
                  <Upload size={14} /> {saving ? 'Importing...' : 'Import'}
                </button>
              </div>
            </form>
          </section>

          <section className="cb-split-card">
            <div className="cb-split-card-head">
              <h3>Result</h3>
            </div>
            {!result ? (
              <div className="cb-empty cb-empty-compact">
                Upload a spreadsheet to import cash in and cash out rows.
                {bookId ? (
                  <>
                    {' '}
                    <a href={deskUrl('book', { id: bookId })}>Open selected book</a>
                  </>
                ) : null}
              </div>
            ) : (
              <div className="cb-create-form">
                <p>
                  <strong>{result.imported}</strong> imported, <strong>{result.skipped}</strong> skipped.
                </p>
                {(result.errors || []).length > 0 ? (
                  <ul className="cb-import-errors">
                    {result.errors.map((err, i) => (
                      <li key={i}>{err}</li>
                    ))}
                  </ul>
                ) : (
                  <p className="cb-book-meta">No row errors.</p>
                )}
                {bookId ? (
                  <a className="cb-btn cb-btn-pill cb-btn-sm" href={deskUrl('book', { id: bookId })}>
                    View book
                  </a>
                ) : null}
              </div>
            )}
          </section>
        </div>
      )}
    </div>
  )
}
