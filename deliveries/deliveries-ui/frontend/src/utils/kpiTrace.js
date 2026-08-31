export function resolveKpiTrace(key, data) {
  const traces = data?.kpiTraces || {}
  const trace = traces[key]
  if (!trace) return null
  return {
    ...trace,
    items: Array.isArray(trace.items) ? trace.items : [],
    criteria: Array.isArray(trace.criteria) ? trace.criteria : [],
  }
}
