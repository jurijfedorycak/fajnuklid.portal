<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS, ArcElement, Tooltip, Legend
} from 'chart.js'
import {
  FileText, Calendar, Users, FileSignature, Phone, Mail, ArrowRight,
  CheckCircle2, Loader2,
} from 'lucide-vue-next'
import { dashboardService } from '../api'
import { useAuth } from '../stores/auth'

ChartJS.register(ArcElement, Tooltip, Legend)

const { user } = useAuth()

// State
const loading = ref(true)
const error = ref(null)
const dashboardData = ref({
  personnelCount: 0,
  contract: { contractsEnabled: false, hasPdf: false },
  contacts: [],
  cleaningDays: [],
  locations: [],
})

// Fetch dashboard data
onMounted(async () => {
  try {
    const response = await dashboardService.getDashboard()
    if (response.success) {
      dashboardData.value = response.data
    } else {
      error.value = response.message || 'Nepodařilo se načíst data'
    }
  } catch (err) {
    error.value = err.message || 'Nepodařilo se načíst data'
  } finally {
    loading.value = false
  }
})

// Computed from dashboard data
// Note: Invoices are skipped in this phase - showing placeholders
const paid = computed(() => 0)
const unpaid = computed(() => 0)
const overdue = computed(() => 0)
const invoicesTotal = computed(() => 0)
const nextDue = computed(() => null)
const recentInvoices = computed(() => [])
const personnel = computed(() => ({
  count: dashboardData.value.personnelCount || 0,
  locationName: dashboardData.value.locations?.[0]?.name || '',
}))
const contract = computed(() => dashboardData.value.contract || { contractsEnabled: false, hasPdf: false })
const contacts = computed(() => dashboardData.value.contacts || [])
const cleaningDays = computed(() => dashboardData.value.cleaningDays || [])

const chartData = computed(() => ({
  labels: ['Zaplaceno', 'Nezaplaceno', 'Po splatnosti'],
  datasets: [{
    data: [paid.value, unpaid.value, overdue.value],
    backgroundColor: ['#198754', '#667ea1', '#dc3545'],
    borderWidth: 0,
    hoverOffset: 4,
  }]
}))

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom',
      labels: {
        font: { family: 'Rubik', size: 12 },
        padding: 16,
        boxWidth: 12,
        boxHeight: 12,
      }
    },
    tooltip: {
      callbacks: {
        label: (ctx) => ` ${ctx.label}: ${ctx.raw} faktur`
      }
    }
  },
  cutout: '68%',
}

function statusBadge(s) {
  if (s === 'paid')    return { cls: 'badge-success', label: 'Zaplaceno' }
  if (s === 'overdue') return { cls: 'badge-danger',  label: 'Po splatnosti' }
  return { cls: 'badge-info', label: 'Nezaplaceno' }
}

function formatDate(d) {
  if (!d) return ''
  const [y,m,day] = d.split('-')
  return `${day}.${m}.${y}`
}

function formatAmount(n) {
  return n.toLocaleString('cs-CZ') + ' Kč'
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').slice(0,2).toUpperCase()
}

const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return 'Dobré ráno'
  if (h < 18) return 'Dobré odpoledne'
  return 'Dobrý večer'
})

const displayName = computed(() => user.value?.display_name || user.value?.email || 'Klient')
const activeIco = computed(() => user.value?.active_ico || '')

// ── Cleanings mini-calendar (current month) ───────────────────────────────────
const today = new Date()

const cleaningDayMap = computed(() => {
  const m = {}
  for (const d of cleaningDays.value) m[d.date] = { ongoing: !!d.ongoing, note: d.note || '' }
  return m
})

// Build day cells for the current month up to today
const cleaningMiniDays = computed(() => {
  const year  = today.getFullYear()
  const month = today.getMonth()
  const days  = []
  for (let d = 1; d <= today.getDate(); d++) {
    const key = `${year}-${String(month + 1).padStart(2,'0')}-${String(d).padStart(2,'0')}`
    const info = cleaningDayMap.value[key]
    days.push({ day: d, key, hasCleaning: !!info, ongoing: info?.ongoing || false, note: info?.note || '' })
  }
  return days
})

const currentMonthPrefix = computed(() => {
  const year = today.getFullYear()
  const month = String(today.getMonth() + 1).padStart(2, '0')
  return `${year}-${month}`
})

const thisMonthCleanings = computed(() => cleaningDays.value.filter(d => d.date.startsWith(currentMonthPrefix.value)))
const cleaningCountDone = computed(() => thisMonthCleanings.value.filter(d => !d.ongoing).length)
const cleaningCountOngoing = computed(() => thisMonthCleanings.value.filter(d => d.ongoing).length)

const MONTHS = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec']
const currentMonthLabel = computed(() => `${MONTHS[today.getMonth()]} ${today.getFullYear()}`)
</script>

