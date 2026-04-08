<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { RouterLink } from 'vue-router'
import { Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS, ArcElement, Tooltip, Legend
} from 'chart.js'
import {
  Calendar, ChevronLeft, ChevronRight, ArrowRight,
  CheckCircle2, Loader2, Clock, Check,
} from 'lucide-vue-next'
import { dashboardService } from '../api'
import { useAuth } from '../stores/auth'

ChartJS.register(ArcElement, Tooltip, Legend)

const { user } = useAuth()

// ── State ────────────────────────────────────────────────────────────────────
const loading = ref(true)
const refetching = ref(false)
const error = ref(null)
// Guards against double-fetch on initial mount when the watcher fires after
// the server-validated activeIco syncs back into local state.
let initialFetchDone = false
// Token used to discard responses from superseded fetches (e.g. rapid IČO clicks).
let fetchToken = 0

const today = new Date()
const startOfYearStr = `${today.getFullYear()}-01-01`
const todayStr = formatISODate(today)

const range = ref({ from: startOfYearStr, to: todayStr })
const activeIco = ref(user.value?.active_ico || null)

const dashboardData = ref({
  currentUser: null,
  companies: [],
  activeIco: null,
  dateRange: { from: startOfYearStr, to: todayStr },
  overview: {
    invoices: { total: 0, paidCount: 0, unpaidCount: 0, overdueCount: 0, nextDue: null },
    personnel: { count: 0, locationName: '' },
    contract: { hasPdf: false, contractsEnabled: false },
  },
  cleaningDays: [],
  personnelList: [],
  recentInvoices: [],
})

// ── Fetch ────────────────────────────────────────────────────────────────────
async function fetchDashboard(initial = false) {
  const myToken = ++fetchToken
  if (initial) {
    loading.value = true
  } else {
    refetching.value = true
  }
  error.value = null
  try {
    const response = await dashboardService.getDashboard({
      ico: activeIco.value || undefined,
      from: range.value.from,
      to: range.value.to,
    })
    // Discard stale responses (user clicked a different IČO mid-flight)
    if (myToken !== fetchToken) return
    if (response.success) {
      dashboardData.value = response.data
      // Sync local state with server-validated values (e.g. fallback IČO).
      // Set initialFetchDone BEFORE the assignment so the watcher can fire
      // exactly once if the value actually changes.
      if (response.data.activeIco && response.data.activeIco !== activeIco.value) {
        // Suppress watcher reaction to this server-driven sync.
        suppressWatch = true
        activeIco.value = response.data.activeIco
      }
      // Reset personnel paginator when data set changes
      personnelPage.value = 0
    } else {
      error.value = response.message || 'Nepodařilo se načíst data'
    }
  } catch (err) {
    if (myToken !== fetchToken) return
    error.value = err.response?.data?.message || err.message || 'Nepodařilo se načíst data'
  } finally {
    if (myToken === fetchToken) {
      loading.value = false
      refetching.value = false
      initialFetchDone = true
    }
  }
}

let suppressWatch = false

onMounted(() => fetchDashboard(true))

watch([activeIco, () => range.value.from, () => range.value.to], () => {
  if (suppressWatch) {
    suppressWatch = false
    return
  }
  if (!initialFetchDone) return
  fetchDashboard(false)
})

// ── Computed slices ─────────────────────────────────────────────────────────
const companies = computed(() => dashboardData.value.companies || [])
const overview = computed(() => dashboardData.value.overview || {})
const invoicesOverview = computed(() => overview.value.invoices || {})
const personnelOverview = computed(() => overview.value.personnel || {})
const contract = computed(() => overview.value.contract || { hasPdf: false })
const personnelList = computed(() => dashboardData.value.personnelList || [])
const recentInvoices = computed(() => dashboardData.value.recentInvoices || [])
const cleaningDays = computed(() => dashboardData.value.cleaningDays || [])

