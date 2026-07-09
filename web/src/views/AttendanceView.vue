<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  ChevronLeft, ChevronRight, ChevronDown, Loader2, Calendar as CalendarIcon,
  Clock, Phone, Mail, Eye, BarChart3, ArrowUp, ArrowDown, Minus, FolderOpen,
} from 'lucide-vue-next'
import { attendanceService, maintenanceRequestService } from '../api'
import { REQUEST_STATUSES } from '../api/services/maintenanceRequestService'
import { useAuth } from '../stores/auth'
import { formatDurationCs as formatDuration } from '../utils/duration'

const { isAdmin } = useAuth()

const PRIORITY_ORDER = ['resi_se', 'prijato', 'vyreseno']

const router = useRouter()
const route = useRoute()

// Admin preview mode: when an admin opens /dochazka?previewClientId=42 from the
// client edit page, the calendar renders exactly what that client sees (rounding
// applied, raw times stripped) so the admin can verify FreshQR settings.
const previewClientId = computed(() => {
  const raw = route.query.previewClientId
  if (raw === undefined || raw === null || raw === '') return null
  const n = Number(raw)
  return Number.isInteger(n) && n > 0 ? n : null
})
const isPreview = computed(() => previewClientId.value !== null && isAdmin.value)
const previewClientName = ref(null)

// In preview mode the admin should see the client's view, not the admin's audit
// view — so the admin-only template branches (raw start/end times alongside
// rounded duration) are suppressed even though the user is technically an admin.
const showAdminDetails = computed(() => isAdmin.value && !isPreview.value)

// State
const loading = ref(true)
const error = ref(null)
const upstreamError = ref(null)
const cleaningDays = ref([])
const hourlySummary = ref([])
const freshqrActive = ref(false)

// Tabs — the product-owner design splits the page into three views:
// Měsíc (calendar + day records), Rok (year aggregates), Přehled (period switch).
const TABS = [
  { key: 'month', label: 'Měsíc' },
  { key: 'year', label: 'Rok' },
  { key: 'overview', label: 'Přehled' },
]
const activeTab = ref('month')

const PERIOD_OPTIONS = [
  { key: 'day', label: 'Dnes', prevLabel: 'včera' },
  { key: 'week', label: 'Týden', prevLabel: 'minulý týden' },
  { key: 'month', label: 'Měsíc', prevLabel: 'minulý měsíc' },
  { key: 'quarter', label: 'Kvartál', prevLabel: 'minulé čtvrtletí' },
  { key: 'year', label: 'Rok', prevLabel: 'loni' },
]
const overviewPeriod = ref('month')

// Period-keyed summary cache — the month summary drives the delta chips on the
// Měsíc stat cards, the year summary drives the Rok tab, and the Přehled tab
// reads whatever period its switch selects. Each period is fetched at most once
// per preview target.
const summaries = ref({})
const summaryLoading = ref({})
const summaryError = ref({})

const requestsByDay = ref({})
const openPopoverDate = ref(null)
const popoverAnchorRect = ref(null)
const openHourlyBreakdownIco = ref(null)
const hourlyBreakdownAnchorRect = ref(null)

// Selected calendar day — drives the "Záznamy dne" section under the stats.
const selectedDate = ref(null)
const dayRequests = ref([])
const dayRequestsLoading = ref(false)

// Device hover capability — captured once at setup. Drives whether mouseenter
// on a day cell with detailed cleanings opens the peek popover; touch-only
// devices rely on tap-to-select and the Záznamy dne section instead.
const supportsHover = typeof window !== 'undefined'
  && window.matchMedia?.('(hover: hover) and (pointer: fine)').matches === true

let hoverOpenTimer = null
let hoverCloseTimer = null
const HOVER_OPEN_DELAY_MS = 120
const HOVER_CLOSE_DELAY_MS = 150

