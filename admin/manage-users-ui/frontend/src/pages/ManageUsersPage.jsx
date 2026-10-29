import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  AlertCircle,
  Building2,
  Check,
  Info,
  ChevronLeft,
  ChevronRight,
  Copy,
  Eye,
  EyeOff,
  FileText,
  KeyRound,
  Sparkles,
  User,
  X,
  MoreVertical,
  Search,
  Shield,
  Tag,
  Trash2,
  UserCheck,
  UserPlus,
  UserX,
  Users,
} from 'lucide-react'

function getCfg() {
  return window.__MANAGE_USERS_CFG__ || {}
}

function initials(name) {
  const n = String(name || '').trim()
  if (!n) return '?'
  const parts = n.split(/\s+/).filter(Boolean)
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  }
  return n.slice(0, 2).toUpperCase()
}

function formatJoined(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const yyyy = d.getFullYear()
  return `${dd}/${mm}/${yyyy}`
}

function formatMoney(n) {
  return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function generatePassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$'
  let pwd = ''
  for (let i = 0; i < 12; i++) {
    pwd += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  return pwd
}

function postAction(formAction, fields) {
  const form = document.createElement('form')
  form.method = 'POST'
  form.action = formAction
  Object.entries(fields).forEach(([name, value]) => {
    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = name
    input.value = String(value)
    form.appendChild(input)
  })
  document.body.appendChild(form)
  form.submit()
}

function copyText(text, onDone) {
  if (!text) return
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(text).then(onDone).catch(() => {
      try {
        document.execCommand('copy')
        onDone()
      } catch {
        /* ignore */
      }
    })
  } else {
    try {
      document.execCommand('copy')
      onDone()
    } catch {
      /* ignore */
    }
  }
}

function Overlay({ open, onClose, children, wide, className = '' }) {
  if (!open) return null
  return (
    <div className="mu-overlay is-open" role="dialog" onClick={onClose}>
      <div
        className={`mu-panel${wide ? ' mu-panel-wide' : ''}${className ? ` ${className}` : ''}`}
        onClick={(e) => e.stopPropagation()}
      >
        {children}
      </div>
    </div>
  )
}