<template>
  <div>
    <!-- Loading state -->
    <div v-if="loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám přehled...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Content -->
    <template v-else>
    <!-- Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ greeting }}, {{ displayName.split(' ')[0] }} 👋</h1>
        <p class="page-subtitle">Přehled vašeho účtu<span v-if="activeIco"> · IČO: {{ activeIco }}</span></p>
      </div>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
      <div class="card stat-card">
        <div class="stat-icon stat-icon--blue">
          <FileText :size="22" />
        </div>
        <div class="stat-body">
          <div class="stat-value">{{ invoicesTotal }}</div>
          <div class="stat-label">Faktur celkem</div>
          <div class="stat-sub">
            <span class="badge badge-danger" v-if="overdue > 0">{{ overdue }} po splatnosti</span>
            <span class="badge badge-info" v-if="unpaid > 0">{{ unpaid }} čeká</span>
          </div>
        </div>
      </div>

      <div class="card stat-card">
        <div class="stat-icon stat-icon--orange">
          <Calendar :size="22" />
        </div>
        <div class="stat-body">
          <div v-if="nextDue" class="stat-value">Za {{ nextDue.daysRelative }} dní</div>
          <div v-else class="stat-value stat-ok">Vše splaceno ✓</div>
          <div class="stat-label">Nejbližší splatnost</div>
          <div v-if="nextDue" class="stat-sub-text">{{ nextDue.id }}</div>
        </div>
      </div>

      <div class="card stat-card">
        <div class="stat-icon stat-icon--teal">
          <Users :size="22" />
        </div>
        <div class="stat-body">
          <div class="stat-value">{{ personnel.count }}</div>
          <div class="stat-label">Přiřazených pracovníků</div>
          <div class="stat-sub-text" v-if="personnel.locationName">Vaše místo: {{ personnel.locationName }}</div>
        </div>
      </div>

      <div class="card stat-card">
        <div class="stat-icon" :class="contract.hasPdf ? 'stat-icon--green' : 'stat-icon--red'">
          <FileSignature :size="22" />
        </div>
        <div class="stat-body">
          <div class="stat-value">{{ contract.hasPdf ? 'Nahraná ✓' : 'Chybí ⚠' }}</div>
          <div class="stat-label">Smlouva</div>
          <RouterLink v-if="contract.hasPdf" to="/smlouva" class="stat-link">Zobrazit smlouvu →</RouterLink>
        </div>
      </div>
    </div>

    <!-- Chart + Recent invoices -->
    <div class="dashboard-mid">
      <!-- Chart -->
      <div class="card chart-card">
        <h3 class="card-title">Přehled faktur</h3>
        <div class="chart-wrap">
          <Doughnut :data="chartData" :options="chartOptions" />
        </div>
        <div class="chart-totals">
          <div class="total-row">
            <span class="total-dot" style="background:#198754" />
            <span>Zaplaceno</span>
            <strong>{{ paid }}</strong>
          </div>
          <div class="total-row">
            <span class="total-dot" style="background:#667ea1" />
            <span>Nezaplaceno</span>
            <strong>{{ unpaid }}</strong>
          </div>
          <div class="total-row">
            <span class="total-dot" style="background:#dc3545" />
            <span>Po splatnosti</span>
            <strong>{{ overdue }}</strong>
          </div>
        </div>
      </div>

      <!-- Recent invoices -->
      <div class="card recent-card">
        <div class="card-header-row">
          <h3 class="card-title">Poslední faktury</h3>
          <RouterLink to="/faktury" class="card-link">
            Zobrazit vše <ArrowRight :size="14" style="vertical-align:middle;" />
          </RouterLink>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Číslo</th>
                <th>Splatnost</th>
                <th class="text-right">Částka</th>
                <th>Stav</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in recentInvoices" :key="inv.id">
                <td class="fw-500">{{ inv.id }}</td>
                <td>{{ formatDate(inv.due) }}</td>
                <td class="text-right fw-500">{{ formatAmount(inv.amount) }}</td>
                <td>
                  <span class="badge" :class="statusBadge(inv.status).cls">
                    {{ statusBadge(inv.status).label }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Cleanings overview -->
    <div class="card cleaning-card">
      <div class="card-header-row">
        <div>
          <h3 class="card-title" style="margin-bottom:2px;">Úklidy – {{ currentMonthLabel }}</h3>
          <p style="font-size:12px; color:var(--color-gray-500); margin:0;">
            Přehled úklidů od začátku měsíce
          </p>
        </div>
        <RouterLink to="/dochazka" class="card-link">
          Detail <ArrowRight :size="14" style="vertical-align:middle;" />
        </RouterLink>
      </div>

      <!-- Summary pills -->
      <div class="cleaning-summary">
        <span class="cs-pill cs-done">
          <CheckCircle2 :size="13" />
          {{ cleaningCountDone }} proběhlo
        </span>
        <span v-if="cleaningCountOngoing > 0" class="cs-pill cs-ongoing">
          <Loader2 :size="13" class="spin" />
          Právě probíhá
        </span>
      </div>

      <!-- Day strip -->
      <div class="day-strip">
        <div
          v-for="cell in cleaningMiniDays"
          :key="cell.key"
          class="ds-cell"
          :class="{
            'ds-done':    cell.hasCleaning && !cell.ongoing,
            'ds-ongoing': cell.ongoing,
            'ds-empty':   !cell.hasCleaning,
          }"
          :title="cell.hasCleaning ? (cell.note || 'Úklid proběhl') : `${cell.day}. bez úklidu`"
        >
          <span class="ds-num">{{ cell.day }}</span>
          <CheckCircle2 v-if="cell.hasCleaning && !cell.ongoing" :size="10" class="ds-icon" />
          <Loader2      v-if="cell.ongoing"                       :size="10" class="ds-icon spin" />
        </div>
      </div>
    </div>

    <!-- Quick contact -->
    <div class="card" style="margin-top:24px;" v-if="contacts.length > 0">
      <h3 class="card-title" style="margin-bottom:16px;">Rychlý kontakt</h3>
      <div class="contact-quick-grid">
        <div v-for="c in contacts" :key="c.email" class="contact-quick-card">
          <div class="avatar avatar-md contact-avatar">{{ initials(c.name) }}</div>
          <div>
            <div class="fw-500">{{ c.name }}</div>
            <div class="text-muted" style="font-size:12px; margin-bottom:6px;">{{ c.role }}</div>
            <div class="contact-links">
              <a v-if="c.phone" :href="'tel:' + c.phone.replace(/\s/g,'')" class="contact-link">
                <Phone :size="13" /> {{ c.phone }}
              </a>
              <a v-if="c.email" :href="'mailto:' + c.email" class="contact-link">
                <Mail :size="13" /> {{ c.email }}
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
    </template>
  </div>
