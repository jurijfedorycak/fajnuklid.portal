<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ChevronLeft, ChevronRight, ChevronDown, CheckCircle2, Loader2, Calendar as CalendarIcon, Phone, Mail, Eye } from 'lucide-vue-next'
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
const requestsByDay = ref({})
const openPopoverDate = ref(null)
const popoverItems = ref([])
const popoverLoading = ref(false)
const popoverAnchorRect = ref(null)
const popoverMode = ref(null)
const openHourlyBreakdownIco = ref(null)
const hourlyBreakdownAnchorRect = ref(null)

// Device hover capability — captured once at setup. Drives whether mouseenter
// on a day cell with detailed cleanings opens the popover; touch-only devices
// keep the existing click-to-open behaviour.
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

// Fetch data
async function fetchAttendance() {
  loading.value = true
  error.value = null
  try {
    const response = await attendanceService.getAttendance(
      viewYear.value,
      viewMonth.value + 1,
      previewClientId.value
    )
    if (response.success) {
      cleaningDays.value = response.data.cleaningDays || []
      hourlySummary.value = response.data.hourlySummary || []
      freshqrActive.value = response.data.freshqrActive || false
      upstreamError.value = response.data.error || null
      previewClientName.value = response.data.preview?.clientName || null
    } else {
      error.value = response.message || 'Nepodařilo se načíst data'
    }
  } catch (err) {
    error.value = err.message || 'Nepodařilo se načíst data'
  } finally {
    loading.value = false
  }
}

async function fetchRequestsForMonth() {
  // Preview mode renders only the FreshQR side of the customer view — maintenance
  // requests are scoped to the logged-in user, so calling this endpoint as an
  // admin would return the admin's own (empty) requests, not the previewed
  // client's. Skipping the call keeps the preview honest.
  if (isPreview.value) {
    requestsByDay.value = {}
    return
  }
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
      requestsByDay.value = map
    }
  } catch (e) {
    // silent
  }
}

async function loadAll() {
  await Promise.all([fetchAttendance(), fetchRequestsForMonth()])
}

onMounted(loadAll)

// Refetch when month changes or when the preview target changes mid-tab (e.g.
// the admin navigates between two client previews without a full reload).
// Close any open popover so it doesn't show stale breakdown data (or anchor to
// a card that's about to disappear).
watch([viewYear, viewMonth, previewClientId], () => {
  closeDayPopover()
  closeHourlyBreakdown()
  loadAll()
})

// When the wall-clock day rolls over while the page is open, refetch so today's
// "ongoing" state and any cleanings that started after midnight show up. The
// guard ensures we don't refetch on the very first tick (when `today` is set
// from its initial value).
watch(today, (next, prev) => {
  if (prev !== undefined) loadAll()
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

// True iff any rendered day actually carries detailed cleanings — drives the
// optional "víc úklidů v jednom dni" legend chip and the suppression of the
// "no detail" privacy note. Quiet months show the privacy note even on
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
      hasNote: cleanings.some(c => c && c.note),
      requests,
      priorityStatus: pickPriorityStatus(requests.statuses),
      isToday, isPast,
    })
  }
  return cells
})

const monthLabel = computed(() => `${MONTHS[viewMonth.value]} ${viewYear.value}`)

