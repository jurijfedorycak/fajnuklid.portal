<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  ShieldCheck, Users, Plus, Search, Loader2, RotateCcw, Archive,
} from 'lucide-vue-next'
import { adminService } from '../api'

const router = useRouter()

// State
const loading = ref(true)
const error = ref(null)
const clients = ref([])
const searchQuery = ref('')
const statusFilter = ref('active') // 'active' | 'archived'

// Restore confirmation + feedback
const restoreConfirm = ref({ show: false, clientId: null, name: '' })
const restoring = ref(false)
const toast = ref(null)
let toastTimer = null
function showToast(type, message) {
  toast.value = { type, message }
  if (toastTimer) clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toast.value = null }, 3000)
}

// Map API response (snake_case) to frontend (camelCase)
function mapClientFromApi(c) {
  return {
    id: c.id,
    clientId: c.client_id,
    displayName: c.display_name,
    email: c.email,
    icos: c.icos || [],
    active: !!c.active,
    archived: !!c.archived,
    lastLogin: c.last_login,
    createdAt: c.created_at,
  }
}

async function loadClients() {
  loading.value = true
  error.value = null
  try {
    const response = await adminService.getClients(1, 100, null, statusFilter.value)
    if (response.success) {
      // API returns data as array directly, not nested under 'clients'
      const rawData = Array.isArray(response.data) ? response.data : (response.data?.clients || [])
      clients.value = rawData.map(mapClientFromApi)
    } else {
      error.value = response.message || 'Nepodařilo se načíst data'
    }
  } catch (err) {
    error.value = err.message || 'Nepodařilo se načíst data'
  } finally {
    loading.value = false
  }
}

onMounted(loadClients)

function setStatusFilter(status) {
  if (statusFilter.value === status) return
  statusFilter.value = status
  searchQuery.value = ''
  loadClients()
}

const isArchivedView = computed(() => statusFilter.value === 'archived')

const filtered = computed(() => {
  const q = searchQuery.value.toLowerCase()
  if (!q) return clients.value
  return clients.value.filter(c =>
    (c.displayName || '').toLowerCase().includes(q) ||
    (c.email || '').toLowerCase().includes(q) ||
    (c.icos || []).some(i => i.includes(q))
  )
})

const stats = computed(() => ({
  total:    clients.value.length,
  active:   clients.value.filter(c => c.active).length,
  inactive: clients.value.filter(c => !c.active).length,
}))


function editClient(clientId) {
  router.push(`/admin/clients/${clientId}`)
}

function onRowClick(client) {
  // Archived clients are read-only until restored — the edit endpoint hides them.
  if (isArchivedView.value) return
  editClient(client.clientId)
}

function askRestore(client) {
  restoreConfirm.value = { show: true, clientId: client.clientId, name: client.displayName }
}

function cancelRestore() {
  restoreConfirm.value = { show: false, clientId: null, name: '' }
}

async function executeRestore() {
  const { clientId } = restoreConfirm.value
  if (!clientId) return
  restoring.value = true
  try {
    const res = await adminService.restoreClient(clientId)
    if (res.success) {
      showToast('success', 'Klient byl obnoven')
      cancelRestore()
      await loadClients()
    } else {
      showToast('error', res.message || 'Obnovení se nezdařilo')
    }
  } catch (err) {
    showToast('error', err.response?.data?.message || err.message || 'Obnovení se nezdařilo')
  } finally {
    restoring.value = false
  }
}

function newClient() {
  router.push('/admin/clients/new')
}

function formatDate(d) {
  if (!d || d === '—') return '—'
  const [datePart, timePart] = d.split(/[ T]/)
  const [y, m, day] = datePart.split('-')
  if (!timePart) return `${day}.${m}.${y}`
  const [hh, mm] = timePart.split(':')
  return `${day}.${m}.${y} ${hh}:${mm}`
}
</script>

