<script setup>
import { ref, computed, onMounted } from 'vue'
import { Download, FileText, Loader2, RefreshCw } from 'lucide-vue-next'
import { invoiceService } from '../api'

const loading = ref(true)
const syncing = ref(false)
const downloadingPdf = ref(null)
const error = ref(null)
const invoices = ref([])
const icos = ref([])
const activeIco = ref(null)
const activeFilter = ref('all')
const lastSync = ref(null)
const isConfigured = ref(false)

const filters = [
  { key: 'all',     label: 'Vše' },
  { key: 'paid',    label: 'Zaplaceno' },
  { key: 'unpaid',  label: 'Nezaplaceno' },
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
    } else {
      error.value = response.message || 'Nepodařilo se načíst faktury'
    }
  } catch (err) {
    error.value = err.message || 'Nepodařilo se načíst faktury'
  } finally {
    loading.value = false
  }
}

onMounted(() => loadInvoices())

async function switchIco(ico) {
  if (ico === activeIco.value) return
  activeIco.value = ico
  await loadInvoices(ico)
}

async function syncInvoices() {
  if (syncing.value || !isConfigured.value) return
  syncing.value = true
  try {
    const response = await invoiceService.syncInvoices()
    if (response.success) {
      await loadInvoices(activeIco.value)
    } else {
      error.value = response.message || 'Synchronizace selhala'
    }
  } catch (err) {
    error.value = err.message || 'Synchronizace selhala'
  } finally {
    syncing.value = false
  }
}

const filtered = computed(() => {
  if (activeFilter.value === 'all') return invoices.value
  return invoices.value.filter(i => i.status === activeFilter.value)
})

const totals = computed(() => ({
  all:     invoices.value.length,
  paid:    invoices.value.filter(i => i.status === 'paid').length,
  unpaid:  invoices.value.filter(i => i.status === 'unpaid').length,
  overdue: invoices.value.filter(i => i.status === 'overdue').length,
  debt:    invoices.value.filter(i => i.status !== 'paid').reduce((s, i) => s + (i.amount || 0), 0),
}))

function statusBadge(s) {
  if (s === 'paid')    return { cls: 'badge-success', label: 'Zaplaceno' }
  if (s === 'overdue') return { cls: 'badge-danger',  label: 'Po splatnosti' }
  return { cls: 'badge-info', label: 'Nezaplaceno' }
}

function formatDate(d) {
  if (!d) return ''
  const [y, m, day] = d.split('-')
  return `${day}.${m}.${y}`
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

function formatAmount(n) {
  return (n || 0).toLocaleString('cs-CZ') + ' Kč'
}

function dueDays(inv) {
  if (inv.daysRelative === 0) return 'Dnes'
  if (inv.daysRelative > 0)   return `Za ${inv.daysRelative} dní`
  return `${Math.abs(inv.daysRelative)} dní po splatnosti`
}

function dueDaysCls(inv) {
  if (inv.status === 'paid')    return 'text-success'
  if (inv.daysRelative < 0)    return 'text-danger'
  if (inv.daysRelative <= 5)   return 'text-warning'
  return 'text-muted'
}

async function downloadPdf(inv) {
  if (downloadingPdf.value === inv.dbId) return
  downloadingPdf.value = inv.dbId
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
    error.value = 'Nepodařilo se stáhnout PDF'
  } finally {
    downloadingPdf.value = null
  }
}
</script>

