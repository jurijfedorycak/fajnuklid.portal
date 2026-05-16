// Czech-language duration formatter used in both client (AttendanceView) and
// admin (AdminClientEditView) UIs. Returns null for non-positive input so
// callers can simply branch on the result instead of rendering "0 min" placeholders.
export function formatDurationCs(minutes) {
  if (!Number.isFinite(minutes) || minutes <= 0) return null
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h === 0) return `${m} min`
  if (m === 0) return `${h} h`
  return `${h} h ${m} min`
}
