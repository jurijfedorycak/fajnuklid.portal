<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import {
  Download, FileText, Loader2, Sparkles, SlidersHorizontal,
  CalendarDays, ChevronDown, Check, AlertCircle, ReceiptText, X,
} from 'lucide-vue-next'
import { invoiceService } from '../api'
import BottomSheet from '../components/BottomSheet.vue'

const loading = ref(true)
const downloadingPdf = ref(null)
const error = ref(null)
const downloadError = ref(null)
const invoices = ref([])
const icos = ref([])
const activeIco = ref(null)
const lastSync = ref(null)
const isConfigured = ref(false)

const selectedPeriod = ref('all')
const statusFilter = ref('all')
const sheetOpen = ref(false)
const periodOpen = ref(false)

const CZ_MONTHS = ['Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen',
  'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec']

const STATUS_META = {
  paid:    { label: 'Zaplaceno',      pill: 'pill-paid',    icon: 'icon-paid' },
  unpaid:  { label: 'Čeká na úhradu', pill: 'pill-unpaid',  icon: 'icon-unpaid' },
  overdue: { label: 'Po splatnosti',  pill: 'pill-overdue', icon: 'icon-overdue' },
}

const STATUS_OPTIONS = [
  { key: 'all',     label: 'Vše' },
  { key: 'paid',    label: 'Zaplacené' },
  { key: 'unpaid',  label: 'Nezaplacené' },
  { key: 'overdue', label: 'Po splatnosti' },
]

async function loadInvoices(ico = null) {
  loading.value = true
  error.value = null
  try {
    const response = await invoiceService.getInvoices(ico)
    if (response.success) {
      invoices.value = response.data.invoices || []
      icos.value = response.data.icos || []
      activeIco.value = response.data.activeIco || (icos.value[0]?.ico ?? null)
      lastSync.value = response.data.lastSync
      isConfigured.value = response.data.isConfigured ?? false
      selectedPeriod.value = 'all'
      statusFilter.value = 'all'
    } else {
      error.value = response.message || 'Nepodařilo se načíst faktury'
    }
  } catch (err) {
    error.value = err.message || 'Nepodařilo se načíst faktury'
  } finally {
    loading.value = false
  }
}

function onDocumentClick(e) {
  if (periodOpen.value && !e.target.closest('#invoices-period')) periodOpen.value = false
}

function onDocumentKeydown(e) {
  // BottomSheet handles its own Escape; only the topmost layer should react
  if (e.key === 'Escape' && !sheetOpen.value) periodOpen.value = false
}

onMounted(() => {
  loadInvoices()
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onDocumentKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onDocumentKeydown)
})

async function switchIco(ico) {
  sheetOpen.value = false
  if (ico === activeIco.value) return
  activeIco.value = ico
  await loadInvoices(ico)
}

const activeCompany = computed(() => icos.value.find(i => i.ico === activeIco.value) || null)

const periods = computed(() => {
  const keys = [...new Set(invoices.value
    .filter(i => i.issued)
    .map(i => i.issued.slice(0, 7)))]
    .sort()
    .reverse()
  return keys.map(k => {
    const [y, m] = k.split('-')
    return { key: k, label: `${CZ_MONTHS[Number(m) - 1]} ${y}` }
  })
})

const periodLabel = computed(() =>
  selectedPeriod.value === 'all'
    ? 'Vše'
    : periods.value.find(p => p.key === selectedPeriod.value)?.label ?? 'Vše')

const filtered = computed(() =>
  invoices.value.filter(i =>
    (selectedPeriod.value === 'all' || (i.issued || '').startsWith(selectedPeriod.value)) &&
    (statusFilter.value === 'all' || i.status === statusFilter.value)))

// Global figures ignore period/status filters on purpose: "what you owe now"
// must not understate debt while browsing a single month
const outstanding = computed(() =>
  invoices.value.filter(i => i.status !== 'paid').reduce((s, i) => s + (i.amount || 0), 0))

