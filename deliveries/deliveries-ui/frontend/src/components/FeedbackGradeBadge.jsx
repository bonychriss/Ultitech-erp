import { Loader2, Sparkles } from 'lucide-react'
import { gradeTier } from '../api/gradeFeedback.js'

export default function FeedbackGradeBadge({ grade, loading = false, compact = false }) {
  if (loading) {
    return (
      <div className={`dlv-feedback-grade dlv-feedback-grade--loading${compact ? ' dlv-feedback-grade--compact' : ''}`}>
        <Loader2 size={12} className="dlv-spin" aria-hidden="true" />
        <span>Grading...</span>
      </div>
    )
  }

  if (!grade) return null

  const tier = gradeTier(grade.letter)

  return (
    <div
      className={`dlv-feedback-grade dlv-feedback-grade--${tier}${compact ? ' dlv-feedback-grade--compact' : ''}`}
      title={grade.note || grade.label || 'AI feedback grade'}
    >
      <Sparkles size={12} aria-hidden="true" />
      <span className="dlv-feedback-grade-mark">{grade.letter || '-'}</span>
      <span className="dlv-feedback-grade-score">{grade.score}/100</span>
      {!compact && grade.label ? (
        <span className="dlv-feedback-grade-label">{grade.label}</span>
      ) : null}
    </div>
  )
}
