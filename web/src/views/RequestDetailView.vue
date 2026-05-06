<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeft, Calendar, User, Clock,
  CheckCircle2, XCircle, Loader2, Trash2, Building2,
} from 'lucide-vue-next'
import { maintenanceRequestService, REQUEST_STATUSES, REQUEST_CATEGORIES } from '../api'
import RequestConversation from '../components/RequestConversation.vue'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const error = ref(null)
const request = ref(null)
const confirming = ref(false)
const rejecting = ref(false)
const cancelling = ref(false)
const showRejectForm = ref(false)
const rejectComment = ref('')
const rejectError = ref('')
const showCancelConfirm = ref(false)

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

const statusMeta = computed(() => REQUEST_STATUSES.find(s => s.key === request.value?.status) || { label: request.value?.status, badge: 'badge-gray' })
const categoryLabel = computed(() => {
  const c = REQUEST_CATEGORIES.find(x => x.key === request.value?.category)
  return c ? c.label : '—'
})
const canConfirm = computed(() => request.value?.status === 'resi_se')
const canCancel = computed(() => request.value?.status === 'prijato')

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' })
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

function openRejectForm() {
  showRejectForm.value = true
  rejectError.value = ''
  rejectComment.value = ''
}
function closeRejectForm() {
  showRejectForm.value = false
}

async function submitReject() {
  if (rejectComment.value.trim().length < 3) {
    rejectError.value = 'Uveďte prosím důvod (alespoň 3 znaky).'
    return
  }
  rejecting.value = true
  rejectError.value = ''
  try {
    const res = await maintenanceRequestService.reject(request.value.id, rejectComment.value.trim())
    if (!res.success) {
      rejectError.value = res.message || 'Nepodařilo se odeslat.'
      return
    }
    showRejectForm.value = false
    await load()
  } catch (e) {
    rejectError.value = e.response?.data?.message || e.message || 'Nepodařilo se odeslat.'
  } finally {
    rejecting.value = false
  }
}

async function cancelRequest() {
  cancelling.value = true
  try {
    await maintenanceRequestService.cancel(request.value.id)
    router.push('/zadosti')
  } catch (e) {
    error.value = e.response?.data?.message || e.message || 'Nepodařilo se zrušit.'
  } finally {
    cancelling.value = false
    showCancelConfirm.value = false
  }
}
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
        <span>Zpět na požadavky</span>
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
          <div class="meta-value">{{ request.createdBy || '—' }}</div>
        </div>
        <div class="meta-card" id="meta-company">
          <div class="meta-label"><Building2 :size="14" /> Protistrana</div>
          <div class="meta-value">
            {{ request.companyName || '—' }}
            <span v-if="request.companyIco" style="color:var(--color-gray-500); font-weight:400;"> · IČO {{ request.companyIco }}</span>
          </div>
        </div>
        <div class="meta-card" id="meta-category">
          <div class="meta-label">Kategorie</div>
          <div class="meta-value">{{ categoryLabel }}</div>
        </div>
      </div>

      <div id="request-detail-conversation-wrap" class="conversation-wrap">
        <RequestConversation :request="request" viewer-role="client" />
      </div>

      <!-- Cancel in Nový -->
      <div v-if="canCancel" id="request-detail-cancel-section" style="margin-top:20px;">
        <button v-if="!showCancelConfirm" id="request-detail-cancel-btn" class="btn btn-ghost btn-sm" @click="showCancelConfirm = true">
          <Trash2 :size="14" />
          <span>Zrušit požadavek</span>
        </button>
        <div v-else class="card" style="padding:16px; border:1px solid var(--color-danger-light);">
          <p style="margin:0 0 12px; font-size:14px;">Opravdu chcete tento požadavek zrušit?</p>
          <div style="display:flex; gap:8px;">
            <button id="request-detail-cancel-confirm" class="btn btn-danger btn-sm" :disabled="cancelling" @click="cancelRequest">
              <span>{{ cancelling ? 'Ruším...' : 'Ano, zrušit' }}</span>
            </button>
            <button class="btn btn-ghost btn-sm" @click="showCancelConfirm = false">Zpět</button>
          </div>
        </div>
      </div>

      <!-- Confirm / Reject -->
      <div v-if="canConfirm" id="request-detail-confirm-section" class="card confirm-card">
        <p class="confirm-prompt">Pokud je požadavek vyřešen, potvrďte jeho dokončení.</p>

        <div v-if="!showRejectForm" class="confirm-actions">
          <button id="request-detail-confirm-btn" class="btn btn-primary" :disabled="confirming" @click="confirmResolution">
            <CheckCircle2 :size="16" />
            <span>{{ confirming ? 'Potvrzuji...' : 'Potvrdit vyřešení' }}</span>
          </button>
          <button id="request-detail-reject-btn" class="btn btn-outline" @click="openRejectForm">
            <XCircle :size="16" />
            <span>Není vyřešeno</span>
          </button>
        </div>

        <div v-else id="request-detail-reject-form" class="reject-form">
          <label class="form-label" for="reject-comment">Důvod (povinné)</label>
          <textarea
            id="reject-comment"
            v-model="rejectComment"
            class="form-input"
            rows="4"
            placeholder="Popište prosím, co stále není v pořádku..."
          ></textarea>
          <div v-if="rejectError" class="field-error">{{ rejectError }}</div>
          <div style="display:flex; gap:8px; margin-top:12px;">
            <button id="request-detail-reject-submit" class="btn btn-primary" :disabled="rejecting" @click="submitReject">
              <span>{{ rejecting ? 'Odesílám...' : 'Odeslat' }}</span>
            </button>
            <button class="btn btn-ghost" @click="closeRejectForm">Zpět</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.meta-grid {
  display: grid;
  grid-template-columns: 1fr;
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

.conversation-wrap {
  margin-top: 24px;
  padding: 18px 12px;
  background: var(--color-gray-50);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
}
@media (min-width: 640px) {
  .conversation-wrap { padding: 22px 18px; }
}

.confirm-card {
  margin-top: 20px;
  padding: 20px;
  background: var(--color-light);
  border: 1px solid var(--color-mid);
}
.confirm-prompt {
  margin: 0 0 14px;
  font-size: 14px;
  color: var(--color-primary);
  font-weight: 500;
}
.confirm-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.reject-form .form-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-gray-700);
  display: block;
  margin-bottom: 6px;
}
.field-error {
  font-size: 12px;
  color: var(--color-danger);
  margin-top: 4px;
}

.spin { animation: spin 1.5s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

@media (min-width: 768px) {
  .meta-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