const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return 'Dobré ráno'
  if (h < 18) return 'Dobré odpoledne'
  return 'Dobrý večer'
})

const displayFirstName = computed(() => {
  const fullName = dashboardData.value.currentUser?.displayName
    || user.value?.display_name
    || user.value?.email
    || 'Klient'
  return fullName.split(' ')[0]
})

// ── Donut chart ─────────────────────────────────────────────────────────────
// Read color tokens from the CSS custom properties defined in style.css
// (CLAUDE.md rule 3: never hardcode color values).
function cssVar(name) {
  if (typeof window === 'undefined') return ''
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
}

const chartData = computed(() => ({
  labels: ['Zaplaceno', 'Nezaplaceno', 'Po splatnosti'],
  datasets: [{
    data: [
      invoicesOverview.value.paidCount || 0,
      invoicesOverview.value.unpaidCount || 0,
      invoicesOverview.value.overdueCount || 0,
    ],
    backgroundColor: [
      cssVar('--color-success'),
      cssVar('--color-gray-300'),
      cssVar('--color-danger'),
    ],
    borderWidth: 0,
    hoverOffset: 4,
  }],
}))

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: { label: (ctx) => ` ${ctx.label}: ${ctx.raw} faktur` },
    },
  },
  cutout: '70%',
}

// ── Date range picker ───────────────────────────────────────────────────────
const datePickerOpen = ref(false)
const customFrom = ref(range.value.from)
const customTo = ref(range.value.to)
const datePickerWrapRef = ref(null)

function onDocumentClick(e) {
  if (!datePickerOpen.value) return
  if (datePickerWrapRef.value && !datePickerWrapRef.value.contains(e.target)) {
    datePickerOpen.value = false
  }
}

function onDocumentKeydown(e) {
  if (e.key === 'Escape' && datePickerOpen.value) {
    datePickerOpen.value = false
    // Return focus to the trigger button for accessibility
    nextTick(() => document.getElementById('dashboard-date-range-btn')?.focus())
  }
}

onMounted(() => {
  document.addEventListener('mousedown', onDocumentClick)
  document.addEventListener('keydown', onDocumentKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocumentClick)
  document.removeEventListener('keydown', onDocumentKeydown)
})

const PRESETS = [
  { id: 'thisMonth', label: 'Tento měsíc' },
  { id: 'lastMonth', label: 'Minulý měsíc' },
  { id: 'thisYear', label: 'Tento rok' },
  { id: 'lastYear', label: 'Minulý rok' },
  { id: 'custom', label: 'Vlastní' },
]

const activePreset = ref('thisYear')

function applyPreset(id) {
  const now = new Date()
  let from, to
  if (id === 'thisMonth') {
    from = new Date(now.getFullYear(), now.getMonth(), 1)
    to = now
  } else if (id === 'lastMonth') {
    from = new Date(now.getFullYear(), now.getMonth() - 1, 1)
    to = new Date(now.getFullYear(), now.getMonth(), 0)
  } else if (id === 'thisYear') {
    from = new Date(now.getFullYear(), 0, 1)
    to = now
  } else if (id === 'lastYear') {
    from = new Date(now.getFullYear() - 1, 0, 1)
    to = new Date(now.getFullYear() - 1, 11, 31)
  } else {
    activePreset.value = 'custom'
    return
  }
  activePreset.value = id
  range.value = { from: formatISODate(from), to: formatISODate(to) }
  customFrom.value = range.value.from
  customTo.value = range.value.to
  datePickerOpen.value = false
}

function applyCustomRange() {
  if (!customFrom.value || !customTo.value) return
  let from = customFrom.value
  let to = customTo.value
  if (from > to) [from, to] = [to, from]
  range.value = { from, to }
  activePreset.value = 'custom'
  datePickerOpen.value = false
}

function cancelCustomRange() {
  customFrom.value = range.value.from
  customTo.value = range.value.to
  datePickerOpen.value = false
}

