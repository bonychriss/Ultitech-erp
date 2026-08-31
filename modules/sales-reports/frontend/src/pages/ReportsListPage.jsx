import { useCallback, useEffect, useState } from 'react'
import { CFG, apiUrl, pageUrl } from '../config.js'
import EmptyState from '../components/EmptyState.jsx'
import ReportCard from '../components/ReportCard.jsx'
import DeleteModal from '../components/DeleteModal.jsx'
import CreateReportTypeModal from '../components/CreateReportTypeModal.jsx'
import { navigateToNewReport } from '../lib/reportPeriodDefaults.js'

export default function ReportsListPage() {
  const [data, setData] = useState(CFG.reports ? CFG : null)
  const [loading, setLoading] = useState(!CFG.reports)
  const [search, setSearch] = useState(CFG.filters?.search || '')
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleteBusy, setDeleteBusy] = useState(false)
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [createDomain, setCreateDomain] = useState('')

  const loadReports = useCallback(async (nextSearch = search) => {
    setLoading(true)
    try {
      const r = await fetch(apiUrl('list.php', { search: nextSearch }))
      const j = await r.json()
      if (j.success && j.data) {
        setData(j.data)
      }
    } catch (e) {
      console.error(e)
    } finally {
      setLoading(false)
    }
  }, [search])

  useEffect(() => {
    if (!CFG.reports) {
      loadReports()
    }
    const params = new URLSearchParams(window.location.search)
    const create = params.get('create')
    if (create && (CFG.permissions?.create || data?.permissions?.create)) {
      setCreateDomain(create)
      setShowCreateModal(true)
    }
  }, [loadReports])

  function handleFilterSubmit(e) {
    e.preventDefault()
    loadReports(search)
  }

  async function handleDuplicate(id) {
    const fd = new FormData()
    fd.append('id', id)
    try {
      const r = await fetch(apiUrl('duplicate.php'), { method: 'POST', body: fd })
      const j = await r.json()
      if (j.success && j.id) {
        window.location.href = pageUrl('editor', j.id)
      } else {
        alert(j.error || 'Duplicate failed')
      }
    } catch {
      alert('Duplicate failed')
    }
  }

  async function handleDeleteConfirm() {
    if (!deleteTarget) return
    setDeleteBusy(true)
    const fd = new FormData()
    fd.append('id', deleteTarget.id)
    try {
      const r = await fetch(apiUrl('delete.php'), { method: 'POST', body: fd })
      const j = await r.json()
      if (j.success) {
        setDeleteTarget(null)
        await loadReports()
      } else {
        alert(j.error || 'Delete failed')
      }
    } catch {
      alert('Delete failed')
    } finally {
      setDeleteBusy(false)
    }
  }

  const reports = data?.reports || []
  const permissions = data?.permissions || {}

  function openCreateModal() {
    setCreateDomain('')
    setShowCreateModal(true)
  }

  function handleCreateSelect(option) {
    navigateToNewReport(option, option.defaults, CFG)
  }

  return (
    <div className="sr-react-root">
      <nav className="sr-breadcrumb">
        <a href={CFG.urls?.analytics}><i className="bi bi-arrow-left" aria-hidden="true" /> Reports</a>
        <span>/ Business Reports</span>
      </nav>

      <div className="sr-list-toolbar">
        <form className="sr-search-top" onSubmit={handleFilterSubmit}>
          <label className="sr-search-top-field">
            <i className="bi bi-search" aria-hidden="true" />
            <input
              type="search"
              className="sr-search-input"
              placeholder="Search reports..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              aria-label="Search reports"
            />
          </label>
        </form>

        {permissions.create && (
          <button type="button" className="sr-btn sr-btn-primary sr-btn-rounded sr-list-create-btn" onClick={openCreateModal}>
            + Create New Report
          </button>
        )}
      </div>

      {loading ? (
        <div className="sr-loading">Loading reports...</div>
      ) : reports.length === 0 ? (
        <EmptyState canCreate={permissions.create} onCreate={openCreateModal} />
      ) : (
        <div className="sr-report-table-wrap">
          <table className="sr-report-table">
            <colgroup>
              <col className="sr-col-report" />
              <col className="sr-col-period" />
              <col className="sr-col-creator" />
              <col className="sr-col-modified" />
              <col className="sr-col-status" />
              <col className="sr-col-actions" />
            </colgroup>
            <thead>
              <tr>
                <th>Report</th>
                <th>Period</th>
                <th>Created by</th>
                <th>Last modified</th>
                <th>Status</th>
                <th className="sr-report-table-actions-col">Actions</th>
              </tr>
            </thead>
            <tbody>
              {reports.map((report) => (
                <ReportCard
                  key={report.id}
                  report={report}
                  permissions={permissions}
                  onDuplicate={handleDuplicate}
                  onDelete={setDeleteTarget}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}

      <DeleteModal
        report={deleteTarget}
        onCancel={() => setDeleteTarget(null)}
        onConfirm={handleDeleteConfirm}
        busy={deleteBusy}
      />

      <CreateReportTypeModal
        open={showCreateModal}
        onClose={() => {
          setShowCreateModal(false)
          setCreateDomain('')
        }}
        onSelect={handleCreateSelect}
        initialDomainKey={createDomain}
      />
    </div>
  )
}
