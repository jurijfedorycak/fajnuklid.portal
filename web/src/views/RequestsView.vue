<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Loader2, ClipboardList, Plus, MessageSquare, Sparkles } from 'lucide-vue-next'
import { maintenanceRequestService, REQUEST_STATUSES } from '../api'

const router = useRouter()
const loading = ref(true)
const error = ref(null)
const requests = ref([])
const activeFilter = ref('all')

const filters = [{ key: 'all', label: 'Vše' }, ...REQUEST_STATUSES.map(s => ({ key: s.key, label: s.label }))]

async function load() {
  loading.value = true
  try {
    const res = await maintenanceRequestService.list({ status: 'all' })
    if (res.success) requests.value = res.data
    else error.value = res.message
  } catch (e) {
    error.value = e.message || 'Nepodařilo se načíst žádosti'
  } finally {
    loading.value = false
  }
}

onMounted(load)

const filtered = computed(() => {
  if (activeFilter.value === 'all') return requests.value
  return requests.value.filter(r => r.status === activeFilter.value)
})

function statusMeta(key) {
  return REQUEST_STATUSES.find(s => s.key === key) || { label: key, badge: 'badge-gray' }
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' })
}

function openDetail(id) {
  router.push(`/zadosti/${id}`)
}
</script>

<template>
  <div>
    <div v-if="loading" id="requests-loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám žádosti...</p>
    </div>

    <div v-else-if="error" id="requests-error" class="alert alert-danger">{{ error }}</div>

    <template v-else>
      <div id="requests-header" class="page-header">
        <div id="requests-header-text">
          <h1 id="requests-title" class="page-title">
            <ClipboardList :size="22" class="requests-title-icon" aria-hidden="true" />
            Požadavky a reklamace
          </h1>
          <p id="requests-subtitle" class="page-subtitle">
            Sledujte, co se děje, a mějte přehled o každém požadavku.
          </p>
        </div>
        <button id="requests-new-btn" class="btn btn-primary" @click="router.push('/zadosti/nova')">
          <Plus :size="16" />
          <span>Vytvořit požadavek</span>
        </button>
      </div>

      <!-- Brand-new-state onboarding (no requests at all) -->
      <div v-if="requests.length === 0" id="requests-onboarding" class="onboarding-hero">
        <div class="onboarding-hero-icon">
          <MessageSquare :size="28" aria-hidden="true" />
        </div>
        <h2 class="onboarding-hero-title">Máte problém, dotaz, nebo mimořádnou žádost?</h2>
        <p class="onboarding-hero-desc">
          Vytvořte požadavek online – my se na něj podíváme a co nejdřív vám dáme vědět.
          Každý požadavek má přehledný stav, takže vždy víte, na čem jste.
        </p>
        <div class="requests-onboarding-perks">
          <div class="requests-perk">
            <Sparkles :size="14" aria-hidden="true" />
            <span>Přidejte fotky a popis</span>
          </div>
          <div class="requests-perk">
            <Sparkles :size="14" aria-hidden="true" />
            <span>Sledujte stav v reálném čase</span>
          </div>
          <div class="requests-perk">
            <Sparkles :size="14" aria-hidden="true" />
            <span>Vše na jednom místě</span>
          </div>
        </div>
        <div class="onboarding-hero-actions">
          <button id="requests-onboarding-create" class="btn btn-primary btn-sm" @click="router.push('/zadosti/nova')">
            <Plus :size="14" aria-hidden="true" />
            <span>Vytvořit první požadavek</span>
          </button>
        </div>
      </div>

      <template v-else>
      <div id="requests-filters" class="chip-group" style="margin-bottom:16px;">
        <button
          v-for="f in filters"
          :key="f.key"
          :id="'requests-filter-' + f.key"
          class="chip"
          :class="{ active: activeFilter === f.key }"
          @click="activeFilter = f.key"
        >
          {{ f.label }}
        </button>
      </div>

      <div v-if="filtered.length === 0" id="requests-empty" class="card empty-state">
        <ClipboardList id="requests-empty-icon" :size="40" class="empty-state-icon" />
        <p class="empty-state-title">Žádné žádosti v tomto filtru</p>
        <p class="empty-state-text">Zkuste vybrat jiný stav nebo zobrazit vše.</p>
      </div>

      <div v-else id="requests-list" class="requests-list">
        <button
          v-for="r in filtered"
          :key="r.id"
          :id="'request-row-' + r.id"
          class="request-row"
          @click="openDetail(r.id)"
        >
          <div class="request-row-main">
            <div class="request-row-title">{{ r.title }}</div>
            <div class="request-row-meta">
              {{ statusMeta(r.status).label }} · {{ formatDate(r.createdAt) }}
            </div>
          </div>
          <span class="badge" :class="statusMeta(r.status).badge">
            {{ statusMeta(r.status).label }}
          </span>
        </button>
      </div>
      </template>
    </template>
  </div>
</template>

<style scoped>
.requests-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.request-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  width: 100%;
  padding: 18px 22px;
  background: var(--color-gray-50);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  cursor: pointer;
  transition: var(--transition);
  text-align: left;
}

.request-row:hover {
  border-color: var(--color-mid);
  background: var(--color-white);
  box-shadow: var(--shadow-sm);
}

.request-row-main {
  flex: 1;
  min-width: 0;
}

.request-row-title {
  font-size: 15px;
  font-weight: 500;
  color: var(--color-primary);
  margin-bottom: 4px;
}

.request-row-meta {
  font-size: 12px;
  color: var(--color-gray-500);
}

.spin { animation: spin 1.5s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.requests-title-icon {
  vertical-align: -4px;
  margin-right: 8px;
  color: var(--color-mid);
}

/* Make the request row friendlier on mobile — title/badge stack vertically */
@media (max-width: 479.98px) {
  .request-row {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
    padding: 16px 18px;
  }
}

.requests-onboarding-perks {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 14px;
  width: 100%;
  max-width: 420px;
}
@media (min-width: 640px) {
  .requests-onboarding-perks {
    flex-direction: row;
    justify-content: center;
    max-width: none;
  }
}

.requests-perk {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-700);
  padding: 8px 12px;
  background: var(--color-gray-50);
  border-radius: var(--radius-md);
  flex: 1;
  justify-content: center;
}
.requests-perk svg {
  color: var(--color-accent);
  flex-shrink: 0;
}
</style>