const dateRangeLabel = computed(() => `${formatCsDate(range.value.from)} – ${formatCsDate(range.value.to)}`)

// ── Personnel pagination ────────────────────────────────────────────────────
const personnelPage = ref(0)
const PERSONNEL_PER_PAGE = 2

const personnelTotalPages = computed(() => Math.max(1, Math.ceil(personnelList.value.length / PERSONNEL_PER_PAGE)))

const personnelVisible = computed(() => {
  const start = personnelPage.value * PERSONNEL_PER_PAGE
  return personnelList.value.slice(start, start + PERSONNEL_PER_PAGE)
})

function personnelPrev() {
  if (personnelPage.value > 0) personnelPage.value--
}

function personnelNext() {
  if (personnelPage.value < personnelTotalPages.value - 1) personnelPage.value++
}

// ── Cleaning summary ────────────────────────────────────────────────────────
const cleaningStats = computed(() => {
  const stats = { done: 0, ongoing: 0, scheduled: 0 }
  for (const d of cleaningDays.value) {
    if (d.status === 'done') stats.done++
    else if (d.status === 'ongoing') stats.ongoing++
    else if (d.status === 'scheduled') stats.scheduled++
  }
  return stats
})

const MONTHS = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec']
const currentMonthLabel = computed(() => `${MONTHS[today.getMonth()]} ${today.getFullYear()}`)

function cleaningDayNumber(dateStr) {
  if (!dateStr) return ''
  const parts = dateStr.split('-')
  return parts[2] ? parseInt(parts[2], 10) : ''
}

// ── Helpers ─────────────────────────────────────────────────────────────────
function formatISODate(d) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function formatCsDate(iso) {
  if (!iso) return ''
  const [y, m, d] = iso.split('-')
  return `${parseInt(d, 10)}. ${parseInt(m, 10)}. ${y}`
}

function formatAmount(n, currency = 'Kč') {
  return `${Number(n).toLocaleString('cs-CZ')} ${currency}`
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').filter(Boolean).map(w => w[0]).join('').slice(0, 2).toUpperCase()
}

function statusBadge(status) {
  if (status === 'paid') return { cls: 'badge-success', label: 'Zaplaceno' }
  if (status === 'overdue') return { cls: 'badge-danger', label: 'Po splatnosti' }
  return { cls: 'badge-info', label: 'Nezaplaceno' }
}

function selectCompany(ico) {
  if (ico === activeIco.value) return
  activeIco.value = ico
}
</script>