const overdueCount = computed(() => invoices.value.filter(i => i.status === 'overdue').length)

const filtersActive = computed(() => statusFilter.value !== 'all')

function selectPeriod(key) {
  selectedPeriod.value = key
  periodOpen.value = false
}

function overdueLabel(n) {
  if (n === 1) return 'Máte 1 fakturu po splatnosti'
  if (n >= 2 && n <= 4) return `Máte ${n} faktury po splatnosti`
  return `Máte ${n} faktur po splatnosti`
}

function showOverdue() {
  statusFilter.value = 'overdue'
  selectedPeriod.value = 'all'
  document.getElementById('invoices-list-header')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function resetFilters() {
  selectedPeriod.value = 'all'
  statusFilter.value = 'all'
}

function statusMeta(s) {
  return STATUS_META[s] || STATUS_META.unpaid
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' })
}

function formatDateTime(d) {
  if (!d) return ''
  const date = new Date(d)
  return date.toLocaleString('cs-CZ', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function formatAmount(n, cur = 'Kč') {
  const label = cur === 'CZK' ? 'Kč' : cur
  return `${(n || 0).toLocaleString('cs-CZ')} ${label}`
}

async function downloadPdf(inv) {
  if (downloadingPdf.value === inv.dbId) return
  downloadingPdf.value = inv.dbId
  downloadError.value = null
  try {
    const blob = await invoiceService.downloadPdf(inv.dbId)
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `faktura_${inv.id}.pdf`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)
  } catch (err) {
    downloadError.value = `Nepodařilo se stáhnout PDF faktury ${inv.id}`
  } finally {
    downloadingPdf.value = null
  }
}
</script>

<template>
  <div id="invoices-page" class="page-shell page-shell--md">
    <!-- Loading state -->
    <div v-if="loading" id="invoices-loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám faktury...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" id="invoices-error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Content -->
    <template v-else>
      <div id="invoices-header" class="invoices-header">
        <div id="invoices-header-text">
          <h1 id="invoices-title" class="invoices-title">Faktury</h1>
          <p v-if="icos.length > 1 && activeCompany" id="invoices-company" class="invoices-company">
            {{ activeCompany.name }} · IČO {{ activeCompany.ico }}
          </p>
        </div>
        <button
          v-if="invoices.length > 0 || icos.length > 1"
          id="invoices-filter-btn"
          class="invoices-filter-btn"
          aria-label="Filtry"
          @click="sheetOpen = true"
        >
          <SlidersHorizontal :size="18" />
          <span v-if="filtersActive" id="invoices-filter-dot" class="filter-dot" aria-hidden="true"></span>
        </button>
      </div>

      <!-- Not configured warning -->
      <div v-if="!isConfigured" id="invoices-not-configured" class="alert alert-info" style="margin-bottom: 16px;">
        Integrace s iDoklad není nakonfigurována. Faktury budou zobrazeny po nastavení připojení k fakturačnímu systému.
      </div>

      <!-- Brand-new-client onboarding hero for the list -->
      <div
        v-if="invoices.length === 0"
        id="invoices-onboarding"
        class="onboarding-hero invoices-onboarding"
      >
        <div class="onboarding-hero-icon onboarding-hero-icon--soft">
          <FileText :size="28" aria-hidden="true" />
        </div>
        <h2 id="invoices-onboarding-title" class="onboarding-hero-title">
          Vaše faktury budou přehledně čekat tady
        </h2>
        <p id="invoices-onboarding-desc" class="onboarding-hero-desc">
          Jakmile vám vystavíme první fakturu, najdete ji zde spolu s datem splatnosti
          a možností stažení PDF. Žádné papírové archivy, žádné hledání v e-mailu.
        </p>
        <div class="invoices-onboarding-perks">
          <div class="invoices-perk" id="invoices-perk-1">
            <Sparkles :size="14" aria-hidden="true" />
            <span>Stav zaplacení na první pohled</span>
          </div>
          <div class="invoices-perk" id="invoices-perk-2">
            <Sparkles :size="14" aria-hidden="true" />
            <span>PDF ke stažení kdykoliv</span>
          </div>
          <div class="invoices-perk" id="invoices-perk-3">
            <Sparkles :size="14" aria-hidden="true" />
            <span>Upozornění na blížící se splatnost</span>
          </div>
        </div>
      </div>

      <template v-else>
        <!-- Period selector -->
        <div id="invoices-period" class="period-card">
          <button
            id="invoices-period-btn"
            class="period-btn"
            aria-haspopup="listbox"
            :aria-expanded="periodOpen"
            aria-controls="invoices-period-menu"
            @click="periodOpen = !periodOpen"
          >
            <span id="invoices-period-icon" class="period-icon" aria-hidden="true">
              <CalendarDays :size="18" />
            </span>
            <span id="invoices-period-text" class="period-text">
              <span class="period-label">Období</span>
              <span id="invoices-period-value" class="period-value">{{ periodLabel }}</span>
            </span>
            <ChevronDown :size="18" class="period-chevron" :class="{ open: periodOpen }" aria-hidden="true" />
          </button>

          <div v-if="periodOpen" id="invoices-period-menu" class="period-menu" role="listbox" aria-label="Období">
            <button
              id="invoices-period-option-all"
              class="period-option"
              role="option"
              :aria-selected="selectedPeriod === 'all'"
              :class="{ active: selectedPeriod === 'all' }"
              @click="selectPeriod('all')"
            >
              <span>Vše</span>
              <Check v-if="selectedPeriod === 'all'" :size="16" aria-hidden="true" />
            </button>
            <button
              v-for="p in periods"
              :key="p.key"
              :id="'invoices-period-option-' + p.key"
              class="period-option"
              role="option"
              :aria-selected="selectedPeriod === p.key"
              :class="{ active: selectedPeriod === p.key }"
              @click="selectPeriod(p.key)"
            >
              <span>{{ p.label }}</span>
              <Check v-if="selectedPeriod === p.key" :size="16" aria-hidden="true" />
            </button>
          </div>
        </div>

        <!-- Summary card -->
        <div id="invoices-summary" class="summary-card">
          <span id="invoices-summary-label" class="summary-label">Celkem k úhradě</span>
          <p id="invoices-outstanding" class="summary-amount" :class="{ 'is-paid': outstanding === 0 }">
            {{ formatAmount(outstanding) }}
          </p>
          <p v-if="outstanding === 0" id="invoices-all-paid" class="summary-helper">Vše zaplaceno</p>
          <button
            v-if="overdueCount > 0"
            id="invoices-overdue-alert"
            class="overdue-strip"
            aria-label="Zobrazit faktury po splatnosti"
            @click="showOverdue"
          >
            <AlertCircle :size="16" aria-hidden="true" />
            <span>{{ overdueLabel(overdueCount) }}</span>
          </button>
        </div>

        <!-- PDF download error -->
        <div v-if="downloadError" id="invoices-download-error" class="download-error" role="alert">
          <AlertCircle :size="16" aria-hidden="true" />
          <span id="invoices-download-error-text" class="download-error-text">{{ downloadError }}</span>
          <button
            id="invoices-download-error-close"
            class="download-error-close"
            aria-label="Zavřít upozornění"
            @click="downloadError = null"
          >
            <X :size="14" />
          </button>
        </div>

        <!-- Section header -->
        <div id="invoices-list-header" class="list-header">
          <span id="invoices-list-label" class="list-header-label">Seznam dokladů</span>
          <span id="invoices-found-count" class="list-header-count">{{ filtered.length }} nalezeno</span>
        </div>

        <!-- Empty filter combo -->
        <div v-if="filtered.length === 0" id="invoices-empty" class="empty-state">
          <FileText id="invoices-empty-icon" :size="40" class="empty-state-icon" />
          <p id="invoices-empty-title" class="empty-state-title">Žádné faktury pro zvolené filtry</p>
          <p id="invoices-empty-text" class="empty-state-text">Zkuste jiné období nebo stav.</p>
          <button id="invoices-reset-filters" class="btn btn-outline" @click="resetFilters">
            Zrušit filtry
          </button>
        </div>

        <!-- Invoice list -->
        <div v-else id="invoices-list" class="invoices-list">
          <article
            v-for="inv in filtered"
            :key="inv.dbId"
            :id="'invoice-card-' + inv.dbId"
            class="invoice-card"
          >
            <span class="invoice-icon" :class="statusMeta(inv.status).icon" aria-hidden="true">
              <ReceiptText :size="20" />
            </span>
            <div class="invoice-main">
              <span class="invoice-number">#{{ inv.id }}</span>
              <span v-if="inv.status === 'paid'" class="invoice-date">{{ formatDate(inv.issued) }}</span>
              <span v-else class="invoice-date" :class="{ 'is-overdue': inv.status === 'overdue' }">
                Splatnost {{ formatDate(inv.due) }}
              </span>
            </div>
            <div class="invoice-right">
              <span class="invoice-amount">{{ formatAmount(inv.amount, inv.currency || 'Kč') }}</span>
              <span class="invoice-pill" :class="statusMeta(inv.status).pill">{{ statusMeta(inv.status).label }}</span>
            </div>
            <button
              :id="'download-pdf-' + inv.dbId"
              class="invoice-download"
              :aria-label="'Stáhnout PDF faktury ' + inv.id"
              :disabled="downloadingPdf === inv.dbId"
              @click="downloadPdf(inv)"
            >
              <Loader2 v-if="downloadingPdf === inv.dbId" :size="16" class="spin" />
              <Download v-else :size="16" />
            </button>
          </article>
        </div>

        <p v-if="lastSync" id="invoices-last-sync" class="invoices-sync">
          Aktualizace: {{ formatDateTime(lastSync) }}
        </p>
      </template>

      <BottomSheet :show="sheetOpen" title="Filtry" @close="sheetOpen = false">
        <section id="sheet-status-section" class="sheet-section">
          <h3 id="sheet-status-label" class="sheet-section-label">Stav platby</h3>
          <div id="sheet-status-options" aria-labelledby="sheet-status-label">
            <button
              v-for="opt in STATUS_OPTIONS"
              :key="opt.key"
              :id="'sheet-status-' + opt.key"
              class="sheet-option"
              :aria-pressed="statusFilter === opt.key"
              :class="{ active: statusFilter === opt.key }"
              @click="statusFilter = opt.key"
            >
              <span>{{ opt.label }}</span>
              <Check v-if="statusFilter === opt.key" :size="16" aria-hidden="true" />
            </button>
          </div>
        </section>

        <section v-if="icos.length > 1" id="sheet-ico-section" class="sheet-section">
          <h3 id="sheet-ico-label" class="sheet-section-label">Společnost</h3>
          <div id="sheet-ico-options" aria-labelledby="sheet-ico-label">
            <button
              v-for="ico in icos"
              :key="ico.ico"
              :id="'sheet-ico-' + ico.ico"
              class="sheet-option sheet-option-two-line"
              :aria-pressed="activeIco === ico.ico"
              :class="{ active: activeIco === ico.ico }"
              @click="switchIco(ico.ico)"
            >
              <span class="sheet-option-text">
                <span class="sheet-option-name">{{ ico.name }}</span>
                <span class="sheet-option-sub">IČO {{ ico.ico }}</span>
              </span>
              <Check v-if="activeIco === ico.ico" :size="16" aria-hidden="true" />
            </button>
          </div>
        </section>

        <template #footer>
          <button id="sheet-done" class="btn btn-primary btn-full" @click="sheetOpen = false">
            Hotovo
          </button>
        </template>
      </BottomSheet>
    </template>
  </div>
</template>

<style scoped>
/* ═══ Header ═══ */
.invoices-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.invoices-title {
  font-size: var(--fs-2xl);
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.2;
}

.invoices-company {
  font-size: 13px;
  color: var(--color-gray-500);
  margin-top: 2px;
}

.invoices-filter-btn {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: none;
  background: var(--color-primary);
  color: var(--color-white);
  cursor: pointer;
  transition: var(--transition);
  flex-shrink: 0;
}
.invoices-filter-btn:hover {
  background: var(--color-primary-hover);
}

.filter-dot {
  position: absolute;
  top: 2px;
  right: 2px;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: var(--color-blue);
  border: 2px solid var(--color-white);
}

/* ═══ Period selector ═══ */
.period-card {
  position: relative;
  margin-bottom: 12px;
}

.period-btn {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 12px 16px;
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-xl);
  background: var(--color-white);
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  text-align: left;
  transition: var(--transition);
}
.period-btn:hover {
  border-color: var(--color-blue-border);
}

.period-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-md);
  background: var(--color-blue-light);
  color: var(--color-blue);
  flex-shrink: 0;
}

