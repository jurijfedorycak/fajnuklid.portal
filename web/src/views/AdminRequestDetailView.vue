<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeft, Calendar, User, Clock, MessageSquare, Loader2, Trash2,
  Save, Send, Lock,
} from 'lucide-vue-next'
import { adminService, REQUEST_STATUSES, REQUEST_CATEGORIES } from '../api'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const error = ref(null)
const actionError = ref(null)
const request = ref(null)
const savingStatus = ref(false)
const savingDueDate = ref(false)
const posting = ref(false)
const deleting = ref(false)
const showDeleteModal = ref(false)

const editStatus = ref('')
const editDueDate = ref('')

const newComment = ref('')
const newCommentInternal = ref(false)

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await adminService.getMaintenanceRequest(route.params.id)
    if (res.success) {
      request.value = res.data
      editStatus.value = res.data.status
      editDueDate.value = res.data.dueDate || ''
    } else {
      error.value = res.message
    }
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

const statusMeta = computed(() => REQUEST_STATUSES.find(s => s.key === request.value?.status) || {})
const categoryLabel = computed(() => REQUEST_CATEGORIES.find(c => c.key === request.value?.category)?.label || request.value?.category)
const isStatusDirty = computed(() => editStatus.value && editStatus.value !== request.value?.status)
const isDueDateDirty = computed(() => (editDueDate.value || '') !== (request.value?.dueDate || ''))

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' })
}
function formatDateTime(d) {
  if (!d) return ''
  return new Date(d).toLocaleString('cs-CZ', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

async function saveStatus() {
  if (!isStatusDirty.value) return
  actionError.value = null
  savingStatus.value = true
  try {
    await adminService.updateMaintenanceRequest(request.value.id, { status: editStatus.value })
    await load()
  } catch (e) {
    actionError.value = e.message || 'Nepodařilo se uložit stav.'
  } finally {
    savingStatus.value = false
  }
}

async function saveDueDate() {
  if (!isDueDateDirty.value) return
  actionError.value = null
  savingDueDate.value = true
  try {
    await adminService.updateMaintenanceRequest(request.value.id, { dueDate: editDueDate.value || '' })
    await load()
  } catch (e) {
    actionError.value = e.message || 'Nepodařilo se uložit termín.'
  } finally {
    savingDueDate.value = false
  }
}

async function postComment() {
  const text = newComment.value.trim()
  if (!text || posting.value) return
  actionError.value = null
  posting.value = true
  try {
    await adminService.addMaintenanceRequestActivity(request.value.id, text, newCommentInternal.value)
    newComment.value = ''
    newCommentInternal.value = false
    await load()
  } catch (e) {
    actionError.value = e.message || 'Nepodařilo se odeslat komentář.'
  } finally {
    posting.value = false
  }
}

async function confirmDelete() {
  actionError.value = null
  deleting.value = true
  try {
    await adminService.deleteMaintenanceRequest(request.value.id)
    showDeleteModal.value = false
    router.push('/admin/zadosti')
  } catch (e) {
    actionError.value = e.message || 'Nepodařilo se smazat žádost.'
    showDeleteModal.value = false
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div>
    <div v-if="loading" id="admin-request-loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
    </div>

    <div v-else-if="error" id="admin-request-error" class="alert alert-danger">{{ error }}</div>

    <template v-else-if="request">
      <button id="admin-request-back" class="btn btn-ghost" style="margin-bottom:12px;" @click="router.push('/admin/zadosti')">
        <ArrowLeft :size="16" />
        <span>Zpět na žádosti</span>
      </button>

      <div v-if="actionError" id="admin-request-action-error" class="alert alert-danger" style="margin-bottom:16px;">
        {{ actionError }}
      </div>

      <div id="admin-request-header" class="page-header" style="align-items:flex-start;">
        <div style="max-width:780px;">
          <h1 id="admin-request-title" class="page-title">{{ request.title }}</h1>
          <p class="page-subtitle">
            <span class="badge" :class="statusMeta.badge">{{ statusMeta.label }}</span>
            · {{ categoryLabel }} · {{ request.locationValue || '—' }}
          </p>
        </div>
        <button id="admin-request-delete-btn" class="btn btn-danger btn-sm" @click="showDeleteModal = true">
          <Trash2 :size="14" />
          <span>Smazat</span>
        </button>
      </div>

      <!-- Meta cards (editable) -->
      <div id="admin-request-meta" class="meta-grid">
        <div class="meta-card" id="admin-meta-status">
          <div class="meta-label"><Clock :size="14" /> Status</div>
          <div class="meta-edit-row">
            <select id="admin-status-select" v-model="editStatus" class="form-input meta-input">
              <option v-for="s in REQUEST_STATUSES" :key="s.key" :value="s.key">{{ s.label }}</option>
            </select>
            <button
              id="admin-status-save"
              class="btn btn-primary btn-sm"
              :disabled="!isStatusDirty || savingStatus"
              @click="saveStatus"
            >
              <Loader2 v-if="savingStatus" :size="14" class="spin" />
              <Save v-else :size="14" />
              <span>Uložit</span>
            </button>
          </div>
        </div>

        <div class="meta-card" id="admin-meta-created">
          <div class="meta-label"><Calendar :size="14" /> Vytvořeno</div>
          <div class="meta-value">{{ formatDate(request.createdAt) }}</div>
        </div>

        <div class="meta-card" id="admin-meta-client">
          <div class="meta-label"><User :size="14" /> Klient</div>
          <div class="meta-value">{{ request.clientDisplayName || '—' }}</div>
        </div>

        <div class="meta-card" id="admin-meta-due">
          <div class="meta-label"><Calendar :size="14" /> Termín</div>
          <div class="meta-edit-row">
            <input
              id="admin-due-input"
              v-model="editDueDate"
              type="date"
              class="form-input meta-input"
            />
            <button
              id="admin-due-save"
              class="btn btn-primary btn-sm"
              :disabled="!isDueDateDirty || savingDueDate"
              @click="saveDueDate"
            >
              <Loader2 v-if="savingDueDate" :size="14" class="spin" />
              <Save v-else :size="14" />
              <span>Uložit</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Description -->
      <div id="admin-request-description-section" style="margin-top:24px;">
        <div id="admin-request-description-label" class="section-label">Popis (zadal klient)</div>
        <div id="admin-request-description" class="card description-card">
          <p id="admin-request-description-text" style="white-space:pre-wrap;">{{ request.description || '—' }}</p>
        </div>
      </div>

      <!-- Activity timeline -->
      <div id="admin-request-activity" style="margin-top:24px;">
        <div id="admin-request-activity-label" class="section-label">
          <MessageSquare :size="14" style="vertical-align:-2px;" />
          Aktivita ({{ request.activity.length }})
        </div>
        <div v-if="request.activity.length === 0" id="admin-request-activity-empty" class="card" style="color:var(--color-gray-500); font-size:13px;">
          Zatím žádná aktivita.
        </div>
        <div v-else id="admin-request-activity-list" class="activity-list">
          <div
            v-for="a in request.activity"
            :key="a.id"
            :id="'admin-activity-' + a.id"
            class="activity-item"
            :class="{ 'activity-internal': a.isInternal }"
          >
            <div :id="'admin-activity-avatar-' + a.id" class="avatar avatar-sm" :class="{'admin-avatar': a.authorType === 'admin'}">
              {{ (a.author || '?').charAt(0) }}
            </div>
            <div :id="'admin-activity-body-' + a.id" class="activity-body">
              <div :id="'admin-activity-head-' + a.id" class="activity-head">
                <span :id="'admin-activity-author-' + a.id" class="activity-author">{{ a.author }}</span>
                <span v-if="a.isInternal" :id="'admin-activity-internal-badge-' + a.id" class="badge badge-warning internal-badge">
                  <Lock :size="11" /> Interní
                </span>
                <span :id="'admin-activity-time-' + a.id" class="activity-time">{{ formatDateTime(a.createdAt) }}</span>
              </div>
              <div :id="'admin-activity-message-' + a.id" class="activity-message">{{ a.message }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Comment composer -->
      <div id="admin-comment-composer" class="card" style="margin-top:16px;">
        <div class="section-label" style="margin-bottom:8px;">Přidat komentář</div>
        <textarea
          id="admin-comment-textarea"
          v-model="newComment"
          class="form-input"
          rows="3"
          placeholder="Napište komentář..."
        ></textarea>
        <div class="composer-actions">
          <label class="internal-toggle" for="admin-comment-internal">
            <input
              id="admin-comment-internal"
              v-model="newCommentInternal"
              type="checkbox"
            />
            <Lock :size="13" />
            <span>Pouze interní (klient neuvidí)</span>
          </label>
          <button
            id="admin-comment-submit"
            class="btn btn-primary btn-sm"
            :disabled="!newComment.trim() || posting"
            @click="postComment"
          >
            <Loader2 v-if="posting" :size="14" class="spin" />
            <Send v-else :size="14" />
            <span>Odeslat</span>
          </button>
        </div>
      </div>

      <!-- Delete confirmation modal -->
      <div v-if="showDeleteModal" id="admin-delete-modal" class="modal-overlay" @click.self="showDeleteModal = false">
        <div id="admin-delete-modal-card" class="modal-card">
          <h3 id="admin-delete-modal-title" class="modal-title">Smazat žádost?</h3>
          <p id="admin-delete-modal-text" class="modal-text">Tato akce skryje žádost pro klienta i administrátora. Pokračovat?</p>
          <div id="admin-delete-modal-actions" class="modal-actions">
            <button id="admin-delete-cancel" class="btn btn-outline" @click="showDeleteModal = false">Zrušit</button>
            <button id="admin-delete-confirm" class="btn btn-danger" :disabled="deleting" @click="confirmDelete">
              <Loader2 v-if="deleting" :size="14" class="spin" />
              <Trash2 v-else :size="14" />
              <span>{{ deleting ? 'Mažu...' : 'Smazat' }}</span>
            </button>
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

.meta-edit-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.meta-input {
  flex: 1;
  min-width: 0;
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

.activity-item.activity-internal {
  background: var(--color-warning-light);
  border-color: var(--color-warning);
  border-left-width: 3px;
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
  flex-wrap: wrap;
}
.activity-author {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-primary);
}
.activity-time {
  font-size: 11px;
  color: var(--color-gray-500);
  margin-left: auto;
}
.activity-message {
  font-size: 14px;
  color: var(--color-gray-700);
  white-space: pre-wrap;
}

.internal-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 10px;
  padding: 2px 8px;
}

.composer-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 12px;
  gap: 12px;
  flex-wrap: wrap;
}

.internal-toggle {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--color-gray-700);
  cursor: pointer;
  user-select: none;
}

.internal-toggle input[type="checkbox"] {
  width: 16px;
  height: 16px;
  cursor: pointer;
}

.modal-overlay {
  position: fixed;
  inset: 0;
  background: var(--color-overlay);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 20px;
}

.modal-card {
  background: var(--color-white);
  border-radius: var(--radius-lg);
  padding: 24px;
  max-width: 420px;
  width: 100%;
  box-shadow: var(--shadow-lg);
}

.modal-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 8px;
}

.modal-text {
  font-size: 14px;
  color: var(--color-gray-600);
  margin-bottom: 20px;
  line-height: 1.5;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.spin { animation: spin 1.5s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 700px) {
  .meta-grid { grid-template-columns: 1fr; }
}
</style>