<template>
  <div>
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
      <div id="invoices-header" class="page-header">
        <div id="invoices-header-text">
          <h1 id="invoices-title" class="page-title">Faktury</h1>
          <p id="invoices-subtitle" class="page-subtitle">
            Vydané faktury<span v-if="activeIco" id="invoices-active-ico"> · IČO: {{ activeIco }}</span>
            <span v-if="lastSync" id="invoices-last-sync" class="last-sync"> · Aktualizace: {{ formatDateTime(lastSync) }}</span>
          </p>
        </div>

        <div id="invoices-actions" style="display: flex; gap: 12px; align-items: flex-start;">
          <!-- Sync button -->
          <button
            v-if="isConfigured"
            id="invoices-sync-btn"
            class="btn btn-outline"
            :disabled="syncing"
            @click="syncInvoices"
            title="Synchronizovat faktury"
          >
            <RefreshCw :size="16" :class="{ spin: syncing }" />
            <span>{{ syncing ? 'Synchronizuji...' : 'Synchronizovat' }}</span>
          </button>

          <!-- IČO tabs (multi-IČO) -->
          <div id="invoices-ico-tabs" class="ico-tabs" v-if="icos.length > 1">
            <button
              v-for="ico in icos"
              :key="ico.ico"
              :id="'ico-tab-' + ico.ico"
              class="ico-tab"
              :class="{ active: activeIco === ico.ico }"
              @click="switchIco(ico.ico)"
            >
              {{ ico.ico }}<span class="ico-name">{{ ico.name }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Not configured warning -->
      <div v-if="!isConfigured" id="invoices-not-configured" class="alert alert-info" style="margin-bottom: 16px;">
        Integrace s iDoklad není nakonfigurována. Faktury budou zobrazeny po nastavení připojení k fakturačnímu systému.
      </div>

      <!-- Summary bar -->
      <div id="invoices-summary" class="summary-bar card" style="margin-bottom:16px;">
        <div id="summary-item-total" class="summary-item">
          <span id="summary-total" class="summary-val">{{ totals.all }}</span>
          <span id="summary-total-lbl" class="summary-lbl">Celkem faktur</span>
        </div>
        <div id="summary-sep-1" class="summary-sep" />
        <div id="summary-item-paid" class="summary-item">
          <span id="summary-paid" class="summary-val text-success">{{ totals.paid }}</span>
          <span id="summary-paid-lbl" class="summary-lbl">Zaplaceno</span>
        </div>
        <div id="summary-sep-2" class="summary-sep" />
        <div id="summary-item-unpaid" class="summary-item">
          <span id="summary-unpaid" class="summary-val text-mid">{{ totals.unpaid }}</span>
          <span id="summary-unpaid-lbl" class="summary-lbl">Nezaplaceno</span>
        </div>
        <div id="summary-sep-3" class="summary-sep" />
        <div id="summary-item-overdue" class="summary-item">
          <span id="summary-overdue" class="summary-val text-danger">{{ totals.overdue }}</span>
          <span id="summary-overdue-lbl" class="summary-lbl">Po splatnosti</span>
        </div>
        <div id="summary-sep-4" class="summary-sep" />
        <div id="summary-item-debt" class="summary-item">
          <span id="summary-debt" class="summary-val" :class="totals.debt > 0 ? 'text-danger' : 'text-success'">
            {{ formatAmount(totals.debt) }}
          </span>
          <span id="summary-debt-lbl" class="summary-lbl">Celkem k úhradě</span>
        </div>
      </div>

      <!-- Filters -->
      <div id="invoices-filters" class="chip-group" style="margin-bottom:16px;">
        <button
          v-for="f in filters"
          :key="f.key"
          :id="'filter-' + f.key"
          class="chip"
          :class="{ active: activeFilter === f.key }"
          @click="activeFilter = f.key"
        >
          {{ f.label }}
        </button>
      </div>

      <!-- Table -->
      <div id="invoices-table-card" class="card">
        <div v-if="filtered.length === 0" id="invoices-empty" class="empty-state">
          <FileText id="invoices-empty-icon" :size="40" class="empty-state-icon" />
          <p id="invoices-empty-text" class="empty-state-title">Zatím zde nejsou žádné faktury.</p>
        </div>

        <div v-else class="table-wrap">
          <table id="invoices-table" class="data-table">
            <thead>
              <tr>
                <th>Číslo faktury</th>
                <th>Vystaveno</th>
                <th>Splatnost</th>
                <th class="text-right">Částka</th>
                <th>VS</th>
                <th>Stav</th>
                <th>Zbývá / uplynulo</th>
                <th>Akce</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in filtered" :key="inv.dbId" :id="'invoice-row-' + inv.dbId">
                <td class="fw-600" style="color:var(--color-primary)">{{ inv.id }}</td>
                <td class="text-muted">{{ formatDate(inv.issued) }}</td>
                <td>{{ formatDate(inv.due) }}</td>
                <td class="text-right fw-500">{{ formatAmount(inv.amount) }}</td>
                <td class="text-muted">{{ inv.varSymbol }}</td>
                <td>
                  <span class="badge" :class="statusBadge(inv.status).cls">
                    {{ statusBadge(inv.status).label }}
                  </span>
                </td>
                <td :class="dueDaysCls(inv)" style="font-size:13px;">
                  {{ inv.status === 'paid' ? '—' : dueDays(inv) }}
                </td>
                <td>
                  <button
                    :id="'download-pdf-' + inv.dbId"
                    class="btn btn-ghost btn-sm"
                    @click="downloadPdf(inv)"
                    :disabled="downloadingPdf === inv.dbId"
                    title="Stáhnout PDF"
                  >
                    <Loader2 v-if="downloadingPdf === inv.dbId" :size="16" class="spin" />
                    <Download v-else :size="16" />
                    <span>PDF</span>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* IČO tabs */
.ico-tabs {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.ico-tab {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  padding: 6px 14px;
  border-radius: var(--radius-md);
  border: 1.5px solid var(--color-gray-300);
  background: white;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-gray-700);
  cursor: pointer;
  transition: var(--transition);
}

.ico-tab.active {
  border-color: var(--color-primary);
  background: var(--color-light);
  color: var(--color-primary);
}

.ico-name {
  font-size: 11px;
  font-weight: 400;
  color: var(--color-gray-600);
  margin-top: 1px;
}

/* Summary bar */
.summary-bar {
  display: flex;
  align-items: center;
  gap: 0;
  padding: 14px 20px;
}

.summary-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 1;
  gap: 2px;
}

.summary-val {
  font-size: 20px;
  font-weight: 700;
  color: var(--color-primary);
}

.summary-lbl {
  font-size: 11px;
  color: var(--color-gray-600);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.summary-sep {
  width: 1px;
  height: 36px;
  background: var(--color-gray-200);
  flex-shrink: 0;
}

.last-sync {
  font-size: 12px;
  color: var(--color-gray-500);
}

.spin {
  animation: spin 2s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}

@media (max-width: 768px) {
  .summary-bar {
    flex-wrap: wrap;
    gap: 12px;
  }
  .summary-sep { display: none; }
  .summary-item { flex: 1 1 40%; }
}
</style>