.period-text {
  display: flex;
  flex-direction: column;
  gap: 1px;
  flex: 1;
  min-width: 0;
}

.period-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.period-value {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-primary);
}

.period-chevron {
  color: var(--color-gray-400);
  transition: transform var(--transition);
  flex-shrink: 0;
}
.period-chevron.open {
  transform: rotate(180deg);
}

.period-menu {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
  max-height: 280px;
  overflow-y: auto;
  z-index: 20;
  padding: 6px;
}

.period-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  width: 100%;
  padding: 11px 12px;
  border: none;
  border-radius: var(--radius-md);
  background: none;
  font-size: 14px;
  color: var(--color-gray-700);
  cursor: pointer;
  text-align: left;
  transition: var(--transition);
}
.period-option:hover {
  background: var(--color-gray-100);
}
.period-option.active {
  color: var(--color-blue);
  font-weight: 600;
  background: var(--color-blue-light);
}

/* ═══ Summary card ═══ */
.summary-card {
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-sm);
  padding: 18px;
  margin-bottom: 20px;
}

.summary-label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-bottom: 6px;
}

.summary-amount {
  font-size: 30px;
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.15;
}
.summary-amount.is-paid {
  color: var(--color-success);
}

.summary-helper {
  font-size: 13px;
  color: var(--color-gray-500);
  margin-top: 4px;
}

