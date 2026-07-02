<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Loader2, Plus, CirclePlus, CheckCircle2, Play, PlayCircle, CheckSquare, MessageSquare } from 'lucide-vue-next'
import { maintenanceRequestService } from '../api'
import EmptyRequestsIllustration from '../components/EmptyRequestsIllustration.vue'

const router = useRouter()
const loading = ref(true)
const error = ref(null)
const requests = ref([])
const activeTab = ref('prijato')

const TABS = [
  { key: 'prijato', label: 'Nové' },
  { key: 'resi_se', label: 'V řešení' },
  { key: 'vyreseno', label: 'Vyřízeno' },
]

const STATUS_PILLS = {
  prijato: { label: 'Nový', cls: 'pill-new' },
  resi_se: { label: 'V řešení', cls: 'pill-progress' },
  vyreseno: { label: 'Vyřízeno', cls: 'pill-done' },
}

const TIMELINE_STEPS = ['Přijato', 'Zpracovává se', 'Hotovo']

async function load() {
  loading.value = true
  try {
    const res = await maintenanceRequestService.list({ status: 'all' })
    if (res.success) {
      requests.value = res.data
      const firstWithItems = TABS.find(t => res.data.some(r => r.status === t.key))
      if (firstWithItems) activeTab.value = firstWithItems.key
    } else {
      error.value = res.message
    }
  } catch (e) {
    error.value = e.message || 'Nepodařilo se načíst požadavky'
  } finally {
    loading.value = false
  }
}

onMounted(load)

const filtered = computed(() => requests.value.filter(r => r.status === activeTab.value))

function requestNumber(id) {
  return `#REQ-${String(id).padStart(4, '0')}`
}

function pillMeta(status) {
  return STATUS_PILLS[status] || { label: status, cls: 'pill-new' }
}

// done: the step already happened; current: in progress right now (blue play marker)
function stepState(status, index) {
  if (status === 'vyreseno') return 'done'
  if (index === 0) return 'done'
  if (status === 'resi_se' && index === 1) return 'current'
  return 'pending'
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' })
}

function newMessagesLabel(n) {
  if (n === 1) return '1 nová zpráva'
  if (n >= 2 && n <= 4) return `${n} nové zprávy`
  return `${n} nových zpráv`
}

function openDetail(id) {
  router.push(`/zadosti/${id}`)
}
</script>

<template>
  <div id="requests-page">
    <div v-if="loading" id="requests-loading" class="requests-loading">
      <Loader2 :size="32" class="spin" style="color:var(--color-blue);" />
      <p>Načítám požadavky...</p>
    </div>

    <div v-else-if="error" id="requests-error" class="alert alert-danger">{{ error }}</div>

    <template v-else>
      <div id="requests-header" class="requests-header">
        <h1 id="requests-title" class="requests-title">Požadavky</h1>
        <button
          v-if="requests.length > 0"
          id="requests-new-btn"
          class="requests-add-btn"
          aria-label="Nový požadavek"
          @click="router.push('/zadosti/nova')"
        >
          <Plus :size="18" />
        </button>
      </div>

      <!-- Empty state: no requests at all -->
      <div v-if="requests.length === 0" id="requests-empty-state" class="requests-empty">
        <EmptyRequestsIllustration class="requests-empty-art" />
        <h2 id="requests-empty-title" class="requests-empty-title">Vše je v pořádku</h2>
        <p id="requests-empty-text" class="requests-empty-text">
          Momentálně neevidujeme žádné otevřené požadavky ani reklamace.
        </p>
        <button id="requests-empty-create" class="requests-cta" @click="router.push('/zadosti/nova')">
          <CirclePlus :size="18" />
          <span>Nový požadavek</span>
        </button>
      </div>

      <template v-else>
        <div id="requests-tabs" class="requests-tabs" role="tablist">
          <button
            v-for="t in TABS"
            :key="t.key"
            :id="'requests-tab-' + t.key"
            class="requests-tab"
            :class="{ active: activeTab === t.key }"
            role="tab"
            :aria-selected="activeTab === t.key"
            @click="activeTab = t.key"
          >
            {{ t.label }}
          </button>
        </div>

        <!-- One shared timeline per tab — every request in a tab has the same status/progress -->
        <div v-if="filtered.length > 0" id="requests-status-timeline" class="requests-status-timeline">
          <template v-for="(step, i) in TIMELINE_STEPS" :key="step">
            <div class="timeline-step" :class="'is-' + stepState(activeTab, i)">
              <span class="timeline-icon">
                <CheckCircle2 v-if="stepState(activeTab, i) === 'done'" :size="18" />
                <span v-else-if="stepState(activeTab, i) === 'current'" class="timeline-play">
                  <Play :size="9" fill="currentColor" />
                </span>
                <PlayCircle v-else-if="i === 1" :size="18" />
                <CheckSquare v-else :size="18" />
              </span>
              <span class="timeline-label">{{ step }}</span>
            </div>
            <span
              v-if="i < TIMELINE_STEPS.length - 1"
              class="timeline-connector"
              :class="{ filled: stepState(activeTab, i + 1) !== 'pending' }"
            ></span>
          </template>
        </div>

        <div v-if="filtered.length === 0" id="requests-tab-empty" class="requests-tab-empty">
          <p class="requests-tab-empty-title">Žádné požadavky v tomto stavu</p>
          <p class="requests-tab-empty-text">Podívejte se do ostatních záložek.</p>
        </div>

        <div v-else id="requests-list" class="requests-list">
          <article
            v-for="r in filtered"
            :key="r.id"
            :id="'request-card-' + r.id"
            class="request-card"
            role="button"
            tabindex="0"
            @click="openDetail(r.id)"
            @keydown.enter="openDetail(r.id)"
            @keydown.space.prevent="openDetail(r.id)"
          >
            <div class="request-card-top">
              <span class="request-card-number">{{ requestNumber(r.id) }}</span>
              <span class="request-pill" :class="pillMeta(r.status).cls">{{ pillMeta(r.status).label }}</span>
            </div>

            <h3 class="request-card-title">{{ r.title }}</h3>
            <p class="request-card-date">Zadáno: {{ formatDate(r.createdAt) }}</p>

            <div v-if="r.newMessages > 0" class="request-card-footer">
              <div class="request-card-messages" :id="'request-messages-' + r.id">
                <MessageSquare :size="14" />
                <span>{{ newMessagesLabel(r.newMessages) }}</span>
              </div>
            </div>
          </article>
        </div>
      </template>
    </template>
  </div>