<template>
  <div id="dashboard-page">
    <!-- Loading state -->
    <div
      v-if="loading"
      id="dashboard-loading"
      class="card dashboard-loading-state"
      role="status"
      aria-live="polite"
    >
      <Loader2 :size="32" class="spin dashboard-loading-icon" />
      <p class="dashboard-loading-text">Načítám přehled...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" id="dashboard-error" class="alert alert-danger dashboard-error-state">
      <span>{{ error }}</span>
      <button id="dashboard-error-retry-btn" type="button" class="btn btn-sm btn-outline" @click="fetchDashboard(true)">
        Zkusit znovu
      </button>
    </div>

    <!-- Content -->
    <template v-else>
      <!-- Header: greeting + IČO switcher -->
      <header id="dashboard-header" class="dashboard-header">
        <h1 id="dashboard-greeting" class="dashboard-greeting">
          {{ greeting }}, {{ displayFirstName }} <span aria-hidden="true">👋</span>
        </h1>
        <div
          v-if="companies.length >= 2"
          id="dashboard-company-switcher"
          class="company-switcher"
          role="tablist"
          aria-label="Přepínač IČO"
        >
          <button
            v-for="company in companies"
            :key="company.id"
            :id="`dashboard-company-tile-${company.id}`"
            type="button"
            class="company-tile"
            :class="{ active: company.ico === activeIco }"
            role="tab"
            :aria-selected="company.ico === activeIco"
            @click="selectCompany(company.ico)"
          >
            <span class="avatar avatar-sm company-tile-avatar">{{ initials(company.name) }}</span>
            <span class="company-tile-text">
              <span class="company-tile-name">{{ company.name }}</span>
              <span class="company-tile-ico">IČO: {{ company.ico }}</span>
            </span>
          </button>
        </div>
      </header>

      <!-- Overview card -->
      <section id="dashboard-overview-card" class="card overview-card" :class="{ 'is-refetching': refetching }">
        <div class="overview-head">
          <h3 id="dashboard-overview-title" class="overview-title">Celkový přehled</h3>
          <div ref="datePickerWrapRef" class="date-picker-wrap">
            <button
              id="dashboard-date-range-btn"
              type="button"
              class="date-range-btn"
              :aria-expanded="datePickerOpen"
              aria-haspopup="dialog"
              aria-controls="dashboard-date-picker-popover"
              @click="datePickerOpen = !datePickerOpen"
            >
              <Calendar :size="16" />
              <span>{{ dateRangeLabel }}</span>
            </button>
            <div
              v-if="datePickerOpen"
              id="dashboard-date-picker-popover"
              class="date-popover"
              role="group"
              aria-label="Vybrat období"
            >
              <div class="chip-group date-presets">
                <button
                  v-for="preset in PRESETS"
                  :key="preset.id"
                  :id="`dashboard-date-preset-${preset.id}`"
                  type="button"
                  class="chip"
                  :class="{ active: activePreset === preset.id }"
                  @click="applyPreset(preset.id)"
                >
                  {{ preset.label }}
                </button>
              </div>
              <div v-if="activePreset === 'custom'" id="dashboard-date-custom" class="date-custom">
                <div class="form-group">
                  <label for="dashboard-date-custom-from" class="form-label">Od</label>
                  <input id="dashboard-date-custom-from" v-model="customFrom" type="date" class="form-input" />
                </div>
                <div class="form-group">
                  <label for="dashboard-date-custom-to" class="form-label">Do</label>
                  <input id="dashboard-date-custom-to" v-model="customTo" type="date" class="form-input" />
                </div>
                <div class="date-custom-actions">
                  <button id="dashboard-date-custom-cancel" type="button" class="btn btn-ghost btn-sm" @click="cancelCustomRange">Zrušit</button>
                  <button id="dashboard-date-custom-apply" type="button" class="btn btn-primary btn-sm" @click="applyCustomRange">Použít</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="overview-metrics">
          <div id="dashboard-metric-invoices" class="metric">
            <div class="metric-label">Faktur celkem</div>
            <div class="metric-row">
              <div class="metric-value">{{ invoicesOverview.total }}</div>
              <div class="metric-badges">
                <span v-if="invoicesOverview.overdueCount > 0" class="badge badge-danger">
                  {{ invoicesOverview.overdueCount }} po splatnosti
                </span>
                <span v-if="invoicesOverview.unpaidCount > 0" class="badge badge-info">
                  {{ invoicesOverview.unpaidCount }} čeká
                </span>
              </div>
            </div>
          </div>

          <div id="dashboard-metric-next-due" class="metric">
            <div class="metric-label">Nejbližší splatnost za</div>
            <div class="metric-row">
              <div v-if="invoicesOverview.nextDue" class="metric-value">
                {{ invoicesOverview.nextDue.daysRelative }} dní
              </div>
              <div v-else class="metric-value metric-value-ok">Vše splaceno</div>
              <div v-if="invoicesOverview.nextDue" class="metric-badges">
                <span class="badge badge-info">{{ invoicesOverview.nextDue.documentNumber }}</span>
              </div>
            </div>
          </div>

          <div id="dashboard-metric-personnel" class="metric">
            <div class="metric-label">Přiřazených pracovníků</div>
            <div class="metric-row">
              <div class="metric-value">{{ personnelOverview.count }}</div>
              <div v-if="personnelOverview.locationName" class="metric-badges">
                <span class="badge badge-info">Vaše místo: {{ personnelOverview.locationName }}</span>
              </div>
            </div>
          </div>

          <div id="dashboard-metric-contract" class="metric">
            <div class="metric-label">Nahraná smlouva</div>
            <div class="metric-row metric-row-contract">
              <Check v-if="contract.hasPdf" :size="22" class="metric-contract-icon ok" aria-label="Smlouva nahrána" />
              <span v-else class="metric-contract-icon missing" aria-label="Smlouva chybí">!</span>
              <RouterLink v-if="contract.hasPdf" id="dashboard-metric-contract-link" to="/smlouva" class="metric-link">
                Zobrazit smlouvu <ArrowRight :size="14" />
              </RouterLink>
              <span v-else class="metric-link metric-link-missing">Smlouva zatím nenahrána</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Mid row: Cleanings + Personnel -->
      <section id="dashboard-mid-row" class="dashboard-mid-row">
        <article id="dashboard-cleaning-card" class="card cleaning-card">
          <div class="card-header-row">
            <h3 id="dashboard-cleaning-title" class="card-title">Úklidy – {{ currentMonthLabel }}</h3>
            <RouterLink id="dashboard-cleaning-detail-link" to="/dochazka" class="card-link">
              Detail <ArrowRight :size="14" />
            </RouterLink>
          </div>

          <div class="cleaning-summary">
            <span class="cs-pill cs-done">
              <CheckCircle2 :size="13" />
              {{ cleaningStats.done }} proběhlo
            </span>
            <span v-if="cleaningStats.ongoing > 0" class="cs-pill cs-ongoing">
              <Loader2 :size="13" class="spin" />
              Právě probíhá
            </span>
            <span v-if="cleaningStats.scheduled > 0" class="cs-pill cs-scheduled">
              <Clock :size="13" />
              {{ cleaningStats.scheduled }} naplánované
            </span>
          </div>

          <div v-if="cleaningDays.length > 0" id="dashboard-cleaning-strip" class="day-strip">
            <div
              v-for="cell in cleaningDays"
              :key="cell.date"
              :id="`dashboard-cleaning-day-${cell.date}`"
              class="ds-cell"
              :class="{
                'ds-done': cell.status === 'done',
                'ds-ongoing': cell.status === 'ongoing',
                'ds-scheduled': cell.status === 'scheduled',
              }"
              :title="cell.note || cell.date"
            >
              <span class="ds-num">{{ cleaningDayNumber(cell.date) }}</span>
              <CheckCircle2 v-if="cell.status === 'done'" :size="11" class="ds-icon" />
              <Loader2 v-else-if="cell.status === 'ongoing'" :size="11" class="ds-icon spin" />
              <Clock v-else-if="cell.status === 'scheduled'" :size="11" class="ds-icon" />
            </div>
          </div>
          <div v-else id="dashboard-cleaning-empty" class="cleaning-empty">
            Pro toto období zatím nejsou k dispozici žádné úklidy.
          </div>
        </article>

        <article id="dashboard-personnel-card" class="card personnel-card">
          <div class="card-header-row">
            <h3 id="dashboard-personnel-title" class="card-title">Přiřazení pracovníci</h3>
            <div class="personnel-header-actions">
              <div v-if="personnelList.length > PERSONNEL_PER_PAGE" class="personnel-paginator">
                <button
                  id="dashboard-personnel-prev-btn"
                  type="button"
                  class="paginator-btn"
                  :disabled="personnelPage === 0"
                  @click="personnelPrev"
                  aria-label="Předchozí pracovníci"
                >
                  <ChevronLeft :size="16" />
                </button>
                <span id="dashboard-personnel-page-indicator" class="paginator-text">
                  {{ personnelPage + 1 }}/{{ personnelTotalPages }}
                </span>
                <button
                  id="dashboard-personnel-next-btn"
                  type="button"
                  class="paginator-btn"
                  :disabled="personnelPage >= personnelTotalPages - 1"
                  @click="personnelNext"
                  aria-label="Další pracovníci"
                >
                  <ChevronRight :size="16" />
                </button>
              </div>
              <RouterLink id="dashboard-personnel-all-link" to="/personal" class="card-link">
                Všichni pracovníci <ArrowRight :size="14" />
              </RouterLink>
            </div>
          </div>

          <div v-if="personnelVisible.length > 0" id="dashboard-personnel-list" class="personnel-list">
            <div
              v-for="staff in personnelVisible"
              :key="staff.id"
              :id="`dashboard-personnel-card-${staff.id}`"
              class="personnel-row"
            >
              <div class="avatar avatar-md personnel-avatar">
                <img v-if="staff.photoUrl" :src="staff.photoUrl" :alt="staff.name" />
                <span v-else>{{ initials(staff.name) }}</span>
              </div>
              <div class="personnel-info">
                <div class="personnel-name">{{ staff.name }}</div>
                <div v-if="staff.role" class="personnel-role">{{ staff.role }}</div>
              </div>
            </div>
          </div>
          <div v-else id="dashboard-personnel-empty" class="cleaning-empty">
            Pro tento účet zatím nejsou přiřazeni pracovníci.
          </div>
        </article>
      </section>

      <!-- Bottom row: Donut + Recent invoices -->
      <section id="dashboard-bottom-row" class="dashboard-bottom-row">
        <article id="dashboard-chart-card" class="card chart-card">
          <h3 id="dashboard-chart-title" class="card-title">Přehled faktur</h3>
          <div id="dashboard-chart-wrap" class="chart-wrap">
            <Doughnut :data="chartData" :options="chartOptions" />
          </div>
          <div class="chart-totals">
            <div id="dashboard-chart-legend-paid" class="total-row">
              <span class="total-dot total-dot-paid" />
              <span>Zaplaceno</span>
              <strong>{{ invoicesOverview.paidCount || 0 }}</strong>
            </div>
            <div id="dashboard-chart-legend-unpaid" class="total-row">
              <span class="total-dot total-dot-unpaid" />
              <span>Nezaplaceno</span>
              <strong>{{ invoicesOverview.unpaidCount || 0 }}</strong>
            </div>
            <div id="dashboard-chart-legend-overdue" class="total-row">
              <span class="total-dot total-dot-overdue" />
              <span>Po splatnosti</span>
              <strong>{{ invoicesOverview.overdueCount || 0 }}</strong>
            </div>
          </div>
        </article>

        <article id="dashboard-recent-invoices-card" class="card recent-card">
          <div class="card-header-row">
            <h3 id="dashboard-recent-title" class="card-title">Poslední faktury</h3>
            <RouterLink id="dashboard-recent-all-link" to="/faktury" class="card-link">
              Zobrazit vše <ArrowRight :size="14" />
            </RouterLink>
          </div>
          <div v-if="recentInvoices.length > 0" class="table-wrap">
            <table id="dashboard-recent-invoices-table" class="data-table">
              <thead>
                <tr>
                  <th>Číslo</th>
                  <th>Splatnost</th>
                  <th class="text-right">Částka</th>
                  <th>Stav</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="inv in recentInvoices" :id="`dashboard-recent-row-${inv.id}`" :key="inv.id">
                  <td class="fw-500">{{ inv.documentNumber }}</td>
                  <td>{{ formatCsDate(inv.dueDate) }}</td>
                  <td class="text-right fw-500">{{ formatAmount(inv.amount, inv.currency || 'Kč') }}</td>
                  <td>
                    <span class="badge" :class="statusBadge(inv.status).cls">
                      {{ statusBadge(inv.status).label }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else id="dashboard-recent-empty" class="cleaning-empty">
            Pro vybrané období nejsou žádné faktury.
          </div>
        </article>
      </section>
    </template>
  </div>
</template>

<style scoped>
/* ── Loading state ──────────────────────────────────────────────────────── */
.dashboard-error-state {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.dashboard-loading-state {
  padding: 40px;
  text-align: center;
}

.dashboard-loading-icon {
  color: var(--color-accent);
}

.dashboard-loading-text {
  margin-top: 12px;
  color: var(--color-gray-600);
}

/* ── Header ─────────────────────────────────────────────────────────────── */
.dashboard-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24px;
  margin-bottom: 24px;
  flex-wrap: wrap;
}

.dashboard-greeting {
  font-size: 24px;
  font-weight: 600;
  color: var(--color-primary);
  line-height: 1.2;
}

.company-switcher {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.company-tile {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: var(--radius-lg);
  background: var(--card-bg);
  border: 1.5px solid transparent;
  cursor: pointer;
  transition: var(--transition);
  text-align: left;
  min-width: 180px;
  opacity: 0.7;
}

.company-tile:hover {
  opacity: 1;
  border-color: var(--color-gray-200);
}

.company-tile.active {
  opacity: 1;
  border-color: var(--color-gray-300);
  background: var(--color-white);
  box-shadow: var(--shadow-sm);
}

.company-tile-avatar {
  background: var(--color-primary);
  color: var(--color-white);
}

.company-tile-text {
  display: flex;
  flex-direction: column;
}

.company-tile-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-primary);
}