.overdue-strip {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  margin-top: 14px;
  padding: 10px 12px;
  border: none;
  border-radius: var(--radius-md);
  background: var(--color-danger-light);
  color: var(--color-danger);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  text-align: left;
  transition: var(--transition);
}
.overdue-strip:hover {
  filter: brightness(0.97);
}
.overdue-strip svg {
  flex-shrink: 0;
}

/* ═══ Download error ═══ */
.download-error {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  margin-bottom: 12px;
  border-radius: var(--radius-md);
  background: var(--color-danger-light);
  color: var(--color-danger);
  font-size: 13px;
  font-weight: 500;
}
.download-error svg {
  flex-shrink: 0;
}

.download-error-text {
  flex: 1;
  min-width: 0;
}

.download-error-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border: none;
  border-radius: 50%;
  background: transparent;
  color: var(--color-danger);
  cursor: pointer;
  flex-shrink: 0;
  transition: var(--transition);
}
.download-error-close:hover {
  background: var(--color-white);
}

/* ═══ List header ═══ */
.list-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.list-header-label {
  font-size: 12px;
  font-weight: 700;
  color: var(--color-primary);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.list-header-count {
  font-size: 12px;
  color: var(--color-gray-400);
}

/* ═══ Invoice cards ═══ */
.invoices-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.invoice-card {
  display: flex;
  align-items: center;
  gap: 12px;
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-xl);
  padding: 14px 14px 14px 16px;
  box-shadow: var(--shadow-sm);
  transition: var(--transition);
}
.invoice-card:hover {
  border-color: var(--color-blue-border);
  box-shadow: var(--shadow-md);
}