<template>
  <div id="admin-clients-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <ShieldCheck :size="24" style="vertical-align:middle; margin-right:8px; color:var(--color-mid);" />
          Správa portálu
        </h1>
        <p class="page-subtitle">Admin sekce · přístup pouze pro Jurij Fedoryčak</p>
      </div>
      <div id="admin-clients-header-actions" class="header-actions">
        <!-- Active / archived toggle — lives in the header so it adds no extra row on desktop -->
        <div id="admin-client-status-filter" class="seg" role="tablist" aria-label="Zobrazení klientů">
          <button
            id="seg-clients-active"
            type="button"
            class="seg-btn"
            :class="{ active: !isArchivedView }"
            role="tab"
            :aria-selected="!isArchivedView"
            @click="setStatusFilter('active')"
          >
            <Users :size="15" /> Aktivní klienti
          </button>
          <button
            id="seg-clients-archived"
            type="button"
            class="seg-btn"
            :class="{ active: isArchivedView }"
            role="tab"
            :aria-selected="isArchivedView"
            @click="setStatusFilter('archived')"
          >
            <Archive :size="15" /> Archivovaní
          </button>
        </div>
        <button id="btn-add-client" class="btn btn-primary" @click="newClient">
          <Plus :size="16" />
          Přidat klienta
        </button>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám data...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Content -->
    <template v-else>
    <!-- Stats -->
    <div v-if="!isArchivedView" class="admin-stats">
      <div class="card admin-stat">
        <div class="stat-num">{{ stats.total }}</div>
        <div class="stat-lbl">Celkem klientů</div>
      </div>
      <div class="card admin-stat">
        <div class="stat-num text-success">{{ stats.active }}</div>
        <div class="stat-lbl">Aktivních</div>
      </div>
      <div class="card admin-stat">
        <div class="stat-num text-muted">{{ stats.inactive }}</div>
        <div class="stat-lbl">Neaktivních</div>
      </div>
    </div>
    <div v-else class="admin-stats admin-stats--single">
      <div class="card admin-stat">
        <div class="stat-num text-muted">{{ stats.total }}</div>
        <div class="stat-lbl">Archivovaných klientů</div>
      </div>
    </div>

    <!-- Client table -->
    <div class="card">
      <div class="table-toolbar">
        <h3 class="card-title" style="margin-bottom:0;">{{ isArchivedView ? 'Archivovaní klienti' : 'Klienti portálu' }}</h3>
        <div class="search-wrap">
          <Search :size="15" class="search-icon" />
          <input
            id="admin-client-search"
            v-model="searchQuery"
            type="search"
            class="form-input search-input"
            placeholder="Hledat klienta, email, IČO..."
            aria-label="Hledat klienta"
          />
        </div>
      </div>

      <!-- Empty state — archived view -->
      <div v-if="filtered.length === 0 && !searchQuery && isArchivedView" id="admin-archived-empty" class="empty-list-hint" style="margin:20px 0; display:flex; align-items:center; gap:10px; padding:20px 16px; border:2px dashed var(--color-gray-200); border-radius:var(--radius-md); font-size:13px; color:var(--color-gray-500);">
        <Archive :size="28" /> Žádní archivovaní klienti.
      </div>

      <!-- Empty state for no clients -->
      <div v-else-if="filtered.length === 0 && !searchQuery" class="empty-state-guide" style="margin:20px 0;">
        <div class="empty-state-guide-icon"><Users :size="28" /></div>
        <div class="empty-state-guide-title">Zatím nemáte žádné klienty</div>
        <div class="empty-state-guide-desc">
          Klienti uvidí v portálu přehled faktur, personál a historii úklidů.
        </div>
        <button class="btn btn-primary" @click="newClient">
          <Plus :size="16" /> Přidat prvního klienta
        </button>
      </div>

      <!-- Empty state for search with no results -->
      <div v-else-if="filtered.length === 0" class="empty-list-hint" style="margin:20px 0; display:flex; align-items:center; gap:10px; padding:20px 16px; border:2px dashed var(--color-gray-200); border-radius:var(--radius-md); font-size:13px; color:var(--color-gray-500);">
        <Users :size="28" /> Žádní klienti neodpovídají hledání.
      </div>

      <!-- Table with clients -->
      <div v-else id="admin-client-table-wrap" class="table-wrap table-wrap--sticky-first">
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Název firmy</th>
              <th scope="col">E-mail</th>
              <th scope="col">IČO</th>
              <th scope="col">Stav</th>
              <th scope="col">Poslední přihlášení</th>
              <th v-if="isArchivedView" scope="col">Akce</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="client in filtered"
              :key="client.clientId"
              class="client-row"
              :class="{ 'is-clickable': !isArchivedView }"
              @click="onRowClick(client)"
            >
              <td class="fw-600 text-muted" style="font-size:12px;">{{ client.clientId }}</td>
              <td class="fw-500" style="color:var(--color-primary)">{{ client.displayName }}</td>
              <td class="text-muted">{{ client.email }}</td>
              <td>
                <div class="ico-chips">
                  <span v-for="ico in client.icos" :key="ico" class="badge badge-gray">{{ ico }}</span>
                </div>
              </td>
              <td>
                <span
                  v-if="client.archived"
                  class="badge badge-warning"
                >Archivováno</span>
                <span
                  v-else
                  class="badge"
                  :class="client.active ? 'badge-success' : 'badge-gray'"
                >{{ client.active ? 'Aktivní' : 'Neaktivní' }}</span>
              </td>
              <td class="text-muted">{{ formatDate(client.lastLogin) }}</td>
              <td v-if="isArchivedView">
                <button
                  :id="`btn-restore-${client.clientId}`"
                  type="button"
                  class="btn btn-sm btn-outline"
                  @click.stop="askRestore(client)"
                >
                  <RotateCcw :size="14" /> Obnovit
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    </template>

    <!-- Restore confirmation modal -->
    <Teleport to="body">
      <div v-if="restoreConfirm.show" id="client-restore-modal" class="modal-overlay" @click.self="cancelRestore">
        <div
          id="client-restore-modal-content"
          class="modal-content"
          role="alertdialog"
          aria-modal="true"
          aria-labelledby="client-restore-modal-title"
          aria-describedby="client-restore-modal-desc"
        >
          <h3 id="client-restore-modal-title" class="modal-title">Obnovit klienta?</h3>
          <p id="client-restore-modal-desc" class="modal-desc">
            Klient <strong>{{ restoreConfirm.name }}</strong> se vrátí mezi aktivní a jeho přihlášení do portálu se znovu povolí.
          </p>
          <div class="modal-actions">
            <button id="client-restore-cancel-btn" type="button" class="btn btn-ghost" :disabled="restoring" @click="cancelRestore">
              Zrušit
            </button>
            <button id="client-restore-confirm-btn" type="button" class="btn btn-primary" :disabled="restoring" @click="executeRestore">
              <Loader2 v-if="restoring" :size="14" class="spin" />
              <RotateCcw v-else :size="14" />
              Obnovit
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <div v-if="toast" id="admin-clients-toast" class="toast" :class="'toast-' + toast.type">
      {{ toast.message }}
    </div>
  </div>