</template>

<style scoped>
/* Stat grid */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}

.stat-card {
  display: flex;
  align-items: flex-start;
  gap: 14px;
}

.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: white;
}
.stat-icon--blue   { background: var(--color-mid); }
.stat-icon--orange { background: #e67e00; }
.stat-icon--teal   { background: #0d6efd; }
.stat-icon--green  { background: var(--color-success); }
.stat-icon--red    { background: var(--color-danger); }

.stat-value {
  font-size: 22px;
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.1;
}

.stat-ok {
  font-size: 16px;
  color: var(--color-success);
}

.stat-label {
  font-size: 12px;
  color: var(--color-gray-600);
  margin: 3px 0 6px;
}

.stat-sub {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.stat-sub-text {
  font-size: 12px;
  color: var(--color-mid);
}

.stat-link {
  font-size: 12px;
  color: var(--color-mid);
}

/* Dashboard mid row */
.dashboard-mid {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 16px;
  align-items: start;
}

.chart-card { }

.card-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 16px;
}

.card-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.card-link {
  font-size: 13px;
  color: var(--color-mid);
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

/* Quick contact */
.contact-quick-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

.contact-quick-card {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.contact-avatar {
  background: var(--color-mid);
  flex-shrink: 0;
}

.contact-links {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.contact-link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  color: var(--color-mid);
}

.contact-link:hover {
  color: var(--color-primary);
}

/* Cleaning overview card */
.cleaning-card {
  margin-top: 24px;
}

.cleaning-summary {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 10px 0 16px;
}

.cs-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 12px;
  border-radius: var(--radius-pill);
  font-size: 12px;
  font-weight: 500;
}

.cs-done    { background: #d1e7dd; color: var(--color-success); }
.cs-ongoing { background: #fff0d6; color: var(--color-warning); }

/* Day strip */
.day-strip {
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
}

.ds-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  width: 36px;
  height: 44px;
  border-radius: 8px;
  cursor: default;
  transition: transform 0.12s ease;
  flex-shrink: 0;
}

.ds-cell:hover { transform: translateY(-2px); }

.ds-num {
  font-size: 11px;
  font-weight: 500;
  line-height: 1;
}

.ds-icon {
  line-height: 1;
}

.ds-empty {
  background: var(--color-gray-100);
  color: var(--color-gray-400);
}

.ds-done {
  background: #d1e7dd;
  color: #0a3622;
}
.ds-done .ds-icon { color: var(--color-success); }

.ds-ongoing {
  background: #fff0d6;
  color: #7a4200;
  border: 1.5px solid #e67e00;
}
.ds-ongoing .ds-icon { color: var(--color-warning); }

.spin { animation: spin 2s linear infinite; }
@keyframes spin {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}

/* Responsive */
@media (max-width: 1100px) {
  .stat-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .stat-grid {
    grid-template-columns: 1fr;
  }
  .dashboard-mid {
    grid-template-columns: 1fr;
  }
  .contact-quick-grid {
    grid-template-columns: 1fr;
  }
}
</style>
