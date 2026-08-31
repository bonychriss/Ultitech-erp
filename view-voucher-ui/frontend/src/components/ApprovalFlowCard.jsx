import { fmtDateTime, waLink } from '../utils/format.js'

function StageIcon({ done, active }) {
  if (done) return <i className="fas fa-check" />
  if (active) return <i className="fas fa-clock" />
  return <i className="fas fa-user" />
}

function Avatar({ name, photoUrl }) {
  if (photoUrl) {
    return <img src={photoUrl} alt={name || 'User'} className="approval-avatar" style={{ width: 42, height: 42, borderRadius: '50%', objectFit: 'cover' }} />
  }
  const initial = (name || 'U').charAt(0).toUpperCase()
  return (
    <div className="approval-avatar approval-avatar--fallback" style={{ width: 42, height: 42, borderRadius: '50%', background: '#e2e8f0', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 600, color: '#475569' }}>
      {initial}
    </div>
  )
}

export default function ApprovalFlowCard({ data }) {
  const { approvalStages, approvalSummary } = data
  let foundActive = false

  return (
    <section className="vv-card vv-card-approval approval-card no-print">
      <div className="approval-header vv-card-head">
        <h2 className="vv-card-title">Approval Flow</h2>
        <span className={`approval-badge vv-approval-summary ${approvalSummary.className}`}>
          <i className="fas fa-calendar-check" aria-hidden="true" />
          {approvalSummary.done}/{approvalSummary.total} Completed
        </span>
      </div>
      <div className="vv-card-body">
        <div className="approval-timeline approval-stepper">
          {approvalStages.map((st) => {
            const isDone = st.status === 'approved'
            const isActive = !isDone && !foundActive
            if (isActive) foundActive = true
            const cls = isDone ? 'completed' : (isActive ? 'active' : 'pending')
            const link = waLink(st.whatsappPhone)
            return (
              <div key={`${st.role}-${st.id}`} className={`approval-step ${cls}`}>
                <div className={`timeline-dot ${cls}`} aria-hidden="true">
                  <StageIcon done={isDone} active={isActive} />
                </div>
                <Avatar name={st.approver_name || st.role} photoUrl={st.photoUrl} />
                <div className="approval-info">
                  <div className="approval-role">{st.role}</div>
                  <div className="approval-name">
                    {st.approver_name ? (
                      <>
                        {link && (
                          <a href={link} target="_blank" rel="noopener noreferrer" title="Chat on WhatsApp" style={{ marginRight: 5, textDecoration: 'none' }}>
                            <svg viewBox="0 0 24 24" className="no-print" width="16" height="16" fill="#25D366" style={{ verticalAlign: 'middle' }} aria-hidden="true">
                              <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.598 2.654-.698c1.02.595 1.95.838 2.806.838 3.193 0 5.764-2.586 5.763-5.766.001-3.18-2.587-5.765-5.763-5.765zm8.568 2.05c-.6-1.55-1.52-2.91-2.73-4.04s-2.61-1.99-4.22-2.48c-4.9-1.48-10.05.65-12.44 5.15-.3.56-.54 1.15-.72 1.76-.75 2.54-.31 5.3 1.2 7.7l-1.68 6.13a1 1 0 0 0 1.22 1.22l6.13-1.68c2.4 1.51 5.16 1.95 7.7 1.2 4.5-1.32 7.56-5.58 7.33-10.3-.06-1.63-.61-3.18-1.79-4.66z" />
                            </svg>
                          </a>
                        )}
                        {st.approver_name}
                      </>
                    ) : (
                      <span className="approval-name-pending">Pending</span>
                    )}
                  </div>
                </div>
                <div className="approval-meta">
                  <div className={`approval-date${isDone ? '' : ' approval-date--pending'}`}>
                    {isDone && st.approved_at ? <span>{fmtDateTime(st.approved_at)}</span> : <span>Pending</span>}
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      </div>
    </section>
  )
}
