// Czech-language duration formatter used in both client (AttendanceView) and
// admin (AdminClientEditView) UIs. Returns null only for invalid input (null,
// NaN, negative) so callers can branch on null to decide whether to render
// anything at all. A legitimate zero — most commonly a sub-threshold cleaning
// rounded down to "0 min" by the IČO's rounding rules — renders as "0 min"
// rather than disappearing into a trailing-dot placeholder.
export function formatDurationCs(minutes) {
  if (!Number.isFinite(minutes) || minutes < 0) return null
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h === 0) return `${m} min`
  if (m === 0) return `${h} h`
  return `${h} h ${m} min`
}