</template>

<style scoped>
.requests-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 48px 20px;
  color: var(--color-gray-500);
  font-size: 14px;
}

.requests-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}

.requests-title {
  font-size: var(--fs-2xl);
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.2;
}

.requests-add-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  border: none;
  background: var(--color-blue);
  color: var(--color-white);
  transition: var(--transition);
  flex-shrink: 0;
}
.requests-add-btn:hover {
  background: var(--color-blue-hover);
}

/* ═══ Empty state ═══ */
.requests-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 40px 8px 24px;
}
@media (min-width: 640px) {
  .requests-empty { padding: 64px 24px 40px; }
}

.requests-empty-art {
  width: 218px;
  height: auto;
  margin-bottom: 28px;
}

.requests-empty-title {
  font-size: 20px;
  font-weight: 700;
  color: var(--color-primary);
  margin-bottom: 10px;
}

.requests-empty-text {
  font-size: 15px;
  line-height: 1.55;
  color: var(--color-gray-500);
  max-width: 34ch;
  margin-bottom: 28px;
}

.requests-cta {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  max-width: 320px;
  padding: 14px 24px;
  border: none;
  border-radius: var(--radius-lg);
  background: var(--color-blue);
  color: var(--color-white);
  font-size: 15px;
  font-weight: 600;
  transition: var(--transition);
}
.requests-cta:hover {
  background: var(--color-blue-hover);
}

/* ═══ Tabs ═══ */
.requests-tabs {
  display: flex;
  gap: 26px;
  border-bottom: 1px solid var(--color-gray-200);
  margin-bottom: 18px;
}

.requests-tab {
  position: relative;
  padding: 10px 2px 12px;
  border: none;
  background: none;
  font-size: 14px;
  font-weight: 500;
  color: var(--color-gray-500);
  transition: color var(--transition);
}
.requests-tab:hover {
  color: var(--color-primary);
}
.requests-tab.active {
  color: var(--color-blue);
  font-weight: 600;
}
.requests-tab.active::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: -1px;
  height: 2px;
  border-radius: 2px;
  background: var(--color-blue);
}

.requests-tab-empty {
  padding: 40px 20px;
  text-align: center;
}
.requests-tab-empty-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 4px;
}
.requests-tab-empty-text {
  font-size: 13px;
  color: var(--color-gray-500);
}

/* ═══ Request cards ═══ */
.requests-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
  max-width: 720px;
}

.request-card {
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-xl);
  padding: 16px 18px 18px;
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  text-align: left;
  transition: var(--transition);
}
.request-card:hover {
  border-color: var(--color-blue-border);
  box-shadow: var(--shadow-md);
}
.request-card:focus-visible {
  outline: 2px solid var(--color-blue);
  outline-offset: 2px;
}

.request-card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}

.request-card-number {
  font-size: 12px;
  font-weight: 500;
  color: var(--color-gray-400);
  letter-spacing: 0.02em;
}

.request-pill {
  padding: 4px 10px;
  border-radius: var(--radius-sm);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  white-space: nowrap;
}
.pill-new      { background: var(--color-warning-light); color: var(--color-warning); }
.pill-progress { background: var(--color-blue-light); color: var(--color-blue); }
.pill-done     { background: var(--color-success-light); color: var(--color-success); }

.request-card-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-primary);
  line-height: 1.35;
  margin-bottom: 4px;
}

.request-card-date {
  font-size: 13px;
  color: var(--color-gray-500);
}

.request-card-footer {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--color-gray-100);
}

/* ═══ Status timeline — one shared strip per tab ═══ */
.requests-status-timeline {
  display: flex;
  align-items: flex-start;
  max-width: 720px;
  padding: 2px 4px;
  margin-bottom: 18px;
}

.timeline-step {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  flex-shrink: 0;
}
.timeline-step:first-child { align-items: flex-start; }
.timeline-step:last-child  { align-items: flex-end; }

.timeline-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 18px;
}
.timeline-step.is-done .timeline-icon    { color: var(--color-blue); }
.timeline-step.is-pending .timeline-icon { color: var(--color-gray-300); }

.timeline-play {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: var(--color-blue);
  color: var(--color-white);
  padding-left: 1px;
}

.timeline-label {
  font-size: 11px;
  font-weight: 500;
  color: var(--color-primary);
  line-height: 1.2;
  white-space: nowrap;
}
.timeline-step.is-current .timeline-label { color: var(--color-blue); font-weight: 600; }
.timeline-step.is-pending .timeline-label { color: var(--color-gray-400); }

.timeline-connector {
  flex: 1;
  height: 2px;
  margin: 8px 6px 0;
  border-radius: 2px;
  background: var(--color-gray-200);
  min-width: 18px;
}
.timeline-connector.filled {
  background: var(--color-blue);
}

/* ═══ New messages indicator ═══ */
.request-card-messages {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-primary);
}
.request-card-messages svg {
  color: var(--color-gray-500);
  flex-shrink: 0;
}
</style>