.company-tile-ico {
  font-size: 11px;
  color: var(--color-gray-500);
}

/* ── Overview card ──────────────────────────────────────────────────────── */
.overview-card {
  margin-bottom: 24px;
  transition: opacity 0.2s ease;
}

.overview-card.is-refetching {
  opacity: 0.6;
}

.overview-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.overview-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-primary);
}

.date-picker-wrap {
  position: relative;
}

.date-range-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  border-radius: var(--radius-md);
  background: var(--color-white);
  border: 1.5px solid var(--color-gray-200);
  font-size: 13px;
  font-weight: 500;
  color: var(--color-primary);
  cursor: pointer;
  transition: var(--transition);
}

.date-range-btn:hover {
  border-color: var(--color-accent);
}

.date-popover {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 30;
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
  padding: 16px;
  min-width: 280px;
}

.date-presets {
  margin-bottom: 0;
}

.date-custom {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--color-gray-200);
}

.date-custom .form-group {
  margin-bottom: 10px;
}

.date-custom-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}

.overview-metrics {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 24px;
}

.metric {
  display: flex;
  flex-direction: column;
  gap: 8px;
  position: relative;
}

.metric + .metric {
  padding-left: 24px;
  border-left: 1px solid var(--color-gray-200);
}

.metric-label {
  font-size: 13px;
  color: var(--color-gray-600);
  font-weight: 500;
}

