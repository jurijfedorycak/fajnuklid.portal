<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  ShieldCheck, Users, Plus, Edit2, Power, PowerOff, Search, ExternalLink, ArrowRight
} from 'lucide-vue-next'
import { adminClients, adminEmployees } from '../data/mockData.js'

const router = useRouter()
const clients = ref(adminClients.map(c => ({ ...c })))
const searchQuery = ref('')

const filtered = computed(() => {
  const q = searchQuery.value.toLowerCase()
  if (!q) return clients.value
  return clients.value.filter(c =>
    c.displayName.toLowerCase().includes(q) ||
    c.email.toLowerCase().includes(q) ||
    c.icos.some(i => i.includes(q))
  )
})

const stats = computed(() => ({
  total:    clients.value.length,
  active:   clients.value.filter(c => c.active).length,
  inactive: clients.value.filter(c => !c.active).length,
}))

function toggleActive(client) { client.active = !client.active }

function editClient(clientId) {
  router.push(`/admin/klient/${clientId}`)
}

function newClient() {
  router.push('/admin/klient/novy')
}

function formatDate(d) {
  if (d === '—') return '—'
  const [y, m, day] = d.split('-')
  return `${day}.${m}.${y}`
}
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <ShieldCheck :size="24" style="vertical-align:middle; margin-right:8px; color:var(--color-mid);" />
          Správa portálu
        </h1>
        <p class="page-subtitle">Admin sekce · přístup pouze pro Jurij Fedoryčak</p>
      </div>
      <button class="btn btn-primary" @click="newClient">
        <Plus :size="16" />
        Přidat klienta
      </button>
    </div>

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
            v-model="searchQuery"
            type="text"
            class="form-input search-input"
            placeholder="Hledat klienta, email, IČO..."
          />
        </div>
      </div>

      <div class="table-wrap" style="margin-top:16px;">
        <table class="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Název firmy</th>
              <th>E-mail</th>
              <th>IČO</th>
              <th>Stav</th>
              <th>Poslední přihlášení</th>
              <th>Akce</th>
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
              <td>
                <div class="action-btns" @click.stop>
                  <button
                    class="btn btn-ghost btn-sm"
                    @click="toggleActive(client)"
                    :title="client.active ? 'Deaktivovat' : 'Aktivovat'"
                  >
                    <PowerOff v-if="client.active" :size="15" style="color:var(--color-danger)" />
                    <Power    v-else                :size="15" style="color:var(--color-success)" />
                  </button>
                  <button class="btn btn-ghost btn-sm" title="Upravit" @click="editClient(client.clientId)">
                    <Edit2 :size="15" />
                  </button>
                  <button class="btn btn-ghost btn-sm" title="Otevřít v Airtable">
                    <ExternalLink :size="15" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Employees section -->
    <div class="card emp-section" @click="router.push('/admin/zamestnanci')" style="cursor:pointer;">
      <div class="emp-section-row">
        <div class="emp-section-left">
          <Users :size="20" style="color:var(--color-mid);" />
          <div>
            <h3 class="card-title" style="margin-bottom:2px;">Zaměstnanci</h3>
            <p class="text-muted" style="font-size:13px;">Správa zaměstnanců, pracovní smlouvy, GDPR viditelnost v portálu</p>
          </div>
        </div>
        <div class="emp-section-right">
          <div class="emp-mini-stats">
            <span class="emp-mini-stat">
              <strong>{{ adminEmployees.length }}</strong>
              <span class="text-muted">celkem</span>
            </span>
            <span class="emp-mini-stat">
              <strong class="text-success">{{ adminEmployees.filter(e => e.showInPortal).length }}</strong>
              <span class="text-muted">v portálu</span>
            </span>
            <span class="emp-mini-stat">
              <strong style="color:var(--color-mid);">{{ adminEmployees.filter(e => e.contractFile).length }}</strong>
              <span class="text-muted">se smlouvou</span>
            </span>
          </div>
          <ArrowRight :size="18" class="text-muted" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 20px;
}

.admin-stat { text-align: center; }

.stat-num {
  font-size: 28px;
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
  max-width: 280px;
  width: 100%;
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

.ico-chips { display: flex; gap: 4px; flex-wrap: wrap; }
.action-btns { display: flex; gap: 4px; }

/* Clickable rows */
.client-row {
  cursor: pointer;
}
.client-row:hover td {
  background: var(--color-light) !important;
}

/* Employee section */
.emp-section {
  margin-top: 20px;
  transition: box-shadow var(--transition), border-color var(--transition);
  border: 1.5px solid transparent;
}
.emp-section:hover {
  box-shadow: var(--shadow-lg);
  border-color: var(--color-light);
}

.emp-section-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.emp-section-left {
  display: flex;
  align-items: center;
  gap: 14px;
}

.emp-section-right {
  display: flex;
  align-items: center;
  gap: 20px;
}

.emp-mini-stats {
  display: flex;
  gap: 20px;
}

.emp-mini-stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  font-size: 12px;
}
.emp-mini-stat strong {
  font-size: 18px;
  font-weight: 700;
  color: var(--color-primary);
}
</style>
