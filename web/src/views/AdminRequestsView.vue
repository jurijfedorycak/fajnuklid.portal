<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ClipboardList, Loader2, Search } from 'lucide-vue-next'
import { adminService, REQUEST_STATUSES, REQUEST_CATEGORIES } from '../api'

const router = useRouter()

const loading = ref(true)
const error = ref(null)
const requests = ref([])
const clients = ref([])
const activeStatus = ref('all')
const activeClientId = ref('')
const searchQuery = ref('')

const filters = [{ key: 'all', label: 'Vše' }, ...REQUEST_STATUSES.map(s => ({ key: s.key, label: s.label }))]

async function loadAll() {
  loading.value = true
  error.value = null
  try {
    const [reqRes, clientsRes] = await Promise.all([
      adminService.getMaintenanceRequests(activeClientId.value || null, activeStatus.value),
      adminService.getClients(1, 200),
    ])
    if (reqRes.success) {
      requests.value = reqRes.data
    } else {
      error.value = reqRes.message
    }
    if (clientsRes.success) {
      const raw = Array.isArray(clientsRes.data) ? clientsRes.data : (clientsRes.data?.clients || [])
      clients.value = raw.map(c => ({ id: c.id, name: c.display_name }))
    }
  } catch (e) {
    error.value = e.message || 'Nepodařilo se načíst žádosti'
  } finally {
    loading.value = false
  }
}

onMounted(loadAll)

async function onFilterChange() {
  await loadAll()
}

function statusMeta(key) {
  return REQUEST_STATUSES.find(s => s.key === key) || { label: key, badge: 'badge-gray' }
}

function categoryLabel(key) {
  return REQUEST_CATEGORIES.find(c => c.key === key)?.label || key
}

const filtered = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return requests.value
  return requests.value.filter(r =>
    (r.title || '').toLowerCase().includes(q) ||
    (r.clientDisplayName || '').toLowerCase().includes(q)
  )
})

const stats = computed(() => ({
  total:    requests.value.length,
  prijato:  requests.value.filter(r => r.status === 'prijato').length,
  resi:     requests.value.filter(r => r.status === 'resi_se').length,
  vyreseno: requests.value.filter(r => r.status === 'vyreseno').length,
}))

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' })
}

function openDetail(id) {
  router.push(`/admin/zadosti/${id}`)
}
</script>