</template>

<style scoped>
/* Mobile-first admin stats: 1 → 2 (sm) → 3 (md) */
.admin-stats {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
  margin-bottom: 20px;
}
@media (min-width: 640px) {
  .admin-stats {
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
  }
}
@media (min-width: 1024px) {
  .admin-stats {
    grid-template-columns: repeat(3, 1fr);
  }
}

.admin-stat { text-align: center; }

.stat-num {
  font-size: var(--fs-3xl);
  font-weight: 700;
  color: var(--color-primary);
}

.stat-lbl {
  font-size: 12px;
  color: var(--color-gray-600);
  margin-top: 4px;
}

.table-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.search-wrap {
  position: relative;
  width: 100%;
}
@media (min-width: 640px) {
  .search-wrap { max-width: 280px; }
}

.search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-gray-500);
}

.search-input {
  width: 100%;
  padding-left: 32px;
  /* 16px prevents iOS Safari from auto-zooming on focus; desktop restores 13px below */
  font-size: 16px;
}
@media (min-width: 768px) {
  .search-input {
    font-size: 13px;
  }
}

.table-wrap {
  margin-top: 16px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.ico-chips { display: flex; gap: 4px; flex-wrap: wrap; }

/* Clickable rows (active view only; archived rows are read-only) */
.client-row.is-clickable {
  cursor: pointer;
}
.client-row.is-clickable:hover td {
  background: var(--color-light);
}

/* Header action group: toggle + add button share the header row (no extra
   vertical band on desktop). Wraps under the title only on narrow screens. */
.header-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

/* Active / archived segmented control */
.seg {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  background: var(--color-gray-100);
  border-radius: var(--radius-md);
}
.seg-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border: none;
  background: transparent;
  border-radius: var(--radius-sm);
  font-size: 13px;
  font-weight: 500;
  color: var(--color-gray-600);
  cursor: pointer;
}
.seg-btn.active {
  background: var(--color-white);
  color: var(--color-primary);
  box-shadow: var(--shadow-sm);
}

.admin-stats--single {
  grid-template-columns: 1fr;
  max-width: 260px;
}

/* Restore confirmation modal (mirrors the employees delete-modal pattern) */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: var(--color-overlay);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.modal-content {
  background: var(--color-white);
  padding: 24px;
  border-radius: var(--radius-lg);
  max-width: 400px;
  width: 90%;
  box-shadow: var(--shadow-lg);
}
.modal-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 8px;
}
.modal-desc {
  font-size: 14px;
  color: var(--color-gray-600);
  line-height: 1.5;
}
.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 20px;
}

/* .admin-stats + .search-wrap handled mobile-first above */
</style>
