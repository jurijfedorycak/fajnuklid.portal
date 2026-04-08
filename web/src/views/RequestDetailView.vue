<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Calendar, User, Clock, MessageSquare, CheckCircle2, Loader2 } from 'lucide-vue-next'
import { maintenanceRequestService, REQUEST_STATUSES } from '../api'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const error = ref(null)
const request = ref(null)
const confirming = ref(false)
const showFullDescription = ref(false)

async function load() {
  loading.value = true
  try {
    const res = await maintenanceRequestService.get(route.params.id)
    if (res.success) request.value = res.data
    else error.value = res.message
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

const statusMeta = computed(() => REQUEST_STATUSES.find(s => s.key === request.value?.status) || {})
const canConfirm = computed(() => request.value?.status === 'ceka_na_potvrzeni')

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' })
}
function formatDateTime(d) {
  if (!d) return ''
  return new Date(d).toLocaleString('cs-CZ', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

async function confirmResolution() {
  confirming.value = true
  try {
    await maintenanceRequestService.confirm(request.value.id)
    await load()
  } finally {
    confirming.value = false
  }
}

const descriptionPreview = computed(() => {
  if (!request.value?.description) return ''
  const d = request.value.description
  if (showFullDescription.value || d.length <= 220) return d
  return d.slice(0, 220) + '…'
})
</script>

<template>
  <div>
    <div v-if="loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
    </div>

    <div v-else-if="error" class="alert alert-danger">{{ error }}</div>

    <template v-else-if="request">
      <button id="request-detail-back" class="btn btn-ghost" style="margin-bottom:12px;" @click="router.push('/zadosti')">
        <ArrowLeft :size="16" />
        <span>Zpět na žádosti</span>
      </button>

      <div id="request-detail-header" class="page-header" style="align-items:flex-start;">
        <h1 id="request-detail-title" class="page-title" style="max-width:780px;">{{ request.title }}</h1>
        <span class="badge" :class="statusMeta.badge" id="request-detail-status">{{ statusMeta.label }}</span>
      </div>

      <div id="request-detail-meta" class="meta-grid">
        <div class="meta-card" id="meta-status">
          <div class="meta-label"><Clock :size="14" /> Status</div>
          <div class="meta-value">{{ statusMeta.label }}</div>
        </div>
        <div class="meta-card" id="meta-created">
          <div class="meta-label"><Calendar :size="14" /> Vytvořeno</div>
          <div class="meta-value">{{ formatDate(request.createdAt) }}</div>
        </div>
        <div class="meta-card" id="meta-author">
          <div class="meta-label"><User :size="14" /> Zadal</div>
          <div class="meta-value">{{ request.createdBy }}</div>
        </div>
        <div class="meta-card" id="meta-due">
          <div class="meta-label"><Calendar :size="14" /> Termín</div>
          <div class="meta-value">{{ formatDate(request.dueDate) }}</div>
        </div>
      </div>

      <div id="request-detail-description-section" style="margin-top:24px;">
        <div class="section-label">Popis</div>
        <div id="request-detail-description" class="card description-card">
          <p style="white-space:pre-wrap;">{{ descriptionPreview || '—' }}</p>
          <button
            v-if="request.description && request.description.length > 220"
            id="request-detail-description-toggle"
            class="btn btn-ghost btn-sm"
            @click="showFullDescription = !showFullDescription"
          >
            {{ showFullDescription ? 'Skrýt' : 'Zobrazit celý popis' }}
          </button>
        </div>
      </div>

      <div v-if="canConfirm" id="request-detail-confirm-section" class="alert alert-info" style="margin-top:20px; align-items:center; justify-content:space-between;">
        <span>Práce je hotová — potvrďte prosím vyřešení žádosti.</span>
        <button id="request-detail-confirm-btn" class="btn btn-primary btn-sm" :disabled="confirming" @click="confirmResolution">
          <CheckCircle2 :size="16" />
          <span>{{ confirming ? 'Potvrzuji...' : 'Potvrdit vyřešení' }}</span>
        </button>
      </div>

      <div id="request-detail-activity" style="margin-top:24px;">
        <div class="section-label">
          <MessageSquare :size="14" style="vertical-align:-2px;" />
          Aktivita ({{ request.activity.length }})
        </div>
        <div v-if="request.activity.length === 0" class="card" style="color:var(--color-gray-500); font-size:13px;">
          Zatím žádná aktivita.
        </div>
        <div v-else class="activity-list">
          <div v-for="a in request.activity" :key="a.id" :id="'activity-' + a.id" class="activity-item">
            <div class="avatar avatar-sm" :class="{'admin-avatar': a.authorType === 'admin'}">
              {{ a.author.charAt(0) }}
            </div>
            <div class="activity-body">
              <div class="activity-head">
                <span class="activity-author">{{ a.author }}</span>
                <span class="activity-time">{{ formatDateTime(a.createdAt) }}</span>
              </div>
              <div class="activity-message">{{ a.message }}</div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.meta-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}

.meta-card {
  background: var(--color-gray-50);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  padding: 16px 18px;
}

.meta-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 600;
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 6px;
}

.meta-value {
  font-size: 15px;
  font-weight: 500;
  color: var(--color-primary);
}

.section-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 8px;
}

.description-card {
  font-size: 14px;
  color: var(--color-gray-800);
  line-height: 1.6;
}

.activity-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.activity-item {
  display: flex;
  gap: 12px;
  padding: 14px 16px;
  background: var(--color-gray-50);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
}

.admin-avatar {
  background: var(--color-primary);
}

.activity-body { flex: 1; min-width: 0; }
.activity-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 4px;
}
.activity-author {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-primary);
}
.activity-time {
  font-size: 11px;
  color: var(--color-gray-500);
}
.activity-message {
  font-size: 14px;
  color: var(--color-gray-700);
}

.spin { animation: spin 1.5s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 700px) {
  .meta-grid { grid-template-columns: 1fr; }
}
</style>