.invoice-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-lg);
  flex-shrink: 0;
}
.icon-paid    { background: var(--color-success-light); color: var(--color-success); }
.icon-unpaid  { background: var(--color-blue-light);    color: var(--color-blue); }
.icon-overdue { background: var(--color-danger-light);  color: var(--color-danger); }

.invoice-main {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
  min-width: 0;
}

.invoice-number {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.invoice-date {
  font-size: 13px;
  color: var(--color-gray-500);
}
.invoice-date.is-overdue {
  color: var(--color-danger);
}

.invoice-right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 5px;
  flex-shrink: 0;
}

.invoice-amount {
  font-size: 14px;
  font-weight: 700;
  color: var(--color-primary);
  white-space: nowrap;
}

.invoice-pill {
  padding: 4px 10px;
  border-radius: var(--radius-sm);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  white-space: nowrap;
}
.pill-paid    { background: var(--color-success-light); color: var(--color-success); }
.pill-unpaid  { background: var(--color-blue-light);    color: var(--color-blue); }
.pill-overdue { background: var(--color-danger-light);  color: var(--color-danger); }

.invoice-download {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  border-radius: var(--radius-md);
  background: transparent;
  color: var(--color-gray-500);
  cursor: pointer;
  flex-shrink: 0;
  transition: var(--transition);
}
.invoice-download:hover:not(:disabled) {
  background: var(--color-gray-100);
  color: var(--color-primary);
}
.invoice-download:disabled {
  cursor: default;
}