// `today` is a reactive ref, refreshed on visibility change and on a low-frequency
// interval, so a calendar left open past midnight stops marking yesterday as "today"
// and stops dimming today as "past". The ref holds an ISO YYYY-MM-DD string —
// cheaper to compare than constructing Date objects in every cell.
function buildTodayIso() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`
}
const _bootDate = new Date()
const today = ref(buildTodayIso())
let todayInterval = null
function refreshToday() {
  const next = buildTodayIso()
  if (next !== today.value) today.value = next
}

const viewYear  = ref(_bootDate.getFullYear())
const viewMonth = ref(_bootDate.getMonth())

const WEEKDAYS = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne']
const MONTHS = [
  'Leden','Únor','Březen','Duben','Květen','Červen',
  'Červenec','Srpen','Září','Říjen','Listopad','Prosinec',
]

const monthPrefix = computed(() => `${viewYear.value}-${String(viewMonth.value + 1).padStart(2,'0')}-`)

// Fetch data. The token discards slow responses that resolve after the user
// already navigated to a different month (or preview target) — otherwise the
// last response to *arrive* would win over the last month *requested*.
let loadToken = 0

async function fetchAttendanceData() {
  try {
    const response = await attendanceService.getAttendance(
      viewYear.value,
      viewMonth.value + 1,
      previewClientId.value
    )
    if (response.success) return { data: response.data }
    return { error: response.message || 'Nepodařilo se načíst data' }
  } catch (err) {
    return { error: err.message || 'Nepodařilo se načíst data' }
  }
}

async function fetchRequestsData() {
  // Preview mode renders only the FreshQR side of the customer view — maintenance
  // requests are scoped to the logged-in user, so calling this endpoint as an
  // admin would return the admin's own (empty) requests, not the previewed
  // client's. Skipping the call keeps the preview honest.
  if (isPreview.value) return {}
  try {
    const res = await maintenanceRequestService.getCalendar(viewYear.value, viewMonth.value + 1)
    if (res.success) {
      const map = {}
      for (const r of res.data) {
        map[r.date] = {
          total: r.total || 0,
          statuses: r.statuses || {},
        }
      }
      return map
    }
  } catch (e) {
    // silent
  }
  return {}
}

// Summary responses are only committed when the epoch they started under is
// still current — a preview-target switch or day rollover bumps the epoch, so a
// stale in-flight response can't poison the per-period cache (ensureSummary
// would otherwise treat the poisoned slot as valid forever).
let summaryEpoch = 0

async function fetchSummaryFor(period) {
  const epoch = summaryEpoch
  summaryLoading.value = { ...summaryLoading.value, [period]: true }
  summaryError.value = { ...summaryError.value, [period]: null }
  try {
    const res = await attendanceService.getSummary(period, previewClientId.value)
    if (epoch !== summaryEpoch) return
    if (res.success) {
      summaries.value = { ...summaries.value, [period]: res.data }
    } else {
      summaryError.value = { ...summaryError.value, [period]: res.message || 'Nepodařilo se načíst přehled' }
    }
  } catch (e) {
    if (epoch !== summaryEpoch) return
    summaryError.value = { ...summaryError.value, [period]: e.message || 'Nepodařilo se načíst přehled' }
  } finally {
    if (epoch === summaryEpoch) {
      summaryLoading.value = { ...summaryLoading.value, [period]: false }
    }
  }
}

function ensureSummary(period) {
  if (!summaries.value[period] && !summaryLoading.value[period]) fetchSummaryFor(period)
}

function invalidateSummaries() {
  summaryEpoch++
  summaries.value = {}
  summaryLoading.value = {}
  summaryError.value = {}
  ensureSummary('month')
  if (activeTab.value !== 'month') ensureSummary(activeSummaryPeriod.value)
}

async function loadAll() {
  const token = ++loadToken
  selectedDate.value = null
  loading.value = true
  error.value = null
  const [att, reqs] = await Promise.all([fetchAttendanceData(), fetchRequestsData()])
  if (token !== loadToken) return
  requestsByDay.value = reqs
  if (att.error) {
    error.value = att.error
    upstreamError.value = null
  } else {
    cleaningDays.value = att.data.cleaningDays || []
    hourlySummary.value = att.data.hourlySummary || []
    freshqrActive.value = att.data.freshqrActive || false
    upstreamError.value = att.data.error || null
    previewClientName.value = att.data.preview?.clientName || null
  }
  loading.value = false
  autoSelectDay()
}

onMounted(() => {
  loadAll()
  ensureSummary('month')
})

// Refetch when month changes or when the preview target changes mid-tab (e.g.
// the admin navigates between two client previews without a full reload).
// Close any open popover so it doesn't show stale breakdown data (or anchor to
// a card that's about to disappear).
watch([viewYear, viewMonth, previewClientId], () => {
  closeDayPopover()
  closeHourlyBreakdown()
  loadAll()
})

// Summary cache is per client — a preview target switch invalidates everything.
watch(previewClientId, invalidateSummaries)

// When the wall-clock day rolls over while the page is open, refetch so today's
// "ongoing" state and any cleanings that started after midnight show up. The
// summaries move too ("Dnes" period, month deltas), so the cache goes with them.
watch(today, () => {
  invalidateSummaries()
  loadAll()
})

const dayMap = computed(() => {
  const m = {}
  for (const d of cleaningDays.value) {
    m[d.date] = {
      ongoing: !!d.ongoing,
      cleanings: Array.isArray(d.cleanings) ? d.cleanings : [],
    }
  }
  return m
})

// True iff any rendered day actually carries detailed cleanings — suppresses the
// privacy note on Detailed-mode IČOs. Quiet months show the privacy note even on
// Detailed-mode IČOs; that's acceptable because the note is informative, not
// a policy statement.
const hasAnyDetailedCleaning = computed(() =>
  cleaningDays.value.some(d => Array.isArray(d.cleanings) && d.cleanings.length > 0)
)

function pickPriorityStatus(statuses) {
  for (const key of PRIORITY_ORDER) {
    if (statuses[key]) return key
  }
  return null
}

function buildRequestTooltip(requests) {
  if (!requests.total) return ''
  const parts = []
  for (const s of REQUEST_STATUSES) {
    const n = requests.statuses[s.key]
    if (n) parts.push(`${s.label}: ${n}`)
  }
  return `Požadavky: ${requests.total} (${parts.join(', ')})`
}

const calendarDays = computed(() => {
  const year  = viewYear.value
  const month = viewMonth.value
  const firstDay = new Date(year, month, 1)
  const lastDay  = new Date(year, month + 1, 0)
  const startOffset = (firstDay.getDay() + 6) % 7
  const cells = []
  for (let i = 0; i < startOffset; i++) cells.push(null)
  const todayIso = today.value
  for (let d = 1; d <= lastDay.getDate(); d++) {
    const key = `${year}-${String(month + 1).padStart(2,'0')}-${String(d).padStart(2,'0')}`
    const info = dayMap.value[key]
    const isToday = key === todayIso
    const isPast = !isToday && key < todayIso
    const requests = requestsByDay.value[key] || { total: 0, statuses: {} }
    const cleanings = info?.cleanings || []
    cells.push({
      day: d, key,
      hasCleaning: !!info,
      ongoing: info?.ongoing || false,
      cleanings,
      cleaningsCount: cleanings.length,
      // Days in privacy mode carry no cleaning detail but still get one dot.
      dotCount: cleanings.length > 0 ? Math.min(cleanings.length, 3) : (info ? 1 : 0),
      requests,
      priorityStatus: pickPriorityStatus(requests.statuses),
      hasContent: !!info || requests.total > 0,
      isToday, isPast,
    })
  }
  return cells
})

const monthLabel = computed(() => `${MONTHS[viewMonth.value]} ${viewYear.value}`)

// The hourly summary is shown only when the selected month is the current
// calendar month. Past months are hidden on purpose: invoiced totals can diverge
// from the raw FreshQR sum (manual adjustments, renegotiated rates, complaint
// credits) and we don't want clients comparing a provisional figure against
// the real invoice. Future months are hidden because they're always zero.
const isCurrentMonth = computed(() => {
  const [yStr, mStr] = today.value.split('-')
  return viewYear.value === Number(yStr) && viewMonth.value === Number(mStr) - 1
})

const hourlySummaryHasRate = computed(() =>
  hourlySummary.value.some(r => r && typeof r.hourlyRate === 'number' && r.hourlyRate > 0)
)

const czkFormatter = new Intl.NumberFormat('cs-CZ', {
  style: 'currency',
  currency: 'CZK',
  maximumFractionDigits: 2,
})
function formatCzk(amount) {
  if (!Number.isFinite(amount)) return ''
  return czkFormatter.format(amount)
}

function summaryHours(row) {
  // "0 h" reads better than "0 min" in the summary card header — keep the
  // explicit zero fallback even though formatDuration handles 0 itself now.
  if (!Number.isFinite(row.totalMinutes) || row.totalMinutes <= 0) return '0 h'
  return formatDuration(row.totalMinutes)
}

function summaryAmount(row) {
  if (!row.hourlyRate || row.hourlyRate <= 0) return null
  return formatCzk((row.totalMinutes / 60) * row.hourlyRate)
}

// Mirror the backend's pickMinutes precedence: roundedMinutes is the billable
// truth (0 is a valid rounded-down value, not "missing"); rawMinutes only kicks
// in when no rounding rule applied (roundedMinutes is null).
function pickCleaningMinutes(c) {
  if (typeof c.roundedMinutes === 'number' && c.roundedMinutes >= 0) return c.roundedMinutes
  if (typeof c.rawMinutes === 'number' && c.rawMinutes > 0) return c.rawMinutes
  return null
}

function dailyBreakdown(ico) {
  const days = []
  for (const day of cleaningDays.value) {
    let minutes = 0
    let hasContribution = false
    for (const c of (day.cleanings || [])) {
      if (c.ico !== ico) continue
      const m = pickCleaningMinutes(c)
      if (m === null) continue
      minutes += m
      hasContribution = true
    }
    // Days where every cleaning rounded down to 0 minutes still happened and
    // belong in the breakdown — dropping them used to make IČOs with strict
    // rounding rules look like they had no activity at all in the popover.
    if (hasContribution) days.push({ date: day.date, minutes })
  }
  return days
}

function formatBreakdownDate(iso) {
  const [y, m, d] = iso.split('-').map(Number)
  const date = new Date(y, m - 1, d)
  // ISO weekday: Mon=1..Sun=7 — convert to our WEEKDAYS index (0=Po..6=Ne).
  const wdIndex = (date.getDay() + 6) % 7
  return `${WEEKDAYS[wdIndex]} ${d}. ${m}.`
}

const breakdownRow = computed(() => {
  if (!openHourlyBreakdownIco.value) return null
  return hourlySummary.value.find(r => r.ico === openHourlyBreakdownIco.value) || null
})

const breakdownDays = computed(() => {
  if (!openHourlyBreakdownIco.value) return []
  return dailyBreakdown(openHourlyBreakdownIco.value)
})

const hourlyBreakdownAnchorStyle = computed(() => {
  const r = hourlyBreakdownAnchorRect.value
  if (!r) return {}
  return {
    '--anchor-bottom': `${r.bottom}px`,
    '--anchor-center': `${r.left + r.width / 2}px`,
  }
})

function openHourlyBreakdown(ico, event) {
  if (openHourlyBreakdownIco.value === ico) {
    closeHourlyBreakdown()
    return
  }
  closeDayPopover()
  if (event && event.currentTarget && event.currentTarget.getBoundingClientRect) {
    hourlyBreakdownAnchorRect.value = event.currentTarget.getBoundingClientRect()
  } else {
    hourlyBreakdownAnchorRect.value = null
  }
  openHourlyBreakdownIco.value = ico
}

function closeHourlyBreakdown() {
  openHourlyBreakdownIco.value = null
  hourlyBreakdownAnchorRect.value = null
}

// --- Month stat cards (Návštěv / Celkový čas) ---
const monthDays = computed(() =>
  cleaningDays.value.filter(d => d.date.startsWith(monthPrefix.value))
)

// Detailed-mode days count each cleaning as a visit; privacy-mode days carry no
// detail, so the day itself counts as one visit.
const monthVisitCount = computed(() =>
  monthDays.value.reduce(
    (n, d) => n + (Array.isArray(d.cleanings) && d.cleanings.length > 0 ? d.cleanings.length : 1),
    0
  )
)

const monthMinutes = computed(() => {
  let total = 0
  for (const d of monthDays.value) {
    for (const c of (d.cleanings || [])) {
      const m = pickCleaningMinutes(c)
      if (m !== null) total += m
    }
  }
  return total
})

// "—" covers privacy-mode months where visits happened but times are hidden;
// an empty month reads "0" like the design's empty state.
const monthTimeText = computed(() => {
  if (monthMinutes.value > 0) return formatDuration(monthMinutes.value)
  return monthVisitCount.value === 0 ? '0' : '—'
})

const monthOverview = computed(() => summaries.value['month'] || null)

// Delta chips replicate the design's "+5%" / "+2h" hints. They only make sense
// on the current month, where the stat cards and the month summary describe the
// same range.
const monthVisitDelta = computed(() => {
  if (!isCurrentMonth.value || !monthOverview.value) return 0
  return (monthOverview.value.current.visitCount || 0) - (monthOverview.value.previous.visitCount || 0)
})
const monthVisitDeltaText = computed(() => {
  const d = monthVisitDelta.value
  if (d === 0) return null
  const prev = monthOverview.value.previous.visitCount || 0
  if (prev > 0) {
    const pct = Math.round((d / prev) * 100)
    if (pct !== 0) return `${d > 0 ? '+' : '−'}${Math.abs(pct)} %`
  }
  return `${d > 0 ? '+' : '−'}${Math.abs(d)}`
})
const monthMinutesDelta = computed(() => {
  if (!isCurrentMonth.value || !monthOverview.value?.current?.hasTimeData) return 0
  return (monthOverview.value.current.totalMinutes || 0) - (monthOverview.value.previous.totalMinutes || 0)
})
const monthTimeDeltaText = computed(() => {
  const d = monthMinutesDelta.value
  if (d === 0) return null
  return `${d > 0 ? '+' : '−'}${formatDuration(Math.abs(d))}`
})

// --- Header status badge ---
// The backend only sets `ongoing` while a cleaning is genuinely live now — today's
// open scans, plus an overnight cleaning that started late yesterday and is still
// running past midnight (anchored to its start day). Keying on the flag alone,
// rather than d.date === today, keeps the badge lit for that overnight case.
const hasOngoingNow = computed(() =>
  cleaningDays.value.some(d => d.ongoing)
)
const headerStatus = computed(() => {
  if (loading.value) return { key: 'updating', label: 'Aktualizace…' }
  if (upstreamError.value) return { key: 'offline', label: 'Offline' }
  if (freshqrActive.value && hasOngoingNow.value) return { key: 'ongoing', label: 'Úklid probíhá' }
  return null
})

// --- Overview / year tab derived state ---
const activeSummaryPeriod = computed(() =>
  activeTab.value === 'year' ? 'year' : overviewPeriod.value
)
const activeSummary = computed(() => summaries.value[activeSummaryPeriod.value] || null)
const activeSummaryLoading = computed(() => !!summaryLoading.value[activeSummaryPeriod.value])
const activeSummaryError = computed(() => summaryError.value[activeSummaryPeriod.value] || null)

watch([activeTab, activeSummaryPeriod], () => {
  if (activeTab.value === 'year' || activeTab.value === 'overview') {
    ensureSummary(activeSummaryPeriod.value)
  }
})

function prevLabelOf(summary) {
  return PERIOD_OPTIONS.find(p => p.key === summary?.period)?.prevLabel || 'minulé období'
}
function hasTimeDataOf(summary) {
  return !!summary?.current?.hasTimeData
}
function visitDeltaOf(summary) {
  if (!summary) return 0
  return (summary.current.visitCount || 0) - (summary.previous.visitCount || 0)
}
function minutesDeltaOf(summary) {
  if (!summary) return 0
  return (summary.current.totalMinutes || 0) - (summary.previous.totalMinutes || 0)
}
function maxObjectMinutesOf(summary) {
  return (summary?.current?.perObject || []).reduce((m, o) => Math.max(m, o.totalMinutes || 0), 0)
}

function pluralUklid(n) {
  const abs = Math.abs(n)
  if (abs === 1) return 'úklid'
  if (abs >= 2 && abs <= 4) return 'úklidy'
  return 'úklidů'
}
function formatVisits(n) {
  return `${n} ${pluralUklid(n)}`
}
function pluralRequests(n) {
  if (n === 1) return '1 požadavek'
  if (n >= 2 && n <= 4) return `${n} požadavky`
  return `${n} požadavků`
}

function deltaClass(d) {
  return d > 0 ? 'delta-up' : (d < 0 ? 'delta-down' : 'delta-flat')
}
function deltaIcon(d) {
  return d > 0 ? ArrowUp : (d < 0 ? ArrowDown : Minus)
}
// Uses a real minus sign (−) so a negative delta reads cleanly next to the arrow.
function deltaCount(d) {
  if (d === 0) return 'beze změny'
  return `${d > 0 ? '+' : '−'}${Math.abs(d)}`
}
function deltaMinutesTextOf(summary) {
  const d = minutesDeltaOf(summary)
  if (d === 0) return 'beze změny'
  return `${d > 0 ? '+' : '−'}${formatDuration(Math.abs(d))}`
}

// Bar width relative to the busiest object; a small floor keeps a tiny non-zero
// value visible rather than collapsing to an invisible sliver.
function barWidth(minutes, summary) {
  const max = maxObjectMinutesOf(summary)
  if (!max || !minutes || minutes <= 0) return '0%'
  return `${Math.max(4, Math.round((minutes / max) * 100))}%`
}

function formatRangeLabel(range) {
  if (!range || !range.from || !range.to) return ''
  const [fy, fm, fd] = range.from.split('-').map(Number)
  const [ty, tm, td] = range.to.split('-').map(Number)
  if (range.from === range.to) return `${fd}. ${fm}. ${fy}`
  if (fy === ty && fm === tm) return `${fd}. – ${td}. ${tm}. ${ty}`
  if (fy === ty) return `${fd}. ${fm}. – ${td}. ${tm}. ${ty}`
  return `${fd}. ${fm}. ${fy} – ${td}. ${tm}. ${ty}`
}

function prevMonth() {
  if (viewMonth.value === 0) { viewMonth.value = 11; viewYear.value-- }
  else viewMonth.value--
}
function nextMonth() {
  if (viewMonth.value === 11) { viewMonth.value = 0; viewYear.value++ }
  else viewMonth.value++
}
function goToday() {
  // Read the wall clock at click time rather than the cached today.value so a
  // page that's been open across midnight still navigates to the real current
  // month even before the interval tick rolls today forward.
  const now = new Date()
  viewYear.value  = now.getFullYear()
  viewMonth.value = now.getMonth()
  refreshToday()
}

// --- Day selection & "Záznamy dne" ---
function selectDay(cell) {
  if (!cell.hasContent) return
  selectedDate.value = cell.key
}

// Prefer today, otherwise the most recent day with records, so opening the page
// immediately shows something meaningful in Záznamy dne.
function autoSelectDay() {
  const dates = new Set(monthDays.value.map(d => d.date))
  for (const [key, val] of Object.entries(requestsByDay.value)) {
    if (key.startsWith(monthPrefix.value) && val.total > 0) dates.add(key)
  }
  const sorted = [...dates].sort()
  if (sorted.length === 0) {
    selectedDate.value = null
    return
  }
  if (dates.has(today.value)) {
    selectedDate.value = today.value
    return
  }
  const past = sorted.filter(d => d <= today.value)
  selectedDate.value = past.length ? past[past.length - 1] : sorted[0]
}

const selectedDayInfo = computed(() =>
  selectedDate.value ? (dayMap.value[selectedDate.value] || null) : null
)
const selectedDayCleanings = computed(() => selectedDayInfo.value?.cleanings || [])
const selectedDayLabel = computed(() => {
  if (!selectedDate.value) return ''
  const [y, m, d] = selectedDate.value.split('-').map(Number)
  return `${d}. ${m}. ${y}`
})

const monthHasRecords = computed(() => {
  if (monthDays.value.length > 0) return true
  return Object.entries(requestsByDay.value)
    .some(([key, val]) => key.startsWith(monthPrefix.value) && val.total > 0)
})

// Requests for the selected day load lazily; the token guards against a slow
// response landing after the user already picked a different day.
let dayRequestsToken = 0
async function loadDayRequests() {
  const date = selectedDate.value
  const token = ++dayRequestsToken
  dayRequests.value = []
  dayRequestsLoading.value = false
  if (!date || isPreview.value) return
  const total = requestsByDay.value[date]?.total || 0
  if (!total) return
  dayRequestsLoading.value = true
  try {
    const res = await maintenanceRequestService.list({ date })
    if (res.success && token === dayRequestsToken) {
      dayRequests.value = res.data
    }
  } catch (e) {
    // silent — the day simply shows no request cards
  } finally {
    if (token === dayRequestsToken) dayRequestsLoading.value = false
  }
}
watch([selectedDate, requestsByDay], loadDayRequests)

// Company label for a record card: resolved from any loaded summary's per-object
// breakdown or from the hourly billing rows. Falls back to a generic label.
const icoCompanyMap = computed(() => {
  const m = {}
  for (const s of Object.values(summaries.value)) {
    for (const o of (s?.current?.perObject || [])) {
      if (o.ico && o.companyName) m[o.ico] = o.companyName
    }
  }
  for (const r of hourlySummary.value) {
    if (r.ico && r.companyName) m[r.ico] = r.companyName
  }
  return m
})

function recordLabel(c) {
  if (c.ongoing) return 'Právě probíhá'
  return icoCompanyMap.value[c.ico] || 'Úklid'
}

// End-of-cleaning label. An overnight cleaning is anchored to its start day by
// the backend; when it finished after midnight (endsNextDay) we suffix "(+1)"
// so the end time reads as the following day rather than an impossible reversal.
function endLabel(c) {
  if (!c || !c.endTime) return ''
  return c.endsNextDay ? `${c.endTime} (+1)` : c.endTime
}

function initials(name) {
  const parts = String(name || '').split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '–'
  return parts.slice(0, 2).map(p => p[0].toUpperCase()).join('')
}

// --- Hover peek popover (desktop only) ---
function peekDayPopover(cell, event) {
  if (!cell.cleaningsCount) return
  closeHourlyBreakdown()
  if (event && event.currentTarget && event.currentTarget.getBoundingClientRect) {
    popoverAnchorRect.value = event.currentTarget.getBoundingClientRect()
  } else {
    popoverAnchorRect.value = null
  }
  openPopoverDate.value = cell.key
}

function onCellMouseEnter(cell, event) {
  if (!supportsHover) return
  // Per-cell signal for "Personál a časy" data: backend only emits non-empty
  // cleanings[] for IČOs in detailed mode.
  if (!cell.cleaningsCount) return
  clearTimeout(hoverCloseTimer); hoverCloseTimer = null
  // Capture rect now — `event.currentTarget` is nulled out by the time the
  // timer fires (synthetic events are recycled).
  const rect = event?.currentTarget?.getBoundingClientRect?.() ?? null
  const switchingFromOtherHover = openPopoverDate.value !== null && openPopoverDate.value !== cell.key
  const delay = switchingFromOtherHover ? 0 : HOVER_OPEN_DELAY_MS
  clearTimeout(hoverOpenTimer)
  hoverOpenTimer = setTimeout(() => {
    peekDayPopover(cell, { currentTarget: { getBoundingClientRect: () => rect } })
  }, delay)
}

function onCellMouseLeave() {
  if (!supportsHover) return
  clearTimeout(hoverOpenTimer); hoverOpenTimer = null
  clearTimeout(hoverCloseTimer)
  hoverCloseTimer = setTimeout(closeDayPopover, HOVER_CLOSE_DELAY_MS)
}

function onPopoverMouseEnter() {
  clearTimeout(hoverCloseTimer); hoverCloseTimer = null
}

function onPopoverMouseLeave() {
  clearTimeout(hoverCloseTimer)
  hoverCloseTimer = setTimeout(closeDayPopover, HOVER_CLOSE_DELAY_MS)
}

function statusMeta(key) {
  return REQUEST_STATUSES.find(s => s.key === key) || { label: key, badge: 'badge-gray' }
}

function closeDayPopover() {
  openPopoverDate.value = null
  popoverAnchorRect.value = null
  if (hoverOpenTimer !== null) { clearTimeout(hoverOpenTimer); hoverOpenTimer = null }
  if (hoverCloseTimer !== null) { clearTimeout(hoverCloseTimer); hoverCloseTimer = null }
}

function goToRequest(id) {
  router.push(`/zadosti/${id}`)
}

const activeCell = computed(() => {
  if (!openPopoverDate.value) return null
  return calendarDays.value.find(c => c && c.key === openPopoverDate.value) || null
})

const popoverAnchorStyle = computed(() => {
  const r = popoverAnchorRect.value
  if (!r) return {}
  return {
    '--anchor-bottom': `${r.bottom}px`,
    '--anchor-center': `${r.left + r.width / 2}px`,
  }
})

function handleViewportChange() {
  if (openPopoverDate.value) closeDayPopover()
  if (openHourlyBreakdownIco.value) closeHourlyBreakdown()
}

function handleVisibilityChange() {
  if (document.visibilityState === 'visible') {
    refreshToday()
  }
}

onMounted(() => {
  window.addEventListener('scroll', handleViewportChange, true)
  window.addEventListener('resize', handleViewportChange)
  // Refresh `today` when the tab regains focus AND on a 60-second interval
  // while the page stays open — a phone left on the dochazka tab overnight
  // would otherwise keep dimming today's cell as "past".
  document.addEventListener('visibilitychange', handleVisibilityChange)
  todayInterval = setInterval(refreshToday, 60_000)
})
onBeforeUnmount(() => {
  window.removeEventListener('scroll', handleViewportChange, true)
  window.removeEventListener('resize', handleViewportChange)
  document.removeEventListener('visibilitychange', handleVisibilityChange)
  if (todayInterval !== null) {
    clearInterval(todayInterval)
    todayInterval = null
  }
  if (hoverOpenTimer !== null) { clearTimeout(hoverOpenTimer); hoverOpenTimer = null }
  if (hoverCloseTimer !== null) { clearTimeout(hoverCloseTimer); hoverCloseTimer = null }
})
</script>

<template>
  <div id="attendance-page" class="page-shell page-shell--lg">
    <!-- Admin preview banner: only rendered when an admin opened /dochazka with
         a previewClientId query param. Bold strip so the admin can never confuse
         the preview with their own attendance data. The preview always opens in
         a new tab, so closing the view means closing the tab — no in-page close
         button needed. -->
    <div v-if="isPreview" id="attendance-preview-banner" class="preview-banner">
      <div class="preview-banner-content">
        <Eye :size="16" aria-hidden="true" />
        <div class="preview-banner-text">
          <strong>Náhled klientského pohledu</strong>
          <span v-if="previewClientName">— {{ previewClientName }}</span>
          <span class="preview-banner-hint">Zobrazujeme přesně to, co uvidí klient.</span>
        </div>
      </div>
    </div>

    <div class="page-header attendance-header">
      <div>
        <h1 class="page-title">Docházka a záznamy</h1>
        <p class="page-subtitle">Přehled úklidů na vašem místě</p>
      </div>
      <span
        v-if="headerStatus"
        id="attendance-status-badge"
        class="status-badge"
        :class="`status-${headerStatus.key}`"
      >
        <span class="status-dot" aria-hidden="true" />
        {{ headerStatus.label }}
      </span>
    </div>

    <!-- Upstream (FreshQR) transient failure — offline banner, calendar still renders -->
    <div v-if="upstreamError && !loading" id="attendance-upstream-error" class="offline-banner">
      <span class="offline-banner-dot" aria-hidden="true" />
      <span>{{ upstreamError }}</span>
    </div>

    <!-- Error state -->
    <div v-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Loading skeleton -->
    <div v-else-if="loading" id="attendance-skeleton" class="attendance-skeleton" aria-hidden="true">
      <div class="skeleton sk-tabs" />
      <div class="sk-cal">
        <div class="skeleton sk-cal-title" />
        <div class="sk-cal-grid">
          <div v-for="n in 35" :key="n" class="skeleton sk-cal-day" />
        </div>
      </div>
      <div class="sk-stat-row">
        <div class="skeleton sk-stat" />
        <div class="skeleton sk-stat" />
      </div>
      <div class="skeleton sk-line" />
      <div class="skeleton sk-record" />
    </div>

    <!-- Fallback when FreshQR not active — onboarding tone -->
    <div v-else-if="!freshqrActive" id="attendance-freshqr-off" class="onboarding-hero">
      <div class="onboarding-hero-icon onboarding-hero-icon--soft">
        <CalendarIcon :size="28" aria-hidden="true" />
      </div>
      <h2 class="onboarding-hero-title">Chcete přehled o každém úklidu?</h2>
      <p class="onboarding-hero-desc">
        Po aktivaci docházky přes QR kód uvidíte ve svém kalendáři každý úklid:
        den, který proběhl, i ten, co zrovna probíhá. Transparentní kontrola bez papírování.
      </p>
      <div class="onboarding-hero-actions">
        <a href="tel:+420773023608" class="btn btn-primary btn-sm">
          <Phone :size="14" aria-hidden="true" />
          Aktivovat telefonicky
        </a>
        <a href="mailto:jurij.fedorycak@fajnuklid.cz" class="btn btn-outline btn-sm">
          <Mail :size="14" aria-hidden="true" />
          Napsat e-mail
        </a>
      </div>
    </div>

    <!-- Active FreshQR content -->
    <template v-else>

    <!-- View tabs -->
    <div id="attendance-tabs" class="seg-tabs" role="tablist" aria-label="Zobrazení docházky">
      <button
        v-for="t in TABS"
        :key="t.key"
        :id="`attendance-tab-${t.key}`"
        type="button"
        role="tab"
        class="seg-tab"
        :class="{ 'is-active': activeTab === t.key }"
        :aria-selected="activeTab === t.key"
        @click="activeTab = t.key"
      >{{ t.label }}</button>
    </div>

    <!-- ═══ MĚSÍC ═══ -->
    <template v-if="activeTab === 'month'">

      <!-- Calendar card -->
      <div id="attendance-calendar-card" class="card cal-card">
        <div class="cal-header">
          <h2 id="cal-month-label" class="cal-month">{{ monthLabel }}</h2>
          <div class="cal-controls">
            <button v-if="!isCurrentMonth" id="cal-today-btn" class="today-btn" @click="goToday">Dnes</button>
            <button id="cal-prev-btn" class="nav-btn" aria-label="Předchozí měsíc" @click="prevMonth">
              <ChevronLeft :size="17" />
            </button>
            <button id="cal-next-btn" class="nav-btn" aria-label="Další měsíc" @click="nextMonth">
              <ChevronRight :size="17" />
            </button>
          </div>
        </div>

        <div id="attendance-cal-grid" class="cal-grid">
          <div class="wd-header" v-for="wd in WEEKDAYS" :key="wd">{{ wd }}</div>

          <template v-for="(cell, idx) in calendarDays" :key="idx">
            <div v-if="cell === null" class="day-cell empty-cell" />
            <div
              v-else
              :id="`cal-day-${cell.key}`"
              class="day-cell"
              :class="{
                'day-selected':  selectedDate === cell.key,
                'day-today':     cell.isToday,
                'day-has-record': cell.hasCleaning,
                'day-ongoing':   cell.ongoing,
                'day-past':      cell.isPast && !cell.hasContent,
                'day-clickable': cell.hasContent,
              }"
              :title="cell.requests.total > 0 ? buildRequestTooltip(cell.requests) : (cell.hasCleaning ? 'Úklid proběhl' : '')"
              @click="selectDay(cell)"
              @mouseenter="onCellMouseEnter(cell, $event)"
              @mouseleave="onCellMouseLeave"
            >
              <span class="day-num">{{ cell.day }}</span>
              <span v-if="cell.dotCount > 0" class="day-dots" aria-hidden="true">
                <span v-for="n in cell.dotCount" :key="n" class="day-dot" />
              </span>
              <span
                v-if="cell.requests.total > 0"
                class="day-status-badge"
                :class="`badge-${cell.priorityStatus === 'resi_se' ? 'warning' : cell.priorityStatus === 'prijato' ? 'info' : 'success'}`"
                :id="'req-badge-' + cell.key"
              >{{ cell.requests.total }}</span>
            </div>
          </template>
        </div>

        <div id="attendance-cal-legend" class="cal-legend">
          <span id="cal-legend-record" class="cal-legend-item">
            <span class="cal-legend-swatch cal-legend-swatch--record" aria-hidden="true">
              <span class="cal-legend-dot" />
            </span>
            Den s úklidem
          </span>
          <span id="cal-legend-ongoing" class="cal-legend-item">
            <span class="cal-legend-swatch cal-legend-swatch--ongoing" aria-hidden="true">
              <span class="cal-legend-dot" />
            </span>
            Právě probíhá
          </span>
          <span id="cal-legend-selected" class="cal-legend-item">
            <span class="cal-legend-swatch cal-legend-swatch--selected" aria-hidden="true">
              <span class="cal-legend-dot" />
            </span>
            Vybraný den
          </span>
          <span v-if="!isPreview" id="cal-legend-requests" class="cal-legend-item">
            <span class="cal-legend-badge" aria-hidden="true">1</span>
            Požadavky
          </span>
        </div>
      </div>

      <!-- Stat cards -->
      <div id="attendance-month-stats" class="stat-cards">
        <div id="attendance-stat-visits" class="stat-card">
          <span class="stat-label">Návštěv</span>
          <span class="stat-value-row">
            <span class="stat-value">{{ monthVisitCount }}</span>
            <span
              v-if="monthVisitDeltaText"
              class="stat-delta"
              :class="deltaClass(monthVisitDelta)"
            >{{ monthVisitDeltaText }}</span>
          </span>
        </div>
        <div id="attendance-stat-time" class="stat-card">
          <span class="stat-label">Celkový čas</span>
          <span class="stat-value-row">
            <span class="stat-value">{{ monthTimeText }}</span>
            <span
              v-if="monthTimeDeltaText"
              class="stat-delta"
              :class="deltaClass(monthMinutesDelta)"
            >{{ monthTimeDeltaText }}</span>
          </span>
        </div>
      </div>

      <!-- Empty month -->
      <div v-if="!monthHasRecords" id="attendance-month-empty" class="month-empty">
        <span class="month-empty-icon">
          <FolderOpen :size="22" aria-hidden="true" />
        </span>
        <p class="month-empty-text">Žádné záznamy pro tento měsíc</p>
      </div>

      <!-- Záznamy dne -->
      <template v-else-if="selectedDate">
        <h2 id="day-records-title" class="section-title">
          Záznamy dne
          <span class="section-title-date">{{ selectedDayLabel }}</span>
        </h2>
        <div id="day-records-list" class="record-list">
          <div
            v-for="(c, i) in selectedDayCleanings"
            :key="`${c.startTime || 'na'}-${c.employee}-${i}`"
            :id="`day-record-${selectedDate}-${i}`"
            class="record-card"
          >
            <div class="record-main">
              <span class="record-label" :class="{ 'record-label--ongoing': c.ongoing }">
                {{ recordLabel(c) }}
              </span>
              <span class="record-time">
                <Clock :size="14" aria-hidden="true" />
                <!-- Ongoing visit: backend sets c.ongoing only when the cleaning is
                     today AND its TimeTo is null/equal-to-TimeFrom. On IČOs with
                     rounding rules the controller strips startTime so the row stays
                     a pure "Probíhá" until the rounded display can be committed. -->
                <template v-if="c.ongoing">
                  <span class="record-time-main record-time-ongoing">
                    {{ c.startTime ? `${c.startTime} — nyní` : 'Probíhá' }}
                  </span>
                </template>
                <!-- Rules defined for this IČO: clients see the rounded range
                     (controller swaps endTime for the shifted value); admins keep
                     the raw range alongside the explicit "Účtováno" label. -->
                <template v-else-if="c.roundedMinutes != null">
                  <span class="record-time-main">
                    <template v-if="c.startTime && c.endTime">{{ c.startTime }} — {{ endLabel(c) }}</template>
                    <template v-else>{{ formatDuration(c.roundedMinutes) }}</template>
                  </span>
                  <span v-if="showAdminDetails" class="record-time-billed">
                    · Účtováno {{ formatDuration(c.roundedMinutes) }}
                  </span>
                  <span v-else-if="c.startTime && c.endTime" class="record-time-billed">
                    · {{ formatDuration(c.roundedMinutes) }}
                  </span>
                </template>
                <!-- No rounding rules configured: legacy raw-time range. -->
                <template v-else>
                  <span class="record-time-main">
                    {{ c.startTime || '—' }}<template v-if="c.endTime"> — {{ endLabel(c) }}</template>
                  </span>
                </template>
              </span>
              <span class="record-emp">{{ c.employee }}</span>
            </div>
            <span class="record-avatar" aria-hidden="true">{{ initials(c.employee) }}</span>
          </div>

          <!-- Privacy-mode day: presence known, details hidden -->
          <div
            v-if="selectedDayInfo && selectedDayCleanings.length === 0"
            :id="`day-record-presence-${selectedDate}`"
            class="record-card"
          >
            <div class="record-main">
              <span class="record-label" :class="{ 'record-label--ongoing': selectedDayInfo.ongoing }">
                {{ selectedDayInfo.ongoing ? 'Právě probíhá' : 'Úklid proběhl' }}
              </span>
              <span class="record-emp">Detaily nejsou zobrazeny z důvodu ochrany soukromí.</span>
            </div>
          </div>

          <!-- Maintenance requests of the selected day -->
          <div v-if="dayRequestsLoading" id="day-requests-loading" class="record-loading">
            <Loader2 :size="14" class="spin" aria-hidden="true" />
            Načítám požadavky…
          </div>
          <button
            v-for="item in dayRequests"
            :key="item.id"
            :id="`day-record-request-${item.id}`"
            type="button"
            class="record-card record-card--request"
            @click="goToRequest(item.id)"
          >
            <div class="record-main">
              <span class="record-label record-label--request">Požadavek</span>
              <span class="record-req-title">{{ item.title }}</span>
              <span class="record-req-meta">
                <span class="dpi-badge" :class="statusMeta(item.status).badge">
                  {{ statusMeta(item.status).label }}
                </span>
                <span v-if="item.companyName" class="record-req-company">{{ item.companyName }}</span>
              </span>
            </div>
            <ChevronRight :size="16" class="record-chevron" aria-hidden="true" />
          </button>
        </div>
      </template>

      <!-- Hourly billing summary — one row per IČO on Hodinová sazba. Only
           visible when viewing the current month (see isCurrentMonth comment). -->
      <div
        v-if="hourlySummary.length > 0 && isCurrentMonth"
        id="hourly-summary-card"
        class="card hourly-summary-card"
      >
        <div class="hs-header">
          <h2 class="hs-title">Hodinové vyúčtování</h2>
          <span class="hs-month">{{ monthLabel }}</span>
        </div>
        <ul class="hs-list">
          <li
            v-for="row in hourlySummary"
            :key="row.ico"
            :id="`hourly-summary-row-${row.ico}`"
            class="hs-row"
          >
            <div class="hs-row-head">
              <span class="hs-company">{{ row.companyName }}</span>
              <span class="hs-ico">IČO {{ row.ico }}</span>
            </div>
            <dl class="hs-row-body">
              <div class="hs-metric">
                <dt class="hs-metric-label">Zatím odpracováno</dt>
                <dd class="hs-metric-value">
                  <button
                    type="button"
                    :id="`hourly-breakdown-trigger-${row.ico}`"
                    class="hs-hours-trigger"
                    :class="{ 'is-open': openHourlyBreakdownIco === row.ico }"
                    :aria-haspopup="true"
                    :aria-expanded="openHourlyBreakdownIco === row.ico"
                    @click="openHourlyBreakdown(row.ico, $event)"
                  >
                    {{ summaryHours(row) }}
                    <ChevronDown :size="14" aria-hidden="true" />
                  </button>
                </dd>
              </div>
              <div v-if="row.hourlyRate && row.hourlyRate > 0" class="hs-metric">
                <dt class="hs-metric-label">Sazba</dt>
                <dd class="hs-metric-value hs-rate">{{ formatCzk(row.hourlyRate) }}/hod</dd>
              </div>
              <div v-if="summaryAmount(row)" class="hs-metric hs-amount">
                <dt class="hs-metric-label">Předběžně k fakturaci</dt>
                <dd class="hs-metric-value">{{ summaryAmount(row) }}</dd>
              </div>
            </dl>
          </li>
        </ul>
        <p
          v-if="hourlySummaryHasRate"
          id="hourly-summary-footnote"
          class="hs-footnote"
        >
          Údaje jsou orientační, podle dat z FreshQR. Konečné vyúčtování obdržíte
          po skončení měsíce.
        </p>
      </div>

      <p v-if="!hasAnyDetailedCleaning" class="privacy-note">
        Kalendář zobrazuje pouze přítomnost úklidové služby.
        Detaily pracovníků a časy nejsou zobrazeny z důvodu ochrany soukromí.
      </p>
    </template>

    <!-- ═══ ROK / PŘEHLED ═══ -->
    <div
      v-else
      :id="activeTab === 'year' ? 'attendance-year' : 'attendance-overview'"
      class="card overview-card"
    >
      <div class="ov-header">
        <div class="ov-title-wrap">
          <BarChart3 :size="18" class="ov-title-icon" aria-hidden="true" />
          <h2 class="ov-title">{{ activeTab === 'year' ? 'Roční přehled' : 'Přehledy docházky' }}</h2>
        </div>
        <div
          v-if="activeTab === 'overview'"
          class="ov-period-switch"
          role="group"
          aria-label="Období přehledu"
        >
          <button
            v-for="opt in PERIOD_OPTIONS"
            :key="opt.key"
            :id="`ov-period-${opt.key}`"
            type="button"
            class="ov-period-btn"
            :class="{ 'is-active': overviewPeriod === opt.key }"
            :aria-pressed="overviewPeriod === opt.key"
            @click="overviewPeriod = opt.key"
          >{{ opt.label }}</button>
        </div>
      </div>

      <div v-if="activeSummaryLoading" id="ov-loading" class="ov-loading">
        <Loader2 :size="18" class="spin" aria-hidden="true" />
        <span>Načítám přehled…</span>
      </div>
      <div v-else-if="activeSummaryError" id="ov-error" class="alert alert-warning ov-alert">
        {{ activeSummaryError }}
      </div>
      <template v-else-if="activeSummary">
        <p v-if="activeSummary.error" id="ov-partial" class="ov-partial">{{ activeSummary.error }}</p>
        <p class="ov-range">{{ formatRangeLabel(activeSummary.range) }}</p>

        <!-- Headline metrics -->
        <div class="ov-stats">
          <div id="ov-stat-visits" class="ov-stat">
            <span class="ov-stat-label">Uskutečněné návštěvy</span>
            <span class="ov-stat-value">{{ activeSummary.current.visitCount }}</span>
            <span class="ov-stat-delta" :class="deltaClass(visitDeltaOf(activeSummary))">
              <component :is="deltaIcon(visitDeltaOf(activeSummary))" :size="13" aria-hidden="true" />
              {{ deltaCount(visitDeltaOf(activeSummary)) }}
              <span class="ov-delta-ref">vs. {{ prevLabelOf(activeSummary) }}</span>
            </span>
          </div>
          <div v-if="hasTimeDataOf(activeSummary)" id="ov-stat-time" class="ov-stat">
            <span class="ov-stat-label">Odpracovaný čas</span>
            <span class="ov-stat-value">{{ formatDuration(activeSummary.current.totalMinutes) }}</span>
            <span class="ov-stat-delta" :class="deltaClass(minutesDeltaOf(activeSummary))">
              <component :is="deltaIcon(minutesDeltaOf(activeSummary))" :size="13" aria-hidden="true" />
              {{ deltaMinutesTextOf(activeSummary) }}
              <span class="ov-delta-ref">vs. {{ prevLabelOf(activeSummary) }}</span>
            </span>
          </div>
          <div v-if="activeSummary.current.ongoingCount > 0" id="ov-stat-ongoing" class="ov-stat ov-stat--ongoing">
            <span class="ov-stat-label">Právě probíhá</span>
            <span class="ov-stat-value">{{ activeSummary.current.ongoingCount }}</span>
          </div>
        </div>

        <!-- Per-object breakdown (detailed-mode IČOs only) -->
        <div v-if="activeSummary.current.perObject.length" id="ov-objects" class="ov-objects">
          <h3 class="ov-objects-title">Podle objektu</h3>
          <ul class="ov-object-list">
            <li
              v-for="obj in activeSummary.current.perObject"
              :key="obj.ico"
              :id="`ov-object-${obj.ico}`"
              class="ov-object-row"
            >
              <div class="ov-object-head">
                <span class="ov-object-name">{{ obj.companyName }}</span>
                <span class="ov-object-figs">
                  <span class="ov-object-visits">{{ formatVisits(obj.visitCount) }}</span>
                  <span v-if="hasTimeDataOf(activeSummary) && obj.totalMinutes > 0" class="ov-object-time">
                    · {{ formatDuration(obj.totalMinutes) }}
                  </span>
                </span>
              </div>
              <div v-if="hasTimeDataOf(activeSummary) && obj.totalMinutes > 0" class="ov-bar-track" aria-hidden="true">
                <div class="ov-bar-fill" :style="{ width: barWidth(obj.totalMinutes, activeSummary) }" />
              </div>
            </li>
          </ul>
        </div>
        <p v-else id="ov-empty" class="ov-empty">
          V tomto období zatím neproběhl žádný úklid.
        </p>
      </template>
    </div>

    </template>

    <Teleport to="body">
      <!-- Hover peek popover — "Detail záznamu" (desktop only) -->
      <div
        v-if="activeCell"
        id="day-popover"
        class="day-popover"
        :style="popoverAnchorStyle"
        @mouseenter="onPopoverMouseEnter"
        @mouseleave="onPopoverMouseLeave"
      >
        <div :id="`day-popover-header-${activeCell.key}`" class="day-popover-header">
          Detail záznamu · {{ activeCell.day }}. {{ viewMonth + 1 }}.
        </div>
        <ul v-if="activeCell.cleanings.length" :id="`cleaning-list-${activeCell.key}`" class="cleaning-list">
          <li
            v-for="(c, i) in activeCell.cleanings"
            :key="`${c.startTime || 'na'}-${c.employee}-${i}`"
            :id="`cleaning-row-${activeCell.key}-${i}`"
            class="cleaning-row"
          >
            <div :id="`cleaning-time-${activeCell.key}-${i}`" class="cleaning-time">
              <template v-if="c.ongoing">
                <span v-if="c.startTime" class="cleaning-time-ongoing">
                  {{ c.startTime }} · Probíhá
                </span>
                <span v-else class="cleaning-time-ongoing">Probíhá</span>
              </template>
              <template v-else-if="c.roundedMinutes != null">
                <span v-if="showAdminDetails" class="cleaning-time-range">
                  <template v-if="c.startTime && c.endTime">{{ c.startTime }} – {{ endLabel(c) }}</template>
                  <template v-else>—</template>
                  <span class="cleaning-time-billed">· Účtováno {{ formatDuration(c.roundedMinutes) }}</span>
                </span>
                <span v-else class="cleaning-time-billed-only">
                  <template v-if="c.startTime && c.endTime">{{ c.startTime }} – {{ endLabel(c) }} · </template>
                  {{ formatDuration(c.roundedMinutes) }}
                </span>
              </template>
              <template v-else>
                <span class="cleaning-time-range">
                  {{ c.startTime || '—' }}<template v-if="c.endTime"> – {{ endLabel(c) }}</template>
                </span>
              </template>
            </div>
            <div :id="`cleaning-emp-${activeCell.key}-${i}`" class="cleaning-emp">{{ c.employee }}</div>
          </li>
        </ul>
        <div
          v-if="activeCell.requests.total > 0"
          :id="`day-popover-requests-hint-${activeCell.key}`"
          class="day-popover-note"
        >
          {{ pluralRequests(activeCell.requests.total) }} — vyberte den pro detail
        </div>
      </div>

      <!-- Hourly breakdown popover — per-day total for a single IČO. -->
      <div
        v-if="breakdownRow"
        id="hourly-breakdown-backdrop"
        class="day-popover-backdrop"
        @click="closeHourlyBreakdown"
      />
      <div
        v-if="breakdownRow"
        id="hourly-breakdown-popover"
        class="day-popover hourly-breakdown-popover"
        :style="hourlyBreakdownAnchorStyle"
      >
        <div class="day-popover-header">
          {{ breakdownRow.companyName }} · {{ monthLabel }}
        </div>
        <ul v-if="breakdownDays.length > 0" class="hourly-breakdown-list">
          <li
            v-for="d in breakdownDays"
            :key="d.date"
            :id="`hourly-breakdown-day-${d.date}`"
            class="hourly-breakdown-row"
          >
            <span class="hb-date">{{ formatBreakdownDate(d.date) }}</span>
            <span class="hb-hours">{{ formatDuration(d.minutes) }}</span>
          </li>
        </ul>
        <div v-else class="hourly-breakdown-empty">
          <CalendarIcon :size="22" aria-hidden="true" />
          <p class="hb-empty-title">Zatím žádné úklidy</p>
          <p class="hb-empty-hint">
            Jakmile proběhne první úklid v tomto měsíci, uvidíte ho tady.
          </p>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
/* Admin preview banner — mobile-first; strip sits above the page header and
   reuses brand tokens so admins instantly recognise they are looking at a
   client's view, not their own data. */
.preview-banner {
  background: var(--color-light);
  border: 1px solid var(--color-mid);
  border-radius: var(--radius-md);
  margin-bottom: 16px;
  padding: 10px 14px;
  color: var(--color-primary);
}
.preview-banner-content {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.preview-banner-text {
  display: flex;
  align-items: baseline;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 13px;
  flex: 1 1 auto;
  min-width: 0;
}
.preview-banner-text strong {
  font-weight: 700;
}
.preview-banner-hint {
  color: var(--color-gray-700);
  font-size: 12px;
}
@media (min-width: 640px) {
  .preview-banner {
    padding: 12px 16px;
  }
  .preview-banner-text {
    font-size: 14px;
  }
}

/* Header: title left, status badge right — on phones the badge wraps under
   the title, matching the mockup's compact header. */
.attendance-header {
  flex-direction: row;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
}

/* Status badge (Úklid probíhá / Offline / Aktualizace…) */
.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: var(--radius-pill);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  white-space: nowrap;
  flex-shrink: 0;
}
.status-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
  flex-shrink: 0;
}
.status-ongoing {
  background: var(--color-success-light);
  color: var(--color-success);
}
.status-offline {
  background: var(--color-gray-100);
  color: var(--color-gray-500);
}
.status-updating {
  background: var(--color-gray-100);
  color: var(--color-gray-500);
}
.status-updating .status-dot {
  animation: status-pulse 1.2s ease-in-out infinite;
}
@keyframes status-pulse {
  0%, 100% { opacity: 1; }
  50%      { opacity: 0.3; }
}

/* Offline / upstream failure banner */
.offline-banner {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  background: var(--color-warning-light);
  border-radius: var(--radius-md);
  padding: 10px 14px;
  margin-bottom: 16px;
  font-size: 12.5px;
  line-height: 1.45;
  color: var(--color-warning);
  font-weight: 500;
}
.offline-banner-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--color-warning);
  flex-shrink: 0;
  margin-top: 5px;
}

/* Segmented view tabs (Měsíc / Rok / Přehled) */
.seg-tabs {
  display: flex;
  gap: 4px;
  background: var(--color-gray-100);
  border-radius: var(--radius-pill);
  padding: 4px;
  margin-bottom: 16px;
}
@media (min-width: 640px) {
  .seg-tabs { max-width: 480px; }
}
.seg-tab {
  flex: 1;
  border: none;
  background: transparent;
  color: var(--color-gray-600);
  font: inherit;
  font-size: var(--fs-sm);
  font-weight: 600;
  padding: 8px 12px;
  border-radius: var(--radius-pill);
  cursor: pointer;
  transition: var(--transition);
}
.seg-tab:hover { color: var(--color-primary); }
.seg-tab.is-active {
  background: var(--color-white);
  color: var(--color-primary);
  box-shadow: var(--shadow-sm);
}

/* Loading skeleton — mirrors the month layout (tabs, calendar, stat cards,
   section line, record card). */
.attendance-skeleton {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.sk-tabs {
  height: 42px;
  border-radius: var(--radius-pill);
}
@media (min-width: 640px) {
  .sk-tabs { max-width: 480px; }
}
.sk-cal {
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-xl);
  padding: var(--space-lg);
}
.sk-cal-title {
  height: 22px;
  width: 140px;
  margin-bottom: 18px;
}
.sk-cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 8px;
  justify-items: center;
}
.sk-cal-day {
  width: 100%;
  max-width: 40px;
  aspect-ratio: 1;
  border-radius: 50%;
}
.sk-stat-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.sk-stat {
  height: 76px;
  border-radius: var(--radius-lg);
}
.sk-line {
  height: 14px;
  width: 45%;
}
.sk-record {
  height: 64px;
  border-radius: var(--radius-lg);
}

/* Calendar card — white surface with a light border, per the mockup */
.cal-card {
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-xl);
  padding: var(--space-lg);
  margin-bottom: 16px;
}
@media (min-width: 640px) {
  .cal-card { padding: 24px; }
}

/* Header: month title left, controls right */
.cal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}
.cal-month {
  font-size: var(--fs-xl);
  font-weight: 700;
  color: var(--color-primary);
  margin: 0;
}
.cal-controls {
  display: flex;
  align-items: center;
  gap: 8px;
}
.nav-btn {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  border: 1px solid var(--color-gray-200);
  background: var(--color-white);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: var(--color-gray-600);
  transition: var(--transition);
}
.nav-btn:hover {
  border-color: var(--color-blue);
  color: var(--color-blue);
  background: var(--color-blue-light);
}
.today-btn {
  font-size: 12px;
  font-weight: 600;
  padding: 6px 12px;
  border-radius: var(--radius-pill);
  border: 1px solid var(--color-gray-200);
  background: var(--color-white);
  color: var(--color-gray-600);
  cursor: pointer;
  transition: var(--transition);
}
.today-btn:hover { border-color: var(--color-blue); color: var(--color-blue); }

/* Grid — mobile-first: tighter gap and smaller type at xs */
.cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 4px;
}
@media (min-width: 480px) {
  .cal-grid { gap: 6px; }
}
@media (min-width: 768px) {
  .cal-grid { gap: 8px; }
}
/* Docházka is a headline feature — the calendar is given room to breathe and
   reads big on larger screens. The width cap keeps cells from ballooning. */
@media (min-width: 1024px) {
  .cal-grid { max-width: 900px; margin-left: auto; margin-right: auto; }
}
@media (min-width: 1280px) {
  .cal-grid { max-width: 1000px; }
}
.wd-header {
  text-align: center;
  font-size: 11px;
  font-weight: 600;
  color: var(--color-gray-400);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 4px 0 10px;
}
@media (min-width: 768px) {
  .wd-header { font-size: 12px; padding: 4px 0 12px; }
}

/* Day cells — minimalist per the mockup: plain numbers, light-blue chips with a
   dot for days with records, solid blue for the selected day. */
.day-cell {
  aspect-ratio: 1;
  border-radius: 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  position: relative;
  color: var(--color-gray-700);
  transition: background var(--transition), color var(--transition);
  z-index: 0;
}
@media (min-width: 768px) {
  .day-cell { border-radius: 12px; }
}
.empty-cell { background: transparent; }
.day-num { font-size: 13px; font-weight: 500; line-height: 1; }
@media (min-width: 480px) {
  .day-num { font-size: 14px; }
}
@media (min-width: 768px) {
  .day-num { font-size: 16px; }
}
@media (min-width: 1024px) {
  .day-num { font-size: 17px; }
}

.day-past { color: var(--color-gray-400); }
.day-past .day-num { font-weight: 400; }

.day-today:not(.day-selected) {
  box-shadow: inset 0 0 0 1.5px var(--color-blue);
}
.day-today .day-num { font-weight: 700; }

.day-has-record {
  background: var(--color-blue-light);
  color: var(--color-primary);
}
.day-has-record .day-num { font-weight: 600; }
.day-ongoing:not(.day-selected) {
  background: var(--color-success-light);
}

.day-clickable { cursor: pointer; }
.day-clickable:hover:not(.day-selected) {
  background: var(--color-blue-border);
}
.day-ongoing.day-clickable:hover:not(.day-selected) {
  background: var(--color-success-light);
}

.day-selected {
  background: var(--color-blue);
  color: var(--color-white);
}
.day-selected .day-num { font-weight: 700; }

/* Record dots under the day number */
.day-dots {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  line-height: 0;
}
.day-dot {
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: var(--color-blue);
}
@media (min-width: 480px) {
  .day-dot { width: 5px; height: 5px; }
}
.day-ongoing .day-dot { background: var(--color-success); }
.day-selected .day-dot { background: var(--color-white); }

/* Single per-day request badge, colored by highest-priority status */
.day-status-badge {
  position: absolute;
  top: 2px;
  right: 2px;
  font-size: 9px;
  font-weight: 700;
  line-height: 1;
  padding: 2px 5px;
  border-radius: 8px;
  min-width: 14px;
  text-align: center;
}
@media (min-width: 480px) {
  .day-status-badge { font-size: 10px; padding: 2px 6px; min-width: 16px; }
}

/* Lift hovered cells above siblings so the badge isn't clipped. */
.day-cell:hover { z-index: 30; }

/* Calendar legend — miniature day-cell swatches so the legend reads exactly
   like the grid above it. */
.cal-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--color-gray-100);
}
@media (min-width: 1024px) {
  .cal-legend {
    max-width: 900px;
    margin-left: auto;
    margin-right: auto;
  }
}
@media (min-width: 1280px) {
  .cal-legend { max-width: 1000px; }
}
.cal-legend-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--color-gray-500);
}
.cal-legend-swatch {
  width: 18px;
  height: 18px;
  border-radius: 6px;
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  flex-shrink: 0;
}
.cal-legend-dot {
  width: 3px;
  height: 3px;
  border-radius: 50%;
}
.cal-legend-swatch--record { background: var(--color-blue-light); }
.cal-legend-swatch--record .cal-legend-dot { background: var(--color-blue); }
.cal-legend-swatch--ongoing { background: var(--color-success-light); }
.cal-legend-swatch--ongoing .cal-legend-dot { background: var(--color-success); }
.cal-legend-swatch--selected { background: var(--color-blue); }
.cal-legend-swatch--selected .cal-legend-dot { background: var(--color-white); }
.cal-legend-badge {
  font-size: 9px;
  font-weight: 700;
  line-height: 1;
  padding: 2px 5px;
  border-radius: 8px;
  min-width: 14px;
  text-align: center;
  background: var(--color-light);
  color: var(--color-primary);
  flex-shrink: 0;
}

/* Stat cards (Návštěv / Celkový čas) */
.stat-cards {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 16px;
}
.stat-card {
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-xl);
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.stat-label {
  font-size: var(--fs-xs);
  font-weight: 600;
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.stat-value-row {
  display: flex;
  align-items: baseline;
  gap: 8px;
  flex-wrap: wrap;
}
.stat-value {
  font-size: clamp(22px, 4vw + 10px, 28px);
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}
.stat-delta {
  font-size: var(--fs-xs);
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.delta-up   { color: var(--color-success); }
.delta-down { color: var(--color-danger); }
.delta-flat { color: var(--color-gray-500); }

/* Section title above Záznamy dne */
.section-title {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 12px;
  font-weight: 700;
  color: var(--color-primary);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin: 20px 0 10px;
}
.section-title-date {
  font-weight: 600;
  color: var(--color-gray-500);
  text-transform: none;
  letter-spacing: 0;
  font-variant-numeric: tabular-nums;
}

/* Záznamy dne record cards */
.record-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 16px;
}
.record-card {
  display: flex;
  align-items: center;
  gap: 12px;
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-xl);
  padding: 12px 16px;
  text-align: left;
}
.record-main {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.record-label {
  font-size: 11px;
  font-weight: 700;
  color: var(--color-blue);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.record-label--ongoing { color: var(--color-success); }
.record-label--request { color: var(--color-gray-500); }
.record-time {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--color-gray-500);
  flex-wrap: wrap;
}
.record-time-main {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-primary);
  font-variant-numeric: tabular-nums;
}
.record-time-ongoing { color: var(--color-success); }
.record-time-billed {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-gray-500);
}
.record-emp {
  font-size: 12px;
  color: var(--color-gray-500);
}
.record-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--color-blue-light);
  color: var(--color-blue);
  font-size: 12px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.record-loading {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: var(--color-gray-500);
  padding: 4px 2px;
}
.record-card--request {
  font: inherit;
  cursor: pointer;
  transition: var(--transition);
}
.record-card--request:hover {
  border-color: var(--color-blue-border);
  background: var(--color-blue-light);
}
.record-req-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-primary);
}
.record-req-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.record-req-company {
  font-size: 11px;
  color: var(--color-gray-500);
}
.record-chevron {
  color: var(--color-gray-400);
  flex-shrink: 0;
}

/* Empty month */
.month-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 12px;
  padding: 36px 20px;
}
.month-empty-icon {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: var(--color-gray-100);
  color: var(--color-gray-400);
  display: flex;
  align-items: center;
  justify-content: center;
}
.month-empty-text {
  font-size: 14px;
  font-weight: 500;
  color: var(--color-gray-400);
  margin: 0;
}

/* Overview ("Přehledy" / "Rok") — period switcher + KPI cards + per-object bars. */
.overview-card {
  padding: var(--space-lg);
  margin-bottom: 16px;
}
.ov-header {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: var(--space-md);
}
@media (min-width: 640px) {
  .ov-header {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }
}
.ov-title-wrap { display: flex; align-items: center; gap: 8px; }
.ov-title-icon { color: var(--color-mid); flex-shrink: 0; }
.ov-title {
  font-size: var(--fs-lg);
  font-weight: 700;
  color: var(--color-primary);
  margin: 0;
}
.ov-period-switch {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  background: var(--color-gray-100);
  padding: 3px;
  border-radius: var(--radius-pill);
}
.ov-period-btn {
  border: none;
  background: transparent;
  color: var(--color-gray-600);
  font: inherit;
  font-size: var(--fs-sm);
  font-weight: 600;
  padding: 6px 12px;
  border-radius: var(--radius-pill);
  cursor: pointer;
  transition: var(--transition);
}
.ov-period-btn:hover { color: var(--color-primary); }
.ov-period-btn.is-active {
  background: var(--color-white);
  color: var(--color-primary);
  box-shadow: var(--shadow-sm);
}
.ov-loading {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 20px 0;
  color: var(--color-gray-500);
  font-size: var(--fs-sm);
}
.ov-alert { margin: 8px 0 0; }
.ov-partial {
  margin: 0 0 8px;
  font-size: var(--fs-xs);
  color: var(--color-warning);
}
.ov-range {
  margin: 0 0 12px;
  font-size: var(--fs-sm);
  color: var(--color-gray-500);
  font-variant-numeric: tabular-nums;
}
.ov-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}
.ov-stat {
  flex: 1 1 140px;
  border: 1.5px solid var(--color-gray-200);
  border-radius: var(--radius-md);
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.ov-stat--ongoing {
  border-color: var(--color-warning);
  background: var(--color-ongoing-bg);
}
.ov-stat-label {
  font-size: var(--fs-xs);
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-weight: 600;
}
.ov-stat-value {
  font-size: clamp(24px, 4vw + 12px, 30px);
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}
.ov-stat-delta {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: var(--fs-xs);
  font-weight: 600;
}
.ov-delta-ref { color: var(--color-gray-500); font-weight: 500; }

.ov-objects { margin-top: 16px; }
.ov-objects-title {
  font-size: var(--fs-sm);
  font-weight: 700;
  color: var(--color-primary);
  margin: 0 0 10px;
}
.ov-object-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.ov-object-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
}
.ov-object-name {
  font-size: var(--fs-md);
  font-weight: 600;
  color: var(--color-primary);
}
.ov-object-figs {
  font-size: var(--fs-sm);
  color: var(--color-gray-600);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.ov-object-time { color: var(--color-primary); font-weight: 600; }
.ov-bar-track {
  height: 8px;
  border-radius: var(--radius-pill);
  background: var(--color-gray-100);
  overflow: hidden;
}
.ov-bar-fill {
  height: 100%;
  border-radius: var(--radius-pill);
  background: var(--color-mid);
  transition: width 0.3s ease;
}
.ov-empty {
  margin: 16px 0 0;
  font-size: var(--fs-sm);
  color: var(--color-gray-500);
  line-height: 1.4;
}

/* Hourly billing summary — mobile-first; rows stack vertically on phones and
   metrics sit side-by-side from 640px. Cards reuse the global .card class. */
.hourly-summary-card {
  padding: var(--space-lg);
  margin-bottom: 16px;
}
.hs-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: var(--space-md);
  flex-wrap: wrap;
}
.hs-title {
  font-size: var(--fs-lg);
  font-weight: 700;
  color: var(--color-primary);
  margin: 0;
}
.hs-month {
  font-size: var(--fs-sm);
  color: var(--color-gray-500);
  font-weight: 500;
}
.hs-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.hs-row {
  border-radius: var(--radius-md);
  border: 1.5px solid var(--color-gray-200);
  padding: 12px;
  background: var(--color-white);
}
.hs-row-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.hs-company {
  font-size: var(--fs-md);
  font-weight: 600;
  color: var(--color-primary);
}
.hs-ico {
  font-size: var(--fs-xs);
  color: var(--color-gray-500);
  font-variant-numeric: tabular-nums;
}
.hs-row-body {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
}
@media (min-width: 640px) {
  .hs-row-body {
    flex-direction: row;
    flex-wrap: wrap;
    gap: 20px;
  }
}
.hs-metric {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}
@media (min-width: 640px) {
  .hs-metric {
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-start;
    gap: 2px;
  }
}
.hs-metric-label {
  font-size: var(--fs-xs);
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-weight: 600;
  margin: 0;
}
.hs-metric-value {
  font-size: var(--fs-md);
  font-weight: 600;
  color: var(--color-primary);
  font-variant-numeric: tabular-nums;
  margin: 0;
}
.hs-rate {
  color: var(--color-gray-700);
  font-weight: 500;
}
.hs-amount .hs-metric-value {
  color: var(--color-accent);
}
.hs-hours-trigger {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px 2px 0;
  margin: -2px 0;
  background: transparent;
  border: none;
  border-bottom: 1.5px dashed var(--color-gray-300);
  color: inherit;
  font: inherit;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
  border-radius: var(--radius-sm);
  transition: var(--transition);
}
.hs-hours-trigger:hover,
.hs-hours-trigger:focus-visible {
  border-bottom-color: var(--color-mid);
  color: var(--color-mid);
  outline: none;
}
.hs-hours-trigger.is-open {
  border-bottom-color: var(--color-mid);
  color: var(--color-mid);
}
.hs-hours-trigger svg {
  transition: transform 0.15s ease;
}
.hs-hours-trigger.is-open svg {
  transform: rotate(180deg);
}

/* Breakdown popover: reuses the day-popover container but overrides for the
   hourly list & empty state. */
.hourly-breakdown-popover {
  min-width: 240px;
}
.hourly-breakdown-list {
  list-style: none;
  margin: 0;
  padding: 0;
}
.hourly-breakdown-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 12px;
  border-bottom: 1px solid var(--color-gray-100);
}
.hourly-breakdown-row:last-child { border-bottom: none; }
.hb-date {
  font-size: 12px;
  color: var(--color-gray-700);
  font-weight: 500;
}
.hb-hours {
  font-size: 13px;
  color: var(--color-primary);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.hourly-breakdown-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 20px 16px;
  color: var(--color-gray-500);
}
.hourly-breakdown-empty svg {
  color: var(--color-mid);
  margin-bottom: 8px;
}
.hb-empty-title {
  margin: 0 0 4px;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-primary);
}
.hb-empty-hint {
  margin: 0;
  font-size: 12px;
  line-height: 1.4;
}

.hs-footnote {
  margin: 12px 0 0;
  font-size: var(--fs-xs);
  color: var(--color-gray-500);
  line-height: 1.4;
}

/* Backdrop — full-viewport tap-outside dismiss for the hourly breakdown.
   Visible dim on mobile only; transparent on desktop. */
.day-popover-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  z-index: 9990;
}
@media (min-width: 640px) {
  .day-popover-backdrop { background: transparent; }
}

/* Popover: on mobile a bottom-sheet; on desktop anchored to the hovered cell.
   Teleported to <body>, so positioning is always relative to the viewport and
   never affected by transforms on ancestor day-cells. */
.day-popover {
  position: fixed;
  top: auto;
  bottom: calc(16px + env(safe-area-inset-bottom, 0));
  left: calc(16px + env(safe-area-inset-left, 0));
  right: calc(16px + env(safe-area-inset-right, 0));
  transform: none;
  z-index: 9991;
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
  overflow: hidden;
  text-align: left;
  max-height: 70vh;
  overflow-y: auto;
}
@media (min-width: 640px) {
  .day-popover {
    top: var(--anchor-bottom, 50%);
    left: var(--anchor-center, 50%);
    bottom: auto;
    right: auto;
    transform: translate(-50%, 6px);
    min-width: 220px;
    max-height: none;
  }
}
.day-popover-header {
  padding: 8px 12px;
  font-size: 11px;
  font-weight: 600;
  color: var(--color-gray-500);
  text-transform: uppercase;
  background: var(--color-gray-50);
  border-bottom: 1px solid var(--color-gray-200);
}
.day-popover-note {
  padding: 10px 12px;
  font-size: 12px;
  color: var(--color-gray-500);
  border-top: 1px solid var(--color-gray-100);
}
.dpi-badge {
  font-size: 10px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 10px;
  line-height: 1.4;
}

/* Cleanings list inside the popover — chronological per-cleaning rows. */
.cleaning-list {
  list-style: none;
  margin: 0;
  padding: 0;
}
.cleaning-row {
  padding: 10px 12px;
  border-bottom: 1px solid var(--color-gray-100);
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.cleaning-row:last-child { border-bottom: none; }
.cleaning-time {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-gray-500);
  letter-spacing: 0.02em;
}
.cleaning-time-ongoing {
  color: var(--color-warning, var(--color-mid));
  font-weight: 600;
}
.cleaning-time-billed {
  margin-left: 6px;
  color: var(--color-primary);
  font-weight: 600;
}
.cleaning-time-billed-only {
  color: var(--color-primary);
  font-weight: 600;
  letter-spacing: 0.02em;
}
.cleaning-emp {
  font-size: 13px;
  color: var(--color-primary);
  font-weight: 500;
}

.spin { animation: spin 2s linear infinite; }
@keyframes spin {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}

.privacy-note {
  margin-top: 16px;
  font-size: 12px;
  color: var(--color-gray-500);
  text-align: center;
}
</style>
