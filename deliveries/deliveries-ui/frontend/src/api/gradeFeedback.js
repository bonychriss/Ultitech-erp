import { CFG } from '../config.js'

async function parseJson(response) {
  const text = await response.text()
  try {
    return JSON.parse(text)
  } catch {
    throw new Error('Invalid server response.')
  }
}

export function resolveGradeFeedbackUrl() {
  return CFG.gradeFeedbackUrl || ''
}

/**
 * @param {Array<{id:number,feedback?:string,rating?:number}>} reviews
 * @returns {Promise<{gradesById: Record<number, object>, viaAi: boolean, note?: string}>}
 */
export async function fetchFeedbackGrades(reviews) {
  const url = resolveGradeFeedbackUrl()
  if (!url || !Array.isArray(reviews) || reviews.length === 0) {
    return { gradesById: {}, viaAi: false }
  }

  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      reviews: reviews.map((row) => ({
        id: row.id,
        feedback: row.feedback || '',
        rating: row.rating || 0,
      })),
    }),
  })

  const data = await parseJson(res)
  if (!data?.ok) {
    throw new Error(data?.error || 'Feedback grading failed.')
  }

  const gradesById = {}
  for (const grade of data.grades || []) {
    if (grade?.id != null) {
      gradesById[Number(grade.id)] = grade
    }
  }

  return {
    gradesById,
    viaAi: Boolean(data.viaAi),
    note: data.note || '',
  }
}

export function gradeTier(letter = '') {
  const value = String(letter).toUpperCase()
  if (value.startsWith('A')) return 'excellent'
  if (value.startsWith('B')) return 'good'
  if (value.startsWith('C')) return 'fair'
  if (value === 'D') return 'weak'
  if (value === 'F') return 'poor'
  return 'neutral'
}