.metric-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.metric-value {
  font-size: 28px;
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.1;
}

.metric-value-ok {
  font-size: 18px;
  color: var(--color-success);
}

.metric-badges {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.metric-row-contract {
  align-items: center;
}

.metric-contract-icon {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 18px;
}

.metric-contract-icon.ok {
  background: var(--color-success-light);
  color: var(--color-success);
}

.metric-contract-icon.missing {
  background: var(--color-danger-light);
  color: var(--color-danger);
}

.metric-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  color: var(--color-accent);
}

.metric-link:hover {
  color: var(--color-primary);
}

.metric-link-missing {
  color: var(--color-gray-500);
}

/* ── Mid row ────────────────────────────────────────────────────────────── */
.dashboard-mid-row {
  display: grid;
  grid-template-columns: 1fr 360px;
  gap: 16px;
  margin-bottom: 24px;
  align-items: start;
}

.card-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-primary);
}

.card-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.card-link {
  font-size: 13px;
  color: var(--color-accent);
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.card-link:hover {
  color: var(--color-primary);
}

/* Cleaning card */
.cleaning-summary {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.cs-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 12px;
  border-radius: var(--radius-pill);
  font-size: 12px;
  font-weight: 500;
}

.cs-done {
  background: var(--color-success-light);
  color: var(--color-success);
}

.cs-ongoing {
  background: var(--color-white);
  color: var(--color-primary);
  border: 1px solid var(--color-gray-200);
}

.cs-scheduled {
  background: var(--color-light);
  color: var(--color-primary);
}

.day-strip {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.ds-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  width: 52px;
  height: 60px;
  border-radius: var(--radius-md);
  flex-shrink: 0;
  transition: transform 0.12s ease;
}

.ds-cell:hover {
  transform: translateY(-2px);
}

.ds-num {
  font-size: 16px;
  font-weight: 600;
  line-height: 1;
  color: var(--color-primary);
}

.ds-done {
  background: var(--color-success-light);
}

.ds-done .ds-icon {
  color: var(--color-success);
}

.ds-ongoing {
  background: var(--color-white);
  border: 1.5px solid var(--color-primary);
}

.ds-ongoing .ds-icon {
  color: var(--color-primary);
}

.ds-scheduled {
  background: var(--color-light);
}

.ds-scheduled .ds-icon {
  color: var(--color-accent);
}

.cleaning-empty {
  font-size: 13px;
  color: var(--color-gray-500);
  padding: 16px 0;
}

/* Personnel card */
.personnel-header-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.personnel-paginator {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  color: var(--color-gray-600);
}

.paginator-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: var(--radius-sm);
  background: transparent;
  border: none;
  color: var(--color-gray-600);
  cursor: pointer;
  transition: var(--transition);
}