// The summary is shown only when the selected month is the current calendar
// month. Past months are hidden on purpose: invoiced totals can diverge from
// the raw FreshQR sum (manual adjustments, renegotiated rates, complaint
// credits) and we don't want clients comparing a provisional figure against
// the real invoice. Future months are hidden because they're always zero.
const isCurrentMonth = computed(() => {
  // today.value is 'YYYY-MM-DD'; pluck Y and M, compare zero-based month with
  // viewMonth so navigating to a past month hides the summary correctly.
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

const monthStats = computed(() => {
  const prefix = `${viewYear.value}-${String(viewMonth.value + 1).padStart(2,'0')}-`
  const days = cleaningDays.value.filter(d => d.date.startsWith(prefix))
  return {
    total: days.length,
    done: days.filter(d => !d.ongoing).length,
    ongoing: days.filter(d => d.ongoing).length,
  }
})

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

async function showDayPopover(cell, event, mode) {
  // Open whenever the day has anything to show — cleanings (Detailed mode) or
  // maintenance requests. Empty days are still inert.
  if (!cell.requests.total && !cell.cleaningsCount) return
  closeHourlyBreakdown()
  if (event && event.currentTarget && event.currentTarget.getBoundingClientRect) {
    popoverAnchorRect.value = event.currentTarget.getBoundingClientRect()
  } else {
    popoverAnchorRect.value = null
  }
  openPopoverDate.value = cell.key
  popoverMode.value = mode
  popoverItems.value = []
  if (!cell.requests.total) {
    // No requests to fetch — the popover is just the cleanings section.
    popoverLoading.value = false
    return
  }
  popoverLoading.value = true
  try {
    const res = await maintenanceRequestService.list({ date: cell.key })
    if (res.success) {
      popoverItems.value = res.data
    }
  } finally {
    popoverLoading.value = false
  }
}

function pinDayPopover(cell, event) {
  if (openPopoverDate.value === cell.key && popoverMode.value === 'pinned') {
    closeDayPopover()
    return
  }
  showDayPopover(cell, event, 'pinned')
}

function peekDayPopover(cell, event) {
  showDayPopover(cell, event, 'hover')
}

function onCellClick(cell, event) {
  // Hover-peek upgrade: a hovered popover on the same cell becomes pinned on
  // click, so moving the mouse away no longer closes it. Cancel any pending
  // hover-close timer so the upgraded popover sticks.
  if (popoverMode.value === 'hover' && openPopoverDate.value === cell.key) {
    clearTimeout(hoverOpenTimer); hoverOpenTimer = null
    clearTimeout(hoverCloseTimer); hoverCloseTimer = null
    popoverMode.value = 'pinned'
    return
  }
  pinDayPopover(cell, event)
}

function onCellMouseEnter(cell, event) {
  if (!supportsHover) return
  // Per-cell signal for "Personál a časy" data: backend only emits non-empty
  // cleanings[] for IČOs in detailed mode. Other cells keep click-only.
  if (!cell.cleaningsCount) return
  if (popoverMode.value === 'pinned') return
  clearTimeout(hoverCloseTimer); hoverCloseTimer = null
  // Capture rect now — `event.currentTarget` is nulled out by the time the
  // timer fires (synthetic events are recycled).
  const rect = event?.currentTarget?.getBoundingClientRect?.() ?? null
  const switchingFromOtherHover = popoverMode.value === 'hover' && openPopoverDate.value !== cell.key
  const delay = switchingFromOtherHover ? 0 : HOVER_OPEN_DELAY_MS
  clearTimeout(hoverOpenTimer)
  hoverOpenTimer = setTimeout(() => {
    peekDayPopover(cell, { currentTarget: { getBoundingClientRect: () => rect } })
  }, delay)
}

function onCellMouseLeave() {
  if (!supportsHover) return
  clearTimeout(hoverOpenTimer); hoverOpenTimer = null
  if (popoverMode.value !== 'hover') return
  clearTimeout(hoverCloseTimer)
  hoverCloseTimer = setTimeout(closeDayPopover, HOVER_CLOSE_DELAY_MS)
}

function onPopoverMouseEnter() {
  if (popoverMode.value !== 'hover') return
  clearTimeout(hoverCloseTimer); hoverCloseTimer = null
}

function onPopoverMouseLeave() {
  if (popoverMode.value !== 'hover') return
  clearTimeout(hoverCloseTimer)
  hoverCloseTimer = setTimeout(closeDayPopover, HOVER_CLOSE_DELAY_MS)
}

function statusMeta(key) {
  return REQUEST_STATUSES.find(s => s.key === key) || { label: key, badge: 'badge-gray' }
}

function closeDayPopover() {
  openPopoverDate.value = null
  popoverAnchorRect.value = null
  popoverMode.value = null
  if (hoverOpenTimer !== null) { clearTimeout(hoverOpenTimer); hoverOpenTimer = null }
  if (hoverCloseTimer !== null) { clearTimeout(hoverCloseTimer); hoverCloseTimer = null }
}

function goToRequest(id) {
  closeDayPopover()
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
  <div>
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

    <div class="page-header">
      <div>
        <h1 class="page-title">Docházka</h1>
        <p class="page-subtitle">Přehled úklidů na vašem místě</p>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám docházku...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
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
    <template v-if="freshqrActive">

    <!-- Upstream (FreshQR) transient failure banner — calendar still renders -->
    <div v-if="upstreamError" id="attendance-upstream-error" class="alert alert-warning">
      {{ upstreamError }}
    </div>

    <!-- Legend -->
    <div class="legend card">
      <div class="legend-item">
        <span class="legend-dot done" />
        <span>Úklid proběhl</span>
      </div>
      <div class="legend-item">
        <span class="legend-dot ongoing" />
        <span>Probíhá dnes</span>
      </div>
      <div class="legend-item">
        <span class="legend-dot empty" />
        <span>Bez úklidu</span>
      </div>
      <div v-if="hasAnyDetailedCleaning" id="legend-multi-cleanings" class="legend-item">
        <span class="legend-multi-dots" aria-hidden="true">
          <span class="dot" /><span class="dot" /><span class="dot" />
        </span>
        <span>Víc úklidů v jednom dni</span>
      </div>
    </div>

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

    <!-- Calendar card -->
    <div class="card cal-card">
      <!-- Month navigation -->
      <div class="cal-header">
        <button class="nav-btn" @click="prevMonth">
          <ChevronLeft :size="18" />
        </button>
        <div class="cal-title-wrap">
          <h2 class="cal-month">{{ monthLabel }}</h2>
          <button class="today-btn" @click="goToday">Dnes</button>
        </div>
        <button class="nav-btn" @click="nextMonth">
          <ChevronRight :size="18" />
        </button>
      </div>

      <!-- Month summary -->
      <div class="month-stats">
        <template v-if="monthStats.total > 0">
          <span class="ms-item done-text">
            <CheckCircle2 :size="14" />
            {{ monthStats.done }} {{ monthStats.done === 1 ? 'úklid' : (monthStats.done >= 2 && monthStats.done <= 4 ? 'úklidy' : 'úklidů') }} v tomto měsíci
          </span>
          <span v-if="monthStats.ongoing > 0" class="ms-item ongoing-text">
            <Loader2 :size="14" class="spin" />
            Právě probíhá
          </span>
        </template>
        <span v-else class="ms-item empty-text">V tomto měsíci se zatím neuklízelo</span>
      </div>

      <!-- Grid -->
      <div class="cal-grid">
        <div class="wd-header" v-for="wd in WEEKDAYS" :key="wd">{{ wd }}</div>

        <template v-for="(cell, idx) in calendarDays" :key="idx">
          <div v-if="cell === null" class="day-cell empty-cell" />
          <div
            v-else
            class="day-cell"
            :class="[
              {
                'day-today':   cell.isToday,
                'day-done':    cell.hasCleaning && !cell.ongoing,
                'day-ongoing': cell.ongoing,
                'day-past':    cell.isPast && !cell.hasCleaning,
                'day-future':  !cell.isPast && !cell.isToday && !cell.hasCleaning,
                'day-has-requests': cell.requests.total > 0,
                'day-popover-open': openPopoverDate === cell.key,
              },
              cell.priorityStatus ? `day-priority-${cell.priorityStatus}` : '',
            ]"
            :title="cell.requests.total > 0 ? buildRequestTooltip(cell.requests) : (cell.hasCleaning ? (cell.hasNote ? 'Úklid proběhl (s poznámkou)' : 'Úklid proběhl') : '')"
            @click="onCellClick(cell, $event)"
            @mouseenter="onCellMouseEnter(cell, $event)"
            @mouseleave="onCellMouseLeave"
          >
            <span class="day-num">{{ cell.day }}</span>
            <span v-if="cell.hasCleaning && !cell.ongoing" class="day-icon done-icon">
              <CheckCircle2 :size="12" />
            </span>
            <span v-if="cell.ongoing" class="day-icon ongoing-icon">
              <Loader2 :size="12" class="spin" />
            </span>
            <span
              v-if="cell.cleaningsCount >= 2"
              class="day-multi-dots"
              :title="`${cell.cleaningsCount} úklidů v tento den`"
              :id="'multi-dots-' + cell.key"
            >
              <span v-for="n in Math.min(cell.cleaningsCount, 3)" :key="n" class="dot" />
              <span v-if="cell.cleaningsCount > 3" class="dot-more">+</span>
            </span>
            <span v-if="cell.hasNote" class="day-note-dot" title="Poznámka k úklidu" />
            <span
              v-if="cell.requests.total > 0"
              class="day-status-badge"
              :class="`badge-${cell.priorityStatus === 'resi_se' ? 'warning' : cell.priorityStatus === 'prijato' ? 'info' : 'success'}`"
              :id="'req-badge-' + cell.key"
            >{{ cell.requests.total }}</span>
          </div>
        </template>
      </div>
    </div>

    <p v-if="!hasAnyDetailedCleaning" class="privacy-note">
      Kalendář zobrazuje pouze přítomnost úklidové služby.
      Detaily pracovníků a časy nejsou zobrazeny z důvodu ochrany soukromí.
    </p>

    </template>

    <Teleport to="body">
      <div
        v-if="activeCell && popoverMode === 'pinned'"
        id="day-popover-backdrop"
        class="day-popover-backdrop"
        @click="closeDayPopover"
      />
      <div
        v-if="activeCell"
        id="day-popover"
        class="day-popover"
        :style="popoverAnchorStyle"
        @mouseenter="onPopoverMouseEnter"
        @mouseleave="onPopoverMouseLeave"
      >
        <template v-if="activeCell.cleanings && activeCell.cleanings.length">
          <div :id="`day-popover-header-cleanings-${activeCell.key}`" class="day-popover-header">Úklidy · {{ activeCell.day }}.{{ viewMonth + 1 }}.</div>
          <ul :id="`cleaning-list-${activeCell.key}`" class="cleaning-list">
            <li
              v-for="(c, i) in activeCell.cleanings"
              :key="`${c.startTime || 'na'}-${c.employee}-${i}`"
              :id="`cleaning-row-${activeCell.key}-${i}`"
              class="cleaning-row"
            >
              <div :id="`cleaning-time-${activeCell.key}-${i}`" class="cleaning-time">
                <!-- Ongoing visit: backend sets c.ongoing only when the cleaning is
                     today AND not scanned out AND the employee hasn't moved on.
                     A null endTime alone is NOT a reliable ongoing signal — past-day
                     single-scan records also have null endTime, and rounding-rule
                     redaction nulls out endTime for finished cleanings too. -->
                <template v-if="c.ongoing">
                  <span v-if="showAdminDetails && c.startTime" class="cleaning-time-ongoing">
                    {{ c.startTime }} · Probíhá
                  </span>
                  <span v-else class="cleaning-time-ongoing">Probíhá</span>
                </template>
                <!-- Rules defined for this IČO: clients see only the billable duration; admins keep raw times alongside. -->
                <template v-else-if="c.roundedMinutes != null">
                  <span v-if="showAdminDetails" class="cleaning-time-range">
                    <template v-if="c.startTime && c.endTime">{{ c.startTime }} – {{ c.endTime }}</template>
                    <template v-else>—</template>
                    <span class="cleaning-time-billed">· Účtováno {{ formatDuration(c.roundedMinutes) }}</span>
                  </span>
                  <span v-else class="cleaning-time-billed-only">
                    Úklid · {{ formatDuration(c.roundedMinutes) }}
                  </span>
                </template>
                <!-- No rounding rules configured: fall back to the legacy raw-time range. -->
                <template v-else>
                  <span class="cleaning-time-range">
                    {{ c.startTime || '—' }}<template v-if="c.endTime"> – {{ c.endTime }}</template>
                  </span>
                </template>
              </div>
              <div :id="`cleaning-emp-${activeCell.key}-${i}`" class="cleaning-emp">{{ c.employee }}</div>
              <div v-if="c.note" :id="`cleaning-note-${activeCell.key}-${i}`" class="cleaning-note">{{ c.note }}</div>
            </li>
          </ul>
        </template>
        <template v-if="activeCell.requests && activeCell.requests.total > 0">
          <div :id="`day-popover-header-requests-${activeCell.key}`" class="day-popover-header">Požadavky · {{ activeCell.day }}.{{ viewMonth + 1 }}.</div>
          <div v-if="popoverLoading" class="day-popover-empty">Načítám…</div>
          <div v-else-if="popoverItems.length === 0" class="day-popover-empty">
            Žádné požadavky.
          </div>
          <button
            v-for="item in popoverItems"
            :key="item.id"
            :id="`day-popover-item-${item.id}`"
            class="day-popover-item"
            @click="goToRequest(item.id)"
          >
            <span class="dpi-title">{{ item.title }}</span>
            <span class="dpi-meta">
              <span class="dpi-badge" :class="statusMeta(item.status).badge">
                {{ statusMeta(item.status).label }}
              </span>
              <span v-if="item.companyName" class="dpi-company">{{ item.companyName }}</span>
            </span>
          </button>
        </template>
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

/* Legend */
.legend {
  display: flex;
  align-items: center;
  gap: 24px;
  padding: 12px 20px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.legend-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-700);
}
.legend-dot {
  width: 16px;
  height: 16px;
  border-radius: 4px;
  flex-shrink: 0;
}
.legend-dot.done    { background: #d1e7dd; border: 1.5px solid #198754; }
.legend-dot.ongoing { background: #fff0d6; border: 1.5px solid #e67e00; }
.legend-dot.empty   { background: var(--color-gray-100); border: 1.5px solid var(--color-gray-300); }

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

/* Calendar wrapper */
.cal-card { padding: var(--space-lg); }
@media (min-width: 640px) {
  .cal-card { padding: 24px; }
}

/* Header */
.cal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.cal-title-wrap {
  display: flex;
  align-items: center;
  gap: 12px;
}
.cal-month {
  font-size: var(--fs-xl);
  font-weight: 700;
  color: var(--color-primary);
  text-align: center;
  flex: 1;
}
@media (min-width: 640px) {
  .cal-month {
    min-width: 180px;
    flex: 0 0 auto;
  }
}
.nav-btn {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: 1.5px solid var(--color-gray-300);
  background: white;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: var(--color-gray-700);
  transition: var(--transition);
}
.nav-btn:hover {
  border-color: var(--color-mid);
  color: var(--color-primary);
  background: var(--color-light);
}
.today-btn {
  font-size: 12px;
  font-weight: 500;
  padding: 4px 12px;
  border-radius: 20px;
  border: 1.5px solid var(--color-gray-300);
  background: white;
  color: var(--color-gray-600);
  cursor: pointer;
  transition: var(--transition);
}
.today-btn:hover { border-color: var(--color-primary); color: var(--color-primary); }

/* Month stats strip */
.month-stats {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 20px;
  padding: 8px 0;
  border-top: 1px solid var(--color-gray-100);
  border-bottom: 1px solid var(--color-gray-100);
  min-height: 36px;
}
.ms-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 500;
}
.done-text    { color: var(--color-success); }
.ongoing-text { color: var(--color-warning); }
.empty-text   { color: var(--color-gray-500); font-weight: 400; }

/* Grid — mobile-first: tighter gap and smaller type at xs */
.cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 3px;
}
@media (min-width: 480px) {
  .cal-grid { gap: 6px; }
}
.wd-header {
  text-align: center;
  font-size: 12px;
  font-weight: 600;
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 4px 0 10px;
}

/* Day cells */
.day-cell {
  aspect-ratio: 1;
  border-radius: 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  position: relative;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.empty-cell { background: transparent; }
.day-num { font-size: 12px; font-weight: 500; line-height: 1; }
@media (min-width: 480px) {
  .day-num { font-size: 14px; }
}

.day-past    { background: var(--color-gray-100); color: var(--color-gray-400); }
.day-past .day-num { font-weight: 400; }
.day-future  { background: white; border: 1.5px solid var(--color-gray-200); color: var(--color-gray-700); }
.day-today   { border: 2px solid var(--color-primary); background: var(--color-light); color: var(--color-primary); }
.day-today .day-num { font-weight: 700; }

.day-done {
  background: #d1e7dd;
  border: 1.5px solid #a3cfbb;
  color: #0a3622;
}
.day-done:hover {
  transform: translateY(-1px);
  box-shadow: 0 3px 10px rgba(25,135,84,0.2);
}
.day-ongoing {
  background: #fff0d6;
  border: 2px solid #e67e00;
  color: #7a4200;
}
.day-ongoing:hover {
  transform: translateY(-1px);
  box-shadow: 0 3px 10px rgba(230,126,0,0.25);
}

.day-icon {
  display: none;
  align-items: center;
  justify-content: center;
  line-height: 1;
}
@media (min-width: 480px) {
  .day-icon { display: flex; }
}
.done-icon    { color: var(--color-success); }
.ongoing-icon { color: var(--color-warning); }

/* Single per-day request badge, colored by highest-priority status */
.day-cell { cursor: pointer; }
.day-status-badge {
  position: absolute;
  top: 3px;
  right: 3px;
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
.day-has-requests { box-shadow: inset 0 0 0 1.5px var(--color-gray-300); }
.day-has-requests.day-priority-prijato  { box-shadow: inset 0 0 0 1.5px var(--color-primary); }
.day-has-requests.day-priority-resi_se  { box-shadow: inset 0 0 0 1.5px var(--color-warning); }
.day-has-requests.day-priority-vyreseno { box-shadow: inset 0 0 0 1.5px var(--color-success); }

/* Lift hovered/active cells above siblings so their shadow/badge isn't clipped. */
.day-cell { z-index: 0; }
.day-cell:hover,
.day-cell.day-popover-open { z-index: 30; }

/* Backdrop — full-viewport tap-outside dismiss. Visible dim on mobile only;
   on desktop it remains interactive but transparent so the calendar stays in view. */
.day-popover-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  z-index: 9990;
}
@media (min-width: 640px) {
  .day-popover-backdrop { background: transparent; }
}

/* Popover: on mobile a bottom-sheet; on desktop anchored to the tapped day-cell.
   Teleported to <body>, so positioning is always relative to the viewport and
   never affected by transforms on ancestor day-cells (which would otherwise turn
   the day-cell into the containing block for `position: fixed`). */
.day-popover {
  position: fixed;
  top: auto;
  bottom: 16px;
  left: 16px;
  right: 16px;
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
.day-popover-item {
  display: flex;
  flex-direction: column;
  width: 100%;
  padding: 10px 12px;
  background: var(--color-white);
  border: none;
  border-bottom: 1px solid var(--color-gray-100);
  cursor: pointer;
  text-align: left;
}
.day-popover-item:last-child { border-bottom: none; }
.day-popover-item:hover { background: var(--color-gray-50); }
.dpi-title { font-size: 13px; color: var(--color-primary); font-weight: 500; }
.dpi-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 6px;
  flex-wrap: wrap;
}
.dpi-badge {
  font-size: 10px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 10px;
  line-height: 1.4;
}
.dpi-company {
  font-size: 11px;
  color: var(--color-gray-500);
}
.day-popover-empty {
  padding: 14px 12px;
  font-size: 12px;
  color: var(--color-gray-500);
  text-align: center;
}

/* Small dot indicating a note exists */
.day-note-dot {
  position: absolute;
  bottom: 5px;
  right: 6px;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: var(--color-mid);
}

/* Multi-cleaning indicator — small horizontal dot stack at the bottom of the
   day cell. Up to 3 dots; "+" suffix when there are more. Visible only on days
   with 2+ cleanings (Detailed-mode IČOs only). */
.day-multi-dots {
  position: absolute;
  bottom: 4px;
  left: 5px;
  display: inline-flex;
  align-items: center;
  gap: 2px;
}
.day-multi-dots .dot {
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: var(--color-success);
}
.day-multi-dots .dot-more {
  font-size: 9px;
  line-height: 1;
  color: var(--color-gray-600);
  margin-left: 1px;
}
@media (min-width: 480px) {
  .day-multi-dots .dot { width: 5px; height: 5px; }
}

/* Same dot stack used inline in the legend */
.legend-multi-dots {
  display: inline-flex;
  align-items: center;
  gap: 2px;
}
.legend-multi-dots .dot {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: var(--color-success);
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
.cleaning-time-open {
  margin-left: 4px;
  color: var(--color-mid);
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
.cleaning-note {
  margin-top: 4px;
  font-size: 12px;
  color: var(--color-gray-700);
  font-style: italic;
  white-space: pre-line;
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

/* .day-num + .day-icon handled mobile-first in their base declarations above. */
</style>