/* ═══ Last sync ═══ */
.invoices-sync {
  font-size: 12px;
  color: var(--color-gray-500);
  text-align: center;
  margin-top: 16px;
}

/* ═══ Filter sheet content ═══ */
.sheet-section {
  margin-bottom: 16px;
}
.sheet-section:last-child {
  margin-bottom: 0;
}

.sheet-section-label {
  font-size: 11px;
  font-weight: 700;
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-bottom: 6px;
}

.sheet-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  width: 100%;
  padding: 12px;
  border: none;
  border-radius: var(--radius-md);
  background: none;
  font-size: 14px;
  color: var(--color-gray-700);
  cursor: pointer;
  text-align: left;
  transition: var(--transition);
}
.sheet-option:hover {
  background: var(--color-gray-100);
}
.sheet-option.active {
  color: var(--color-blue);
  font-weight: 600;
  background: var(--color-blue-light);
}

.sheet-option-text {
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
}

.sheet-option-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sheet-option-sub {
  font-size: 12px;
  color: var(--color-gray-500);
  font-weight: 400;
}

/* ═══ Misc ═══ */
.spin {
  animation: spin 2s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}

/* Onboarding hero spacing within list views */
.invoices-onboarding {
  margin-top: 4px;
}

.invoices-onboarding-perks {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 14px;
  width: 100%;
  max-width: 420px;
  text-align: left;
}
@media (min-width: 640px) {
  .invoices-onboarding-perks {
    margin-top: 18px;
  }
}

.invoices-perk {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-700);
  padding: 8px 12px;
  background: var(--color-gray-50);
  border-radius: var(--radius-md);
}
.invoices-perk svg {
  color: var(--color-accent);
  flex-shrink: 0;
}

/* ═══ Desktop polish ═══ */
@media (min-width: 768px) {
  .invoice-card {
    padding: 18px 20px;
  }
  .summary-card {
    padding: 22px 24px;
  }
  .summary-amount {
    font-size: 34px;
  }
}
</style>
