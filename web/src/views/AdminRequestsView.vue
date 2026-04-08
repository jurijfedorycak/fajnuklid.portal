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
  ceka:     requests.value.filter(r => r.status === 'ceka_na_potvrzeni').length,
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
      <!-- Stats bar -->
      <div id="admin-requests-stats" class="summary-bar card" style="margin-bottom:16px;">
        <div id="admin-stats-total" class="summary-item">
          <span class="summary-val">{{ stats.total }}</span>
          <span class="summary-lbl">Celkem</span>
        </div>
        <div class="summary-sep" />
        <div id="admin-stats-prijato" class="summary-item">
          <span class="summary-val text-mid">{{ stats.prijato }}</span>
          <span class="summary-lbl">Přijato</span>
        </div>
        <div class="summary-sep" />
        <div id="admin-stats-resi" class="summary-item">
          <span class="summary-val text-warning">{{ stats.resi }}</span>
          <span class="summary-lbl">Řeší se</span>
        </div>
        <div class="summary-sep" />
        <div id="admin-stats-ceka" class="summary-item">
          <span class="summary-val text-mid">{{ stats.ceka }}</span>
          <span class="summary-lbl">Čeká na potvrzení</span>
        </div>
        <div class="summary-sep" />
        <div id="admin-stats-vyreseno" class="summary-item">
          <span class="summary-val text-success">{{ stats.vyreseno }}</span>
          <span class="summary-lbl">Vyřešeno</span>
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

      <!-- Table -->
      <div id="admin-requests-table-card" class="card">
        <div v-if="filtered.length === 0" id="admin-requests-empty" class="empty-state">
          <ClipboardList :size="40" class="empty-state-icon" />
          <p class="empty-state-title">Žádné žádosti k zobrazení.</p>
          <p class="empty-state-text">Zkuste jiný filtr.</p>
        </div>

        <div v-else class="table-wrap">
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
      </div>
    </template>
  </div>
</template>

<style scoped>
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
  min-width: 220px;
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

.spin { animation: spin 1.5s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 768px) {
  .summary-bar { flex-wrap: wrap; gap: 12px; }
  .summary-sep { display: none; }
  .summary-item { flex: 1 1 40%; }
}
</style>
