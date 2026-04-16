<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  ShieldCheck, Users, Plus, Search, Loader2,
} from 'lucide-vue-next'
import { adminService } from '../api'

const router = useRouter()

// State
const loading = ref(true)
const error = ref(null)
const clients = ref([])
const searchQuery = ref('')

// Map API response (snake_case) to frontend (camelCase)
function mapClientFromApi(c) {
  return {
    id: c.id,
    clientId: c.client_id,
    displayName: c.display_name,
    email: c.email,
    icos: c.icos || [],
    active: !!c.active,
    lastLogin: c.last_login,
    createdAt: c.created_at,
  }
}

// Fetch data
onMounted(async () => {
  try {
    const response = await adminService.getClients()
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
})

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

function newClient() {
  router.push('/admin/clients/new')
}

function formatDate(d) {
  if (!d || d === '—') return '—'
  const [y, m, day] = d.split('-')
  return `${day}.${m}.${y}`
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
      <button id="btn-add-client" class="btn btn-primary" @click="newClient">
        <Plus :size="16" />
        Přidat klienta
      </button>
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
    <div class="admin-stats">
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

    <!-- Client table -->
    <div class="card">
      <div class="table-toolbar">
        <h3 class="card-title" style="margin-bottom:0;">Klienti portálu</h3>
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

      <!-- Empty state for no clients -->
      <div v-if="filtered.length === 0 && !searchQuery" class="empty-state-guide" style="margin:20px 0;">
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
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="client in filtered"
              :key="client.clientId"
              class="client-row"
              @click="editClient(client.clientId)"
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
                <span class="badge" :class="client.active ? 'badge-success' : 'badge-gray'">
                  {{ client.active ? 'Aktivní' : 'Neaktivní' }}
                </span>
              </td>
              <td class="text-muted">{{ formatDate(client.lastLogin) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    </template>
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
  font-size: 13px;
}

.table-wrap {
  margin-top: 16px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.ico-chips { display: flex; gap: 4px; flex-wrap: wrap; }

/* Clickable rows */
.client-row {
  cursor: pointer;
}
.client-row:hover td {
  background: var(--color-light);
}

/* .admin-stats + .search-wrap handled mobile-first above */
</style>