<template>
  <div>
    <div id="admin-requests-header" class="page-header">
      <div>
        <h1 id="admin-requests-title" class="page-title">
          <ClipboardList :size="22" style="vertical-align:-4px; margin-right:8px; color:var(--color-mid);" />
          Žádosti o údržbu
        </h1>
        <p id="admin-requests-subtitle" class="page-subtitle">
          Správa všech žádostí napříč klienty.
        </p>
      </div>
    </div>

    <div v-if="loading" id="admin-requests-loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p id="admin-requests-loading-text" style="margin-top:12px; color:var(--color-gray-600);">Načítám žádosti...</p>
    </div>

    <div v-else-if="error" id="admin-requests-error" class="alert alert-danger">{{ error }}</div>

    <template v-else>
      <!-- Stats tiles -->
      <div id="admin-requests-stats" class="stats-grid">
        <div id="admin-stats-total" class="stat-tile stat-tile--featured">
          <span class="stat-lbl">Celkem</span>
          <span class="stat-val">{{ stats.total }}</span>
        </div>
        <div id="admin-stats-prijato" class="stat-tile stat-tile--prijato">
          <span class="stat-lbl">Přijato</span>
          <span class="stat-val">{{ stats.prijato }}</span>
        </div>
        <div id="admin-stats-resi" class="stat-tile stat-tile--resi">
          <span class="stat-lbl">Řeší se</span>
          <span class="stat-val">{{ stats.resi }}</span>
        </div>
        <div id="admin-stats-vyreseno" class="stat-tile stat-tile--vyreseno">
          <span class="stat-lbl">Vyřešeno</span>
          <span class="stat-val">{{ stats.vyreseno }}</span>
        </div>
      </div>

      <!-- Filters -->
      <div id="admin-requests-filters" class="filters-row">
        <div class="filter-group">
          <label class="filter-label" for="admin-requests-client-select">Klient</label>
          <select
            id="admin-requests-client-select"
            v-model="activeClientId"
            class="form-input"
            @change="onFilterChange"
          >
            <option value="">Všichni klienti</option>
            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>

        <div class="filter-group filter-group-search">
          <label class="filter-label" for="admin-requests-search">Hledat</label>
          <div class="search-wrap">
            <Search :size="14" class="search-icon" />
            <input
              id="admin-requests-search"
              v-model="searchQuery"
              class="form-input"
              type="text"
              placeholder="Hledat název nebo klienta..."
            />
          </div>
        </div>
      </div>

      <div id="admin-requests-status-chips" class="chip-group" style="margin-bottom:16px;">
        <button
          v-for="f in filters"
          :key="f.key"
          :id="'admin-requests-status-' + f.key"
          class="chip"
          :class="{ active: activeStatus === f.key }"
          @click="activeStatus = f.key; onFilterChange()"
        >
          {{ f.label }}
        </button>
      </div>

      <!-- Table + mobile cards -->
      <div id="admin-requests-table-card" class="card">
        <div v-if="filtered.length === 0" id="admin-requests-empty" class="empty-state">
          <ClipboardList :size="40" class="empty-state-icon" />
          <p class="empty-state-title">Žádné žádosti k zobrazení.</p>
          <p class="empty-state-text">Zkuste jiný filtr.</p>
        </div>

        <template v-else>
          <!-- Mobile cards (< 768px) -->
          <div id="admin-requests-cards" class="requests-cards">
            <div
              v-for="r in filtered"
              :key="r.id"
              :id="'admin-request-card-' + r.id"
              class="request-card"
              role="button"
              tabindex="0"
              @click="openDetail(r.id)"
              @keydown.enter="openDetail(r.id)"
              @keydown.space.prevent="openDetail(r.id)"
            >
              <div class="request-card-head">
                <span class="request-card-client">{{ r.clientDisplayName || '—' }}</span>
                <span class="badge" :class="statusMeta(r.status).badge">
                  {{ statusMeta(r.status).label }}
                </span>
              </div>
              <h3 class="request-card-title">{{ r.title }}</h3>
              <dl class="request-card-meta">
                <dt>Vytvořeno</dt>
                <dd>{{ formatDate(r.createdAt) }}</dd>
                <dt>Termín</dt>
                <dd>{{ formatDate(r.dueDate) }}</dd>
                <dt>Kategorie</dt>
                <dd>{{ categoryLabel(r.category) }}</dd>
                <template v-if="r.locationValue">
                  <dt>Místo</dt>
                  <dd>{{ r.locationValue }}</dd>
                </template>
              </dl>
            </div>
          </div>

          <!-- Desktop table (≥ 768px) -->
          <div class="table-wrap table-wrap--sticky-first requests-table-wrap">
            <table id="admin-requests-table" class="data-table">
              <thead>
                <tr>
                  <th>Vytvořeno</th>
                  <th>Klient</th>
                  <th>Název</th>
                  <th>Kategorie</th>
                  <th>Místo</th>
                  <th>Stav</th>
                  <th>Termín</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="r in filtered"
                  :key="r.id"
                  :id="'admin-request-row-' + r.id"
                  class="clickable-row"
                  @click="openDetail(r.id)"
                >
                  <td class="text-muted">{{ formatDate(r.createdAt) }}</td>
                  <td class="fw-500" style="color:var(--color-primary)">{{ r.clientDisplayName || '—' }}</td>
                  <td>{{ r.title }}</td>
                  <td class="text-muted">{{ categoryLabel(r.category) }}</td>
                  <td class="text-muted">{{ r.locationValue || '—' }}</td>
                  <td>
                    <span class="badge" :class="statusMeta(r.status).badge">
                      {{ statusMeta(r.status).label }}
                    </span>
                  </td>
                  <td class="text-muted">{{ formatDate(r.dueDate) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Stat tiles — mobile-first: 2-col grid with Celkem spanning full width, single row ≥640px */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 16px;
}

.stat-tile {
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-left: 3px solid var(--color-gray-300);
  border-radius: var(--radius-md);
  padding: 10px 14px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 4px;
  min-height: 64px;
}

.stat-tile--featured {
  grid-column: 1 / -1;
  border-left-color: var(--color-primary);
  background: var(--color-gray-50);
}

.stat-tile--prijato  { border-left-color: var(--color-mid); }
.stat-tile--resi     { border-left-color: var(--color-warning); }
.stat-tile--vyreseno { border-left-color: var(--color-success); }

.stat-lbl {
  font-size: 11px;
  color: var(--color-gray-600);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-weight: 500;
  line-height: 1.3;
}

.stat-val {
  font-size: 22px;
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1;
}

.stat-tile--prijato  .stat-val { color: var(--color-mid); }
.stat-tile--resi     .stat-val { color: var(--color-warning); }
.stat-tile--vyreseno .stat-val { color: var(--color-success); }

@media (min-width: 640px) {
  .stats-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .stat-tile--featured { grid-column: auto; }
}

.filters-row {
  display: flex;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}

.filter-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1 1 100%;
}
@media (min-width: 640px) {
  .filter-group { flex: 0 1 auto; min-width: 220px; }
}

.filter-group-search {
  flex: 1;
}

.filter-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.search-wrap {
  position: relative;
}

.search-wrap .form-input {
  padding-left: 32px;
}

.search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-gray-400);
}

.clickable-row { cursor: pointer; }

/* Mobile cards for requests — shown < 768px, hidden ≥ 768px */
.requests-cards {
  display: grid;
  gap: 10px;
}
.requests-table-wrap {
  display: none;
}
@media (min-width: 768px) {
  .requests-cards { display: none; }
  .requests-table-wrap { display: block; }
}

.request-card {
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  cursor: pointer;
  transition: var(--transition);
  text-align: left;
}
.request-card:hover {
  border-color: var(--color-mid);
  box-shadow: var(--shadow-sm);
}
.request-card:focus-visible {
  outline: 2px solid var(--color-mid);
  outline-offset: 2px;
}

.request-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.request-card-client {
  font-size: 11px;
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-weight: 600;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.request-card-title {
  font-size: 15px;
  font-weight: 500;
  color: var(--color-primary);
  margin: 0;
  line-height: 1.35;
}

.request-card-meta {
  display: grid;
  grid-template-columns: minmax(0, auto) minmax(0, 1fr);
  gap: 4px 14px;
  margin: 0;
  padding-top: 8px;
  border-top: 1px solid var(--color-gray-100);
  font-size: 13px;
}
.request-card-meta dt {
  color: var(--color-gray-500);
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  align-self: center;
}
.request-card-meta dd {
  color: var(--color-gray-800);
  text-align: right;
  margin: 0;
  word-break: break-word;
}

.spin { animation: spin 1.5s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