.paginator-btn:hover:not(:disabled) {
  background: var(--color-gray-100);
  color: var(--color-primary);
}

.paginator-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.paginator-text {
  min-width: 28px;
  text-align: center;
}

.personnel-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.personnel-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px;
  border-radius: var(--radius-md);
}

.personnel-avatar {
  background: var(--color-accent);
  overflow: hidden;
}

.personnel-info {
  display: flex;
  flex-direction: column;
}

.personnel-name {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-primary);
}

.personnel-role {
  font-size: 12px;
  color: var(--color-gray-500);
}

/* ── Bottom row ─────────────────────────────────────────────────────────── */
.dashboard-bottom-row {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 16px;
  align-items: start;
}

.chart-card .card-title {
  margin-bottom: 16px;
}

.chart-wrap {
  height: 200px;
  position: relative;
}

.chart-totals {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.total-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-700);
}

.total-row strong {
  margin-left: auto;
  font-weight: 600;
  color: var(--color-primary);
}

.total-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}

.total-dot-paid {
  background: var(--color-success);
}

.total-dot-unpaid {
  background: var(--color-gray-300);
}

.total-dot-overdue {
  background: var(--color-danger);
}

.recent-card .card-title {
  margin-bottom: 0;
}

/* ── Spin animation ─────────────────────────────────────────────────────── */
.spin {
  animation: spin 1.5s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

/* ── Responsive ─────────────────────────────────────────────────────────── */
@media (max-width: 1100px) {
  .overview-metrics {
    grid-template-columns: repeat(2, 1fr);
    row-gap: 24px;
  }
  .metric + .metric {
    padding-left: 0;
    border-left: none;
  }
  .metric:nth-child(odd) {
    padding-right: 24px;
    border-right: 1px solid var(--color-gray-200);
  }
  .dashboard-mid-row,
  .dashboard-bottom-row {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .overview-metrics {
    grid-template-columns: 1fr;
  }
  .metric:nth-child(odd) {
    padding-right: 0;
    border-right: none;
  }
  .dashboard-greeting {
    font-size: 20px;
  }
  .company-tile {
    min-width: 0;
    flex: 1 1 calc(50% - 6px);
  }
}
</style>
