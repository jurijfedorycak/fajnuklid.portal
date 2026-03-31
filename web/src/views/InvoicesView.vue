<script setup>
import { ref, computed } from 'vue'
import { Download, FileText } from 'lucide-vue-next'
import { invoices, currentUser } from '../data/mockData.js'

const activeFilter = ref('all')
const activeIco = ref(currentUser.activeIco)

const filters = [
  { key: 'all',     label: 'Vše' },
  { key: 'paid',    label: 'Zaplaceno' },
  { key: 'unpaid',  label: 'Nezaplaceno' },
  { key: 'overdue', label: 'Po splatnosti' },
]

const filtered = computed(() => {
  if (activeFilter.value === 'all') return invoices
  return invoices.filter(i => i.status === activeFilter.value)
})

const totals = computed(() => ({
  all:     invoices.length,
  paid:    invoices.filter(i => i.status === 'paid').length,
  unpaid:  invoices.filter(i => i.status === 'unpaid').length,
  overdue: invoices.filter(i => i.status === 'overdue').length,
  debt:    invoices.filter(i => i.status !== 'paid').reduce((s, i) => s + i.amount, 0),
}))

function statusBadge(s) {
  if (s === 'paid')    return { cls: 'badge-success', label: 'Zaplaceno' }
  if (s === 'overdue') return { cls: 'badge-danger',  label: 'Po splatnosti' }
  return { cls: 'badge-info', label: 'Nezaplaceno' }
}

function formatDate(d) {
  const [y, m, day] = d.split('-')
  return `${day}.${m}.${y}`
}

function formatAmount(n) {
  return n.toLocaleString('cs-CZ') + ' Kč'
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

function downloadPdf(inv) {
  alert(`Stáhnout PDF: ${inv.id}.pdf\n(Ve finální aplikaci zde bude odkaz na skutečný dokument z iDoklad.)`)
}
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Faktury</h1>
        <p class="page-subtitle">Vydané faktury · IČO: {{ currentUser.activeIco }}</p>
      </div>

      <!-- IČO tabs (multi-IČO) -->
      <div class="ico-tabs" v-if="currentUser.icos.length > 1">
        <button
          v-for="ico in currentUser.icos"
          :key="ico.ico"
          class="ico-tab"
          :class="{ active: activeIco === ico.ico }"
          @click="activeIco = ico.ico"
        >
          {{ ico.ico }}<span class="ico-name">{{ ico.name }}</span>
        </button>
      </div>
    </div>

    <!-- Summary bar -->
    <div class="summary-bar card" style="margin-bottom:16px;">
      <div class="summary-item">
        <span class="summary-val">{{ totals.all }}</span>
        <span class="summary-lbl">Celkem faktur</span>
      </div>
      <div class="summary-sep" />
      <div class="summary-item">
        <span class="summary-val text-success">{{ totals.paid }}</span>
        <span class="summary-lbl">Zaplaceno</span>
      </div>
      <div class="summary-sep" />
      <div class="summary-item">
        <span class="summary-val text-mid">{{ totals.unpaid }}</span>
        <span class="summary-lbl">Nezaplaceno</span>
      </div>
      <div class="summary-sep" />
      <div class="summary-item">
        <span class="summary-val text-danger">{{ totals.overdue }}</span>
        <span class="summary-lbl">Po splatnosti</span>
      </div>
      <div class="summary-sep" />
      <div class="summary-item">
        <span class="summary-val" :class="totals.debt > 0 ? 'text-danger' : 'text-success'">
          {{ formatAmount(totals.debt) }}
        </span>
        <span class="summary-lbl">Celkem k úhradě</span>
      </div>
    </div>

    <!-- Filters -->
    <div class="chip-group" style="margin-bottom:16px;">
      <button
        v-for="f in filters"
        :key="f.key"
        class="chip"
        :class="{ active: activeFilter === f.key }"
        @click="activeFilter = f.key"
      >
        {{ f.label }}
      </button>
    </div>

    <!-- Table -->
    <div class="card">
      <div v-if="filtered.length === 0" class="empty-state">
        <FileText :size="40" class="empty-state-icon" />
        <p class="empty-state-title">Zatím zde nejsou žádné faktury.</p>
      </div>

      <div v-else class="table-wrap">
        <table class="data-table">
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
            <tr v-for="inv in filtered" :key="inv.id">
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
                <button class="btn btn-ghost btn-sm" @click="downloadPdf(inv)" title="Stáhnout PDF">
                  <Download :size="16" />
                  <span>PDF</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
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

@media (max-width: 768px) {
  .summary-bar {
    flex-wrap: wrap;
    gap: 12px;
  }
  .summary-sep { display: none; }
  .summary-item { flex: 1 1 40%; }
}
</style>