export default function ManageUsersPage() {
  const cfg = getCfg()
  const initial = cfg.initial || {}
  const formAction = initial.formAction || 'manage-users.php'
  const currentUserId = initial.currentUserId || 0
  const departments = initial.departments || ['Procurement', 'IT', 'Finance', 'Sales', 'Driver']
  const accessRoleOptions = initial.accessRoleOptions || [...departments, 'Warehouse', 'Store']

  const [search, setSearch] = useState('')
  const [filterRole, setFilterRole] = useState('all')
  const [filterStatus, setFilterStatus] = useState('all')
  const [perPage, setPerPage] = useState(0)
  const [page, setPage] = useState(1)
  const [openMenuId, setOpenMenuId] = useState(null)

  useEffect(() => {
    if (openMenuId === null) return undefined
    const onDown = (e) => {
      if (!e.target.closest || !e.target.closest('.actions-dropdown')) setOpenMenuId(null)
    }
    const onKey = (e) => {
      if (e.key === 'Escape') setOpenMenuId(null)
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [openMenuId])

  const [registerOpen, setRegisterOpen] = useState(false)
  const [passwordOpen, setPasswordOpen] = useState(false)
  const [passwordUser, setPasswordUser] = useState(null)
  const [deptOpen, setDeptOpen] = useState(false)
  const [deptUser, setDeptUser] = useState(null)
  const [rolesOpen, setRolesOpen] = useState(false)
  const [rolesUser, setRolesUser] = useState(null)
  const [rolesSelected, setRolesSelected] = useState([])
  const [bulkOpen, setBulkOpen] = useState(false)

  const users = initial.users || []
  const stats = initial.stats || { total: users.length, activeAdmins: 0, activeEmployees: 0, departments: 0 }

  const filtered = useMemo(() => {
    const q = search.toLowerCase().trim()
    return users.filter((u) => {
      const blob = `${u.full_name} ${u.username} ${u.email} ${u.department} ${(u.extra_roles || []).join(' ')} ${(u.access_roles || []).join(' ')}`.toLowerCase()
      const matchQ = !q || blob.includes(q)
      const matchRole = filterRole === 'all' || u.role === filterRole
      const matchStatus = filterStatus === 'all' || String(u.is_active) === filterStatus
      return matchQ && matchRole && matchStatus
    })
  }, [users, search, filterRole, filterStatus])

  const pageSize = perPage > 0 ? perPage : Math.max(filtered.length, 1)
  const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize))
  const safePage = Math.min(page, totalPages)
  const pageSlice = filtered.slice((safePage - 1) * pageSize, safePage * pageSize)

  useEffect(() => {
    setPage(1)
  }, [search, filterRole, filterStatus, perPage])

  const [toast, setToast] = useState(null)
  const toastTimers = useRef([])
  const dismissToast = useCallback(() => {
    toastTimers.current.forEach(clearTimeout)
    toastTimers.current = []
    setToast((t) => (t ? { ...t, shown: false } : t))
    toastTimers.current.push(setTimeout(() => setToast(null), 250))
  }, [])
  const showToast = useCallback((type, text) => {
    toastTimers.current.forEach(clearTimeout)
    toastTimers.current = []
    const kind = type === 'error' ? 'error' : type === 'success' ? 'success' : 'info'
    setToast({ type: kind, text, shown: false, key: Date.now() })
    toastTimers.current.push(setTimeout(() => setToast((t) => (t ? { ...t, shown: true } : t)), 20))
    toastTimers.current.push(setTimeout(() => {
      setToast((t) => (t ? { ...t, shown: false } : t))
      toastTimers.current.push(setTimeout(() => setToast(null), 250))
    }, 3000))
  }, [])
  useEffect(() => () => toastTimers.current.forEach(clearTimeout), [])

  useEffect(() => {
    if (initial.success) showToast('success', initial.success)
    if (initial.error) showToast('error', initial.error)
  }, [initial.success, initial.error, showToast])

  useEffect(() => {
    const Swal = window.Swal
    if (!Swal) return
    const bulk = initial.bulkPwFlash
    if (bulk?.password) {
      Swal.fire({
        title: 'Shared password applied',
        html: `<p style="margin:0 0 12px;font-size:14px;color:#475569;">${bulk.count || 0} user(s) now use this password. Emails were <strong>not</strong> changed.</p>`
          + `<input type="text" id="bulkPwFlashCopy" readonly value="${String(bulk.password).replace(/"/g, '&quot;')}" `
          + 'style="width:100%;padding:12px;font-size:16px;font-weight:700;letter-spacing:0.05em;border:2px solid #e2e8f0;border-radius:8px;text-align:center;">',
        icon: 'success',
        confirmButtonText: 'Copy password',
        showCancelButton: true,
        cancelButtonText: 'Close',
      }).then((result) => {
        if (result.isConfirmed) {
          const inp = document.getElementById('bulkPwFlashCopy')
          if (inp) {
            inp.select()
            copyText(inp.value, () => showToast('success', 'Copied'))
          }
        }
      })
    }
  }, [initial.bulkPwFlash, showToast])

  const confirmAction = (opts, onYes) => {
    const Swal = window.Swal
    if (!Swal) {
      if (window.confirm(opts.text || opts.title)) onYes()
      return
    }
    Swal.fire({
      title: opts.title,
      text: opts.text,
      icon: opts.icon || 'question',
      showCancelButton: true,
      confirmButtonColor: opts.color || '#2563eb',
      cancelButtonColor: '#64748b',
      confirmButtonText: opts.confirmText || 'Yes, proceed',
      cancelButtonText: 'Cancel',
    }).then((r) => {
      if (r.isConfirmed) onYes()
    })
  }

  const bulkBase = initial.bulkResetEligibleCount || 0
  const bulkHasAdmin = initial.bulkHasSystemAdmin
  const [bulkIncludeSelf, setBulkIncludeSelf] = useState(false)
  const [bulkIncludeAdmin, setBulkIncludeAdmin] = useState(false)
  const bulkCount =
    bulkBase + (bulkIncludeSelf ? 1 : 0) + (bulkIncludeAdmin && bulkHasAdmin ? 1 : 0)

  const pwNewRef = useRef(null)
  const pwCopyRef = useRef(null)

  return (
    <div className="mu-desk">
      {toast ? (
        <div
          key={toast.key}
          className={`mu-toast mu-toast--${toast.type}${toast.shown ? ' is-in' : ''}`}
          role="status"
          aria-live="polite"
        >
          <i aria-hidden="true">
            {toast.type === 'error' ? <AlertCircle size={15} /> : toast.type === 'info' ? <Info size={15} /> : <Check size={15} strokeWidth={3} />}
          </i>
          <span>{toast.text}</span>
          <button type="button" className="mu-toast-close" aria-label="Dismiss" onClick={dismissToast}>&times;</button>
        </div>
      ) : null}
      <header className="mu-page-header">
        <div>
          <h1>User Management</h1>
          <p>Manage system access and roles for all staff members</p>
        </div>
        <div className="mu-header-actions">
          <button type="button" className="mu-btn-outline" onClick={() => setBulkOpen(true)}>
            <KeyRound size={13} aria-hidden />
            Reset all passwords
          </button>
          <button type="button" className="mu-btn-register" onClick={() => setRegisterOpen(true)}>
            <UserPlus size={13} aria-hidden />
            Register New Employee
          </button>
        </div>
      </header>

      <div className="mu-card">
        <div className="mu-toolbar">
          <div className="mu-search">
            <Search size={14} aria-hidden />
            <input
              type="text"
              placeholder="Search users by name, email or department..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <div className="mu-filters">
            <select value={filterRole} onChange={(e) => setFilterRole(e.target.value)}>
              <option value="all">All Roles</option>
              <option value="admin">Admins Only</option>
              <option value="employee">Employees Only</option>
            </select>
            <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
              <option value="all">All Status</option>
              <option value="1">Active Only</option>
              <option value="0">Inactive Only</option>
            </select>
          </div>
        </div>

      </div>
      <div className="mu-stats">
        <StatCard icon={<Users size={15} />} tone="purple" value={stats.total} label={`${stats.total} Total Users`} />
        <StatCard icon={<Shield size={15} />} tone="green" value={stats.activeAdmins} label={`${stats.activeAdmins} Active Admins`} />
        <StatCard icon={<Users size={15} />} tone="blue" value={stats.activeEmployees} label={`${stats.activeEmployees} Active Employees`} />
        <StatCard icon={<Building2 size={15} />} tone="orange" value={stats.departments} label={`${stats.departments} Departments`} />
      </div>


      <div className="mu-results-head">
        <span className="mu-results-count">
          {filtered.length === 0
            ? `0 results`
            : `Showing ${(safePage - 1) * pageSize + 1}–${Math.min(safePage * pageSize, filtered.length)} of ${filtered.length}${filtered.length < users.length ? ` (filtered from ${users.length})` : ''}`}
        </span>
        <label className="mu-per-page">
          <select value={perPage} onChange={(e) => setPerPage(Number(e.target.value))}>
            <option value={0}>All users</option>
            <option value={10}>10 per page</option>
            <option value={25}>25 per page</option>
            <option value={50}>50 per page</option>
            <option value={100}>100 per page</option>
          </select>
        </label>
      </div>

      <div className="mu-card mu-card--table">
        <div className="mu-table-wrap">
          <table className="mu-table">
            <thead>
              <tr>
                <th>User</th>
                <th>Email</th>
                <th>Department</th>
                <th>Role</th>
                <th>Status</th>
                <th>Engagement</th>
                <th>Joined</th>
                <th style={{ textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {pageSlice.map((u, rowIndex) => {
                const menuUp = pageSlice.length > 3 && rowIndex >= pageSlice.length - 2
                const isAdmin = u.role === 'admin'
                const avatarClass = `c${u.id % 5}`
                const canAct = u.id !== currentUserId
                return (
                  <tr key={u.id}>
                    <td>
                      <div className="mu-user-cell">
                        <div className={`mu-avatar ${avatarClass}`}>{initials(u.full_name || u.username)}</div>
                        <div style={{ minWidth: 0 }}>
                          <span className="mu-user-name">{u.full_name || u.username}</span>
                          <div className="mu-user-code">@{u.username}</div>
                        </div>
                      </div>
                    </td>
                    <td className="mu-email">{u.email}</td>
                    <td>
                      {!isAdmin ? (
                        <div className="mu-dept-cell">
                          <button
                            type="button"
                            className="mu-link"
                            onClick={() => {
                              setDeptUser(u)
                              setDeptOpen(true)
                            }}
                          >
                            {u.department || '-'}
                          </button>
                          {(u.extra_roles || []).length > 0 && (
                            <div className="mu-extra-roles">
                              {(u.extra_roles || []).map((r) => (
                                <span key={r} className="mu-pill mu-pill-role-extra">{r}</span>
                              ))}
                            </div>
                          )}
                        </div>
                      ) : (
                        <span className="mu-muted">{u.department || '-'}</span>
                      )}
                    </td>
                    <td>
                      <span className={`mu-pill ${isAdmin ? 'role-admin' : 'role-employee'}`}>
                        {u.role.charAt(0).toUpperCase() + u.role.slice(1)}
                      </span>
                    </td>
                    <td>
                      <span className={`mu-pill ${u.is_active ? 'status-active' : 'status-inactive'}`}>
                        {u.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                    <td className="mu-engagement">
                      <strong>{u.voucher_count} Vouchers</strong>
                      <span>TZS {formatMoney(u.approved_amount)}</span>
                    </td>
                    <td className="mu-joined">{formatJoined(u.created_at)}</td>
                    <td style={{ textAlign: 'right' }}>
                      {canAct ? (
                        <div className="mu-actions">
                          <div className="actions-dropdown">
                            <button
                              type="button"
                              className="mu-icon-btn mu-icon-btn--more"
                              title="Actions"
                              aria-label="Actions"
                              aria-haspopup="menu"
                              aria-expanded={openMenuId === u.id}
                              onClick={() => setOpenMenuId(openMenuId === u.id ? null : u.id)}
                            >
                              <MoreVertical size={16} aria-hidden />
                            </button>
                            {openMenuId === u.id && (
                              <div className={`actions-dropdown-menu show${menuUp ? ' is-up' : ''}`} role="menu">
                                {!isAdmin && (
                                  <>
                                    <button
                                      type="button"
                                      role="menuitem"
                                      className="actions-dropdown-item"
                                      onClick={() => {
                                        setDeptUser(u)
                                        setDeptOpen(true)
                                        setOpenMenuId(null)
                                      }}
                                    >
                                      <Building2 size={16} className="mu-icon-btn--dept" aria-hidden />
                                      Change department
                                    </button>
                                    <button
                                      type="button"
                                      role="menuitem"
                                      className="actions-dropdown-item"
                                      onClick={() => {
                                        setRolesUser(u)
                                        setRolesSelected([...(u.extra_roles || [])])
                                        setRolesOpen(true)
                                        setOpenMenuId(null)
                                      }}
                                    >
                                      <Tag size={16} className="mu-icon-btn--roles" aria-hidden />
                                      Manage roles
                                    </button>
                                  </>
                                )}
                                <button
                                  type="button"
                                  role="menuitem"
                                  className="actions-dropdown-item"
                                  onClick={() => {
                                    setPasswordUser(u)
                                    setPasswordOpen(true)
                                    setOpenMenuId(null)
                                  }}
                                >
                                  <KeyRound size={16} className="mu-icon-btn--key" aria-hidden />
                                  Set / reset password
                                </button>
                                {!u.is_system_admin && (
                                  <>
                                    <div className="actions-dropdown-divider" />
                                    {u.is_active ? (
                                      <button
                                        type="button"
                                        role="menuitem"
                                        className="actions-dropdown-item"
                                        onClick={() => {
                                          setOpenMenuId(null)
                                          confirmAction(
                                            {
                                              title: 'Deactivate User?',
                                              text: 'The user will no longer be able to log in.',
                                              icon: 'warning',
                                              color: '#b91c1c',
                                            },
                                            () => postAction(formAction, { action: 'deactivate', user_id: u.id }),
                                          )
                                        }}
                                      >
                                        <UserX size={16} className="mu-icon-btn--deactivate" aria-hidden />
                                        Deactivate
                                      </button>
                                    ) : (
                                      <button
                                        type="button"
                                        role="menuitem"
                                        className="actions-dropdown-item"
                                        onClick={() => {
                                          setOpenMenuId(null)
                                          confirmAction(
                                            {
                                              title: 'Activate User?',
                                              text: "This will restore the user's access to the system.",
                                              icon: 'question',
                                              color: '#27ae60',
                                            },
                                            () => postAction(formAction, { action: 'activate', user_id: u.id }),
                                          )
                                        }}
                                      >
                                        <UserCheck size={16} className="mu-icon-btn--activate" aria-hidden />
                                        Activate
                                      </button>
                                    )}
                                    <button
                                      type="button"
                                      role="menuitem"
                                      className="actions-dropdown-item text-danger"
                                      onClick={() => {
                                        setOpenMenuId(null)
                                        let text = `Are you sure you want to permanently delete user "${u.full_name}"?`
                                        if (u.voucher_count > 0) {
                                          text += `\n\nWarning: This user has ${u.voucher_count} voucher(s) associated. They will be reassigned to the system admin.`
                                        }
                                        text += '\n\nThis action cannot be undone.'
                                        confirmAction(
                                          {
                                            title: 'Delete Permanently?',
                                            text,
                                            icon: 'error',
                                            color: '#dc2626',
                                            confirmText: 'Yes, Delete User',
                                          },
                                          () => postAction(formAction, { action: 'delete', user_id: u.id }),
                                        )
                                      }}
                                    >
                                      <Trash2 size={16} className="mu-icon-btn--del" aria-hidden />
                                      Delete user
                                    </button>
                                  </>
                                )}
                              </div>
                            )}
                          </div>
                        </div>
                      ) : (
                        <span className="mu-muted">You</span>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {perPage > 0 && totalPages > 1 ? (
          <nav className="mu-pagination" aria-label="User pages">
            <button
              type="button"
              className="mu-page-btn"
              disabled={safePage <= 1}
              onClick={() => setPage(safePage - 1)}
            >
              <ChevronLeft size={16} aria-hidden />
              Prev
            </button>
            <span className="mu-page-status">
              Page {safePage} of {totalPages}
            </span>
            <button
              type="button"
              className="mu-page-btn"
              disabled={safePage >= totalPages}
              onClick={() => setPage(safePage + 1)}
            >
              Next
              <ChevronRight size={16} aria-hidden />
            </button>
          </nav>
        ) : null}
      </div>

      <Overlay open={registerOpen} onClose={() => setRegisterOpen(false)}>
        <ModalHead title="Register New Employee" onClose={() => setRegisterOpen(false)} />
        <form method="POST" action={formAction}>
          <input type="hidden" name="action" value="register" />
          <FormField label="Full Name (used as username)" name="full_name" required placeholder="e.g. John Doe" />
          <FormField label="Email Address" name="email" type="email" required placeholder="john@example.com" />
          <label className="mu-form-label">Department</label>
          <select name="department" className="mu-input" required defaultValue="">
            <option value="" disabled>Select department</option>
            {departments.map((d) => (
              <option key={d} value={d}>{d}</option>
            ))}
          </select>
          <PasswordPair prefix="reg" fieldNames={{ a: 'password', b: 'confirm_password' }} />
          <ModalActions onCancel={() => setRegisterOpen(false)} submitLabel="Register Employee" />
        </form>
      </Overlay>

      <Overlay open={passwordOpen} onClose={() => setPasswordOpen(false)} className="mu-panel-pw">
        <div className="mu-pw-head">
          <span className="mu-pw-head-icon" aria-hidden>
            <User size={22} fill="currentColor" strokeWidth={1.5} />
          </span>
          <div className="mu-pw-head-text">
            <h3>Set user password</h3>
            <p>User: {passwordUser?.full_name || passwordUser?.username}</p>
          </div>
          <button type="button" className="mu-pw-close" onClick={() => setPasswordOpen(false)} aria-label="Close">
            <X size={20} aria-hidden />
          </button>
        </div>
        <form method="POST" action={formAction} autoComplete="off">
          <input type="hidden" name="action" value="reset_password" />
          <input type="hidden" name="user_id" value={passwordUser?.id || ''} />
          <PasswordPair
            prefix="new"
            fieldNames={{ a: 'new_password', b: 'confirm_password' }}
            copyRef={pwCopyRef}
            inputRef={pwNewRef}
          />
          <ModalActions onCancel={() => setPasswordOpen(false)} submitLabel="Save password" />
        </form>
      </Overlay>

      <Overlay open={deptOpen} onClose={() => setDeptOpen(false)}>
        <ModalHead title="Change Department" onClose={() => setDeptOpen(false)} />
        <p className="mu-modal-sub">For user: {deptUser?.full_name}</p>
        <form method="POST" action={formAction}>
          <input type="hidden" name="user_id" value={deptUser?.id || ''} />
          <input type="hidden" name="action" value="change_department" />
          <label className="mu-form-label">Select New Department</label>
          <select name="department" className="mu-input" required defaultValue={deptUser?.department || departments[0]}>
            {departments.map((d) => (
              <option key={d} value={d}>{d}</option>
            ))}
          </select>
          <ModalActions onCancel={() => setDeptOpen(false)} submitLabel="Update" />
        </form>
      </Overlay>

      <Overlay open={rolesOpen} onClose={() => setRolesOpen(false)} wide>
        <ModalHead title="Manage Access Roles" onClose={() => setRolesOpen(false)} />
        <p className="mu-modal-sub">
          For <strong>{rolesUser?.full_name}</strong>. Primary department stays{' '}
          <strong>{rolesUser?.department || '-'}</strong>. Tick extra roles so they can also use those features
          (e.g. Driver + Warehouse).
        </p>
        <form method="POST" action={formAction}>
          <input type="hidden" name="user_id" value={rolesUser?.id || ''} />
          <input type="hidden" name="action" value="change_access_roles" />
          <div className="mu-roles-grid">
            {accessRoleOptions.map((role) => {
              const primary = String(rolesUser?.department || '').toLowerCase() === String(role).toLowerCase()
              const checked = primary || rolesSelected.some((r) => String(r).toLowerCase() === String(role).toLowerCase())
              return (
                <label key={role} className={`mu-role-chip${primary ? ' is-primary' : ''}${checked && !primary ? ' is-on' : ''}`}>
                  <input
                    type="checkbox"
                    name="access_roles[]"
                    value={role}
                    disabled={primary}
                    checked={checked}
                    onChange={(e) => {
                      if (primary) return
                      setRolesSelected((prev) => {
                        if (e.target.checked) {
                          return prev.some((r) => String(r).toLowerCase() === String(role).toLowerCase())
                            ? prev
                            : [...prev, role]
                        }
                        return prev.filter((r) => String(r).toLowerCase() !== String(role).toLowerCase())
                      })
                    }}
                  />
                  <span>{role}{primary ? ' (primary)' : ''}</span>
                </label>
              )
            })}
          </div>
          <ModalActions onCancel={() => setRolesOpen(false)} submitLabel="Save roles" />
        </form>
      </Overlay>

      <Overlay open={bulkOpen} onClose={() => setBulkOpen(false)} wide>
        <ModalHead title="Reset all user passwords" onClose={() => setBulkOpen(false)} />
        <div className="mu-warn-box">
          <strong>Warning:</strong> Sets the same login password for every selected user in this company.
          <strong> Email addresses are not changed.</strong>
        </div>
        <p className="mu-modal-sub">
          Will update <strong>{bulkCount}</strong> user(s) (excludes you and the system admin unless you check the options below).
        </p>
        <form method="POST" action={formAction} autoComplete="off">
          <input type="hidden" name="action" value="reset_all_passwords" />
          <PasswordPair prefix="bulk" fieldNames={{ a: 'bulk_password', b: 'bulk_confirm_password' }} />
          <label className="mu-check">
            <input type="checkbox" name="bulk_include_self" value="1" checked={bulkIncludeSelf} onChange={(e) => setBulkIncludeSelf(e.target.checked)} />
            Also reset my password (current admin)
          </label>
          <label className="mu-check">
            <input
              type="checkbox"
              name="bulk_include_system_admin"
              value="1"
              checked={bulkIncludeAdmin}
              onChange={(e) => setBulkIncludeAdmin(e.target.checked)}
            />
            Include system admin account
          </label>
          <label className="mu-form-label">Type <code>RESET ALL</code> to confirm</label>
          <input className="mu-input" name="bulk_confirm_phrase" required placeholder="RESET ALL" style={{ textTransform: 'uppercase' }} />
          <ModalActions onCancel={() => setBulkOpen(false)} submitLabel="Apply to all users" danger />
        </form>
      </Overlay>
    </div>
  )
}

function StatCard({ icon, tone, value, label }) {
  return (
    <div className="mu-stat-card">
      <div className={`mu-stat-icon ${tone}`}>{icon}</div>
      <div>
        <div className="mu-stat-value">{value}</div>
        <div className="mu-stat-label">{label}</div>
      </div>
    </div>
  )
}

function ModalHead({ title, onClose }) {
  return (
    <div className="mu-modal-head">
      <h3>{title}</h3>
      <button type="button" className="mu-close" onClick={onClose} aria-label="Close">&times;</button>
    </div>
  )
}

function ModalActions({ onCancel, submitLabel, danger }) {
  return (
    <div className="mu-modal-actions">
      <button type="button" className="btn-secondary" onClick={onCancel}>Cancel</button>
      <button type="submit" className={danger ? 'btn-danger' : 'btn-primary'}>{submitLabel}</button>
    </div>
  )
}

function FormField({ label, name, type = 'text', required, placeholder }) {
  return (
    <div className="mu-form-group">
      <label className="mu-form-label" htmlFor={name}>{label}</label>
      <input className="mu-input" id={name} name={name} type={type} required={required} placeholder={placeholder} />
    </div>
  )
}

function PasswordPair({ prefix, fieldNames, copyRef, inputRef }) {
  const names = fieldNames || {
    a: prefix === 'new' ? 'new_password' : `${prefix}_password`,
    b: prefix === 'new' ? 'confirm_password' : `${prefix}_confirm_password`,
  }
  const [show, setShow] = useState(false)
  const [copyVal, setCopyVal] = useState('')

  const gen = () => {
    const pwd = generatePassword()
    const a = document.getElementById(`mu_${names.a}`)
    const b = document.getElementById(`mu_${names.b}`)
    if (a) {
      a.value = pwd
      a.type = 'text'
    }
    if (b) {
      b.value = pwd
      b.type = 'text'
    }
    setCopyVal(pwd)
    setShow(true)
  }

  return (
    <>
      <div className="mu-form-group">
        <label className="mu-form-label" htmlFor={`mu_${names.a}`}>Password</label>
        <div className="mu-pw-field">
          <input
            ref={inputRef}
            className="mu-input"
            id={`mu_${names.a}`}
            name={names.a}
            type={show ? 'text' : 'password'}
            required
            minLength={8}
            autoComplete="new-password"
            onInput={(e) => setCopyVal(e.target.value)}
          />
          <div className="mu-pw-tools">
            <button type="button" className="mu-pw-gen" onClick={gen}>
              <Sparkles size={15} aria-hidden />
              Generate
            </button>
            <span className="mu-pw-sep" aria-hidden />
            <button type="button" className="mu-pw-show" onClick={() => setShow((s) => !s)}>
              {show ? <EyeOff size={15} aria-hidden /> : <Eye size={15} aria-hidden />}
              {show ? 'Hide' : 'Show'}
            </button>
          </div>
        </div>
      </div>
      <div className="mu-form-group">
        <label className="mu-form-label" htmlFor={`mu_${names.b}`}>Confirm password</label>
        <input
          className="mu-input"
          id={`mu_${names.b}`}
          name={names.b}
          type={show ? 'text' : 'password'}
          required
          minLength={8}
          autoComplete="new-password"
        />
      </div>
      {prefix !== 'bulk' && (
        <div className="mu-copy-box">
          <div className="mu-copy-head">
            <span className="mu-copy-icon" aria-hidden>
              <FileText size={17} />
            </span>
            <div>
              <div className="mu-copy-title">Copy password for employee</div>
              <div className="mu-copy-sub">Share this password with the employee securely.</div>
            </div>
          </div>
          <div className="mu-copy-row">
            <input ref={copyRef} readOnly value={copyVal} placeholder="Generate or type a password above" />
            <button
              type="button"
              className="mu-btn-copy"
              onClick={() => {
                const pwd = copyVal || document.getElementById(`mu_${names.a}`)?.value
                if (!pwd || pwd.length < 8) {
                  window.Swal?.fire({ toast: true, icon: 'warning', title: 'Min. 8 characters', timer: 2500, showConfirmButton: false })
                  return
                }
                copyText(pwd, () => window.Swal?.fire({ toast: true, icon: 'success', title: 'Password copied', timer: 2000, showConfirmButton: false }))
              }}
            >
              <Copy size={16} aria-hidden />
              Copy password
            </button>
          </div>
        </div>
      )}
    </>
  )
}

