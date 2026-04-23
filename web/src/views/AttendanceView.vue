<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronLeft, ChevronRight, CheckCircle2, Loader2, ClipboardList, Calendar as CalendarIcon, Phone, Mail } from 'lucide-vue-next'
import { attendanceService, maintenanceRequestService } from '../api'

const router = useRouter()

// State
const loading = ref(true)
const error = ref(null)
const upstreamError = ref(null)
const cleaningDays = ref([])
const freshqrActive = ref(false)
const requestsByDay = ref({})
const openPopoverDate = ref(null)
const popoverItems = ref([])
const popoverLoading = ref(false)

const today = new Date()
const viewYear  = ref(today.getFullYear())
const viewMonth = ref(today.getMonth())

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
    const response = await attendanceService.getAttendance(viewYear.value, viewMonth.value + 1)
    if (response.success) {
      cleaningDays.value = response.data.cleaningDays || []
      freshqrActive.value = response.data.freshqrActive || false
      upstreamError.value = response.data.error || null
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
  try {
    const res = await maintenanceRequestService.getCalendar(viewYear.value, viewMonth.value + 1)
    if (res.success) {
      const map = {}
      for (const r of res.data) {
        map[r.date] = r.count
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

// Refetch when month changes
watch([viewYear, viewMonth], loadAll)

const dayMap = computed(() => {
  const m = {}
  for (const d of cleaningDays.value) {
    m[d.date] = { note: d.note || '', ongoing: !!d.ongoing }
  }
  return m
})

const calendarDays = computed(() => {
  const year  = viewYear.value
  const month = viewMonth.value
  const firstDay = new Date(year, month, 1)
  const lastDay  = new Date(year, month + 1, 0)
  const startOffset = (firstDay.getDay() + 6) % 7
  const cells = []
  for (let i = 0; i < startOffset; i++) cells.push(null)
  for (let d = 1; d <= lastDay.getDate(); d++) {
    const key = `${year}-${String(month + 1).padStart(2,'0')}-${String(d).padStart(2,'0')}`
    const info = dayMap.value[key]
    const isToday = year === today.getFullYear() && month === today.getMonth() && d === today.getDate()
    const isPast  = new Date(year, month, d) < today && !isToday
    cells.push({
      day: d, key,
      hasCleaning: !!info,
      ongoing: info?.ongoing || false,
      note: info?.note || '',
      requestCount: requestsByDay.value[key] || 0,
      isToday, isPast,
    })
  }
  return cells
})

const monthLabel = computed(() => `${MONTHS[viewMonth.value]} ${viewYear.value}`)

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
  viewYear.value  = today.getFullYear()
  viewMonth.value = today.getMonth()
}

async function openDayPopover(cell) {
  if (!cell.requestCount) return
  if (openPopoverDate.value === cell.key) {
    openPopoverDate.value = null
    return
  }
  openPopoverDate.value = cell.key
  popoverLoading.value = true
  popoverItems.value = []
  try {
    const res = await maintenanceRequestService.list({})
    if (res.success) {
      popoverItems.value = res.data.filter(r => (r.createdAt || '').startsWith(cell.key))
    }
  } finally {
    popoverLoading.value = false
  }
}

function goToRequest(id) {
  openPopoverDate.value = null
  router.push(`/zadosti/${id}`)
}
</script>

<template>
  <div>
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
            :class="{
              'day-today':   cell.isToday,
              'day-done':    cell.hasCleaning && !cell.ongoing,
              'day-ongoing': cell.ongoing,
              'day-past':    cell.isPast && !cell.hasCleaning,
              'day-future':  !cell.isPast && !cell.isToday && !cell.hasCleaning,
              'day-has-requests': cell.requestCount > 0,
              'day-popover-open': openPopoverDate === cell.key,
            }"
            :title="cell.requestCount > 0 ? `Požadavky: ${cell.requestCount}` : (cell.hasCleaning ? (cell.note || 'Úklid proběhl') : '')"
            @click="openDayPopover(cell)"
          >
            <span class="day-num">{{ cell.day }}</span>
            <span v-if="cell.hasCleaning && !cell.ongoing" class="day-icon done-icon">
              <CheckCircle2 :size="12" />
            </span>
            <span v-if="cell.ongoing" class="day-icon ongoing-icon">
              <Loader2 :size="12" class="spin" />
            </span>
            <span v-if="cell.note" class="day-note-dot" title="Poznámka k úklidu" />
            <span v-if="cell.requestCount > 0" class="day-request-badge" :id="'req-badge-' + cell.key">
              <ClipboardList :size="9" />{{ cell.requestCount }}
            </span>

            <div
              v-if="openPopoverDate === cell.key"
              id="day-popover-backdrop"
              class="day-popover-backdrop"
              @click.stop="openPopoverDate = null"
            />
            <div v-if="openPopoverDate === cell.key" class="day-popover" @click.stop>
              <div class="day-popover-header">Požadavky · {{ cell.day }}.{{ viewMonth + 1 }}.</div>
              <div v-if="popoverLoading" style="padding:10px; font-size:12px; color:var(--color-gray-500);">Načítám…</div>
              <div v-else-if="popoverItems.length === 0" style="padding:10px; font-size:12px; color:var(--color-gray-500);">
                Žádné požadavky.
              </div>
              <button
                v-for="item in popoverItems"
                :key="item.id"
                class="day-popover-item"
                @click.stop="goToRequest(item.id)"
              >
                <span class="dpi-title">{{ item.title }}</span>
                <span class="dpi-status">{{ item.status }}</span>
              </button>
            </div>
          </div>
        </template>
      </div>
    </div>

    <p class="privacy-note">
      Kalendář zobrazuje pouze přítomnost úklidové služby.
      Detaily pracovníků a časy nejsou zobrazeny z důvodu ochrany soukromí.
    </p>

    </template>
  </div>
</template>

<style scoped>
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

/* Request badge */
.day-cell { cursor: pointer; }
.day-request-badge {
  position: absolute;
  top: 4px;
  right: 4px;
  display: inline-flex;
  align-items: center;
  gap: 2px;
  padding: 2px 5px;
  border-radius: 10px;
  background: var(--color-primary);
  color: var(--color-white);
  font-size: 10px;
  font-weight: 600;
  line-height: 1;
}
.day-has-requests { box-shadow: inset 0 0 0 1.5px var(--color-primary); }

/* Lift the day cell (and its .day-popover / badge) above sibling grid cells.
   The hover transform creates a stacking context that traps inner content,
   so later grid rows paint over shadows/badges/popover without this. */
.day-cell { z-index: 0; }
.day-cell:hover,
.day-cell.day-popover-open,
.day-cell.day-popover-open:hover { z-index: 30; }

/* Backdrop — mobile-only tap-outside dismiss for the bottom-sheet popover */
.day-popover-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  z-index: 19;
}
@media (min-width: 640px) {
  .day-popover-backdrop { display: none; }
}

/* Popover: on mobile becomes a centered bottom-sheet anchored to the viewport,
   avoiding clipping when the tapped day cell is near a screen edge. */
.day-popover {
  position: fixed;
  top: auto;
  bottom: 16px;
  left: 16px;
  right: 16px;
  transform: none;
  z-index: 20;
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
    position: absolute;
    top: 100%;
    bottom: auto;
    left: 50%;
    right: auto;
    transform: translateX(-50%);
    margin-top: 6px;
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
.dpi-status { font-size: 11px; color: var(--color-gray-500); margin-top: 2px; }

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
