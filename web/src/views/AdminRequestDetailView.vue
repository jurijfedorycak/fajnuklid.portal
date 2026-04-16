<script setup>
import { ref, onMounted, computed, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Calendar, Clock, MessageSquare, Loader2, Trash2,
  Save, Send, Lock, ChevronRight, Inbox,
} from 'lucide-vue-next'
import { adminService, REQUEST_STATUSES, REQUEST_CATEGORIES } from '../api'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const error = ref(null)
const request = ref(null)

const savingStatusKey = ref(null)
const savingDueDate = ref(false)
const posting = ref(false)
const deleting = ref(false)
const showDeleteModal = ref(false)

const editDueDate = ref('')

const newComment = ref('')
const composerTab = ref('public')

const statusError = ref(null)
const dueDateError = ref(null)
const commentError = ref(null)

const toast = ref(null)
let toastTimer = null

function showToast(type, message) {
  toast.value = { type, message }
  if (toastTimer) clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toast.value = null }, 3000)
}

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true
  error.value = null
  try {
    const res = await adminService.getMaintenanceRequest(route.params.id)
    if (res.success) {
      const dueWasDirty = isDueDateDirty.value
      request.value = { ...res.data, activity: res.data.activity || [] }
      if (!dueWasDirty) editDueDate.value = res.data.dueDate || ''
    } else {
      error.value = res.message
    }
  } catch (e) {
    error.value = e.message
  } finally {
    if (!silent) loading.value = false
  }
}

onMounted(load)

const categoryLabel = computed(() => REQUEST_CATEGORIES.find(c => c.key === request.value?.category)?.label || request.value?.category)
const isDueDateDirty = computed(() => (editDueDate.value || '') !== (request.value?.dueDate || ''))

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' })
}
function formatDateTime(d) {
  if (!d) return ''
  return new Date(d).toLocaleString('cs-CZ', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function initials(name) {
  if (!name) return '?'
  return name.trim().split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase()
}

async function setStatus(key) {
  if (!request.value || key === request.value.status || savingStatusKey.value) return
  statusError.value = null
  savingStatusKey.value = key
  const previous = request.value.status
  request.value.status = key // optimistic
  try {
    await adminService.updateMaintenanceRequest(request.value.id, { status: key })
    showToast('success', 'Stav byl uložen')
  } catch (e) {
    request.value.status = previous
    statusError.value = e.message || 'Nepodařilo se uložit stav.'
  } finally {
    savingStatusKey.value = null
  }
}

async function saveDueDate() {
  if (!isDueDateDirty.value) return
  dueDateError.value = null
  savingDueDate.value = true
  try {
    await adminService.updateMaintenanceRequest(request.value.id, { dueDate: editDueDate.value || '' })
    request.value.dueDate = editDueDate.value || null
    showToast('success', 'Termín byl uložen')
  } catch (e) {
    dueDateError.value = e.message || 'Nepodařilo se uložit termín.'
  } finally {
    savingDueDate.value = false
  }
}

async function postComment() {
  const text = newComment.value.trim()
  if (!text || posting.value) return
  commentError.value = null
  posting.value = true
  const isInternal = composerTab.value === 'internal'
  try {
    await adminService.addMaintenanceRequestActivity(request.value.id, text, isInternal)
    newComment.value = ''
    showToast('success', isInternal ? 'Interní poznámka přidána' : 'Odpověď odeslána klientovi')
    await load({ silent: true })
    await nextTick()
  } catch (e) {
    commentError.value = e.message || 'Nepodařilo se odeslat komentář.'
  } finally {
    posting.value = false
  }
}

async function confirmDelete() {
  deleting.value = true
  try {
    await adminService.deleteMaintenanceRequest(request.value.id)
    showDeleteModal.value = false
    router.push('/admin/zadosti')
  } catch (e) {
    showToast('error', e.message || 'Nepodařilo se smazat žádost.')
    showDeleteModal.value = false
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div>
    <!-- Skeleton loading -->
    <div v-if="loading" id="admin-request-skeleton">
      <div class="skeleton" style="height:14px; width:240px; margin-bottom:18px;"></div>
      <div class="skeleton" style="height:32px; width:60%; margin-bottom:10px;"></div>
      <div class="skeleton" style="height:14px; width:40%; margin-bottom:24px;"></div>
      <div style="display:flex; gap:8px; margin-bottom:24px;">
        <div class="skeleton" style="height:32px; width:90px; border-radius:999px;"></div>
        <div class="skeleton" style="height:32px; width:110px; border-radius:999px;"></div>
        <div class="skeleton" style="height:32px; width:200px; border-radius:999px;"></div>
        <div class="skeleton" style="height:32px; width:130px; border-radius:999px;"></div>
      </div>
      <div class="skeleton" style="height:120px; width:100%; margin-bottom:14px; border-radius:12px;"></div>
      <div class="skeleton" style="height:80px; width:100%; margin-bottom:10px; border-radius:12px;"></div>
      <div class="skeleton" style="height:80px; width:100%; margin-bottom:24px; border-radius:12px;"></div>
      <div class="skeleton" style="height:140px; width:100%; border-radius:12px;"></div>
    </div>

    <div v-else-if="error" id="admin-request-error" class="alert alert-danger">{{ error }}</div>

    <template v-else-if="request">
      <!-- Breadcrumb -->
      <nav id="admin-request-breadcrumb" class="breadcrumb" aria-label="breadcrumb">
        <a id="admin-request-bc-admin" href="#" @click.prevent="router.push('/admin')">Admin</a>
        <ChevronRight :size="13" class="breadcrumb-sep" />
        <a id="admin-request-bc-list" href="#" @click.prevent="router.push('/admin/zadosti')">Žádosti</a>
        <ChevronRight :size="13" class="breadcrumb-sep" />
        <span id="admin-request-bc-current" class="breadcrumb-current">{{ request.title }}</span>
      </nav>

      <!-- Header -->
      <header id="admin-request-header" class="request-header">
        <div class="header-text">
          <h1 id="admin-request-title" class="request-title">{{ request.title }}</h1>
          <p id="admin-request-subtitle" class="request-subtitle">
            {{ categoryLabel }}
            <span class="dot">·</span>
            {{ request.clientDisplayName || '—' }}
            <span class="dot">·</span>
            Vytvořeno {{ formatDate(request.createdAt) }}
            <template v-if="request.locationValue">
              <span class="dot">·</span>{{ request.locationValue }}
            </template>
          </p>
        </div>
        <button id="admin-request-delete-btn" class="btn btn-ghost btn-sm delete-btn" @click="showDeleteModal = true">
          <Trash2 :size="14" />
          <span>Smazat</span>
        </button>
      </header>

      <!-- Status pills + due date -->
      <div id="admin-request-controls" class="controls-row">
        <div id="admin-request-status-block" class="control-block">
          <div class="control-eyebrow"><Clock :size="12" /> Stav</div>
          <div id="admin-status-pills" class="status-pills">
            <button
              v-for="s in REQUEST_STATUSES"
              :key="s.key"
              :id="'admin-status-pill-' + s.key"
              type="button"
              class="status-pill"
              :class="[s.badge, { active: request.status === s.key }]"
              :disabled="savingStatusKey !== null"
              @click="setStatus(s.key)"
            >
              <Loader2 v-if="savingStatusKey === s.key" :size="12" class="spin" />
              <span>{{ s.label }}</span>
            </button>
          </div>
          <div v-if="statusError" id="admin-status-error" class="field-error">{{ statusError }}</div>
        </div>

        <div id="admin-request-due-block" class="control-block due-block">
          <div class="control-eyebrow"><Calendar :size="12" /> Termín</div>
          <div class="due-row">
            <input
              id="admin-due-input"
              v-model="editDueDate"
              type="date"
              class="form-input due-input"
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
          <div v-if="dueDateError" id="admin-due-error" class="field-error">{{ dueDateError }}</div>
        </div>
      </div>

      <!-- Conversation -->
      <section id="admin-request-conversation" class="conversation">
        <h2 id="admin-request-conv-title" class="conv-title">
          <MessageSquare :size="16" />
          Konverzace ({{ request.activity.length + 1 }})
        </h2>

        <!-- Root: client's original request -->
        <article id="admin-conv-root" class="conv-item conv-root">
          <div class="conv-avatar avatar avatar-sm">{{ initials(request.clientDisplayName) }}</div>
          <div class="conv-body">
            <div class="conv-head">
              <span class="conv-author">{{ request.clientDisplayName || 'Klient' }}</span>
              <span class="conv-role-badge">Žádost od klienta</span>
              <span class="dot">·</span>
              <span class="conv-time">{{ formatDateTime(request.createdAt) }}</span>
            </div>
            <div id="admin-conv-root-message" class="conv-message conv-message-root">{{ request.description || '—' }}</div>
          </div>
        </article>

        <!-- Replies -->
        <article
          v-for="a in request.activity"
          :key="a.id"
          :id="'admin-activity-' + a.id"
          class="conv-item"
          :class="{ 'conv-internal': a.isInternal }"
        >
          <div :id="'admin-activity-avatar-' + a.id" class="conv-avatar avatar avatar-sm" :class="{ 'admin-avatar': a.authorType === 'admin' }">
            {{ initials(a.author) }}
          </div>
          <div class="conv-body">
            <div class="conv-head">
              <span :id="'admin-activity-author-' + a.id" class="conv-author">{{ a.author }}</span>
              <span v-if="a.isInternal" :id="'admin-activity-internal-badge-' + a.id" class="badge badge-warning internal-badge">
                <Lock :size="11" /> Interní
              </span>
              <span class="dot">·</span>
              <span :id="'admin-activity-time-' + a.id" class="conv-time">{{ formatDateTime(a.createdAt) }}</span>
            </div>
            <div :id="'admin-activity-message-' + a.id" class="conv-message">{{ a.message }}</div>
          </div>
        </article>

        <div v-if="request.activity.length === 0" id="admin-conv-empty" class="conv-empty">
          <Inbox :size="20" />
          <span>Buďte první, kdo klientovi odpoví.</span>
        </div>
      </section>

      <!-- Composer -->
      <section
        id="admin-comment-composer"
        class="composer"
        :class="{ 'composer-internal': composerTab === 'internal' }"
      >
        <div id="admin-composer-tabs" class="composer-tabs" role="tablist">
          <button
            id="admin-composer-tab-public"
            type="button"
            role="tab"
            class="composer-tab"
            :class="{ active: composerTab === 'public' }"
            @click="composerTab = 'public'"
          >
            <Send :size="13" />
            Odpověď klientovi
          </button>
          <button
            id="admin-composer-tab-internal"
            type="button"
            role="tab"
            class="composer-tab composer-tab-internal"
            :class="{ active: composerTab === 'internal' }"
            @click="composerTab = 'internal'"
          >
            <Lock :size="13" />
            Interní poznámka
          </button>
        </div>

        <textarea
          id="admin-comment-textarea"
          v-model="newComment"
          class="form-input composer-textarea"
          rows="4"
          :placeholder="composerTab === 'internal' ? 'Poznámka pouze pro tým…' : 'Napište odpověď klientovi…'"
          @keydown.ctrl.enter="postComment"
          @keydown.meta.enter="postComment"
        ></textarea>

        <div v-if="commentError" id="admin-comment-error" class="field-error">{{ commentError }}</div>

        <div class="composer-actions">
          <span id="admin-composer-hint" class="composer-hint">
            <template v-if="composerTab === 'internal'">
              <Lock :size="12" /> Jen tým Fajn Úklid · <kbd>Ctrl</kbd>+<kbd>Enter</kbd>
            </template>
            <template v-else>
              <Send :size="12" /> Klient dostane e-mail · <kbd>Ctrl</kbd>+<kbd>Enter</kbd>
            </template>
          </span>
          <button
            id="admin-comment-submit"
            class="btn btn-sm"
            :class="composerTab === 'internal' ? 'btn-warning' : 'btn-primary'"
            :disabled="!newComment.trim() || posting"
            @click="postComment"
          >
            <Loader2 v-if="posting" :size="14" class="spin" />
            <Send v-else :size="14" />
            <span>{{ composerTab === 'internal' ? 'Uložit poznámku' : 'Odeslat klientovi' }}</span>
          </button>
        </div>
      </section>

      <!-- Delete confirmation modal -->
      <div v-if="showDeleteModal" id="admin-delete-modal" class="modal-overlay" @click.self="showDeleteModal = false">
        <div id="admin-delete-modal-card" class="modal-card">
          <h3 id="admin-delete-modal-title" class="modal-title">Smazat žádost?</h3>
          <p id="admin-delete-modal-text" class="modal-text">
            Opravdu smazat žádost <strong>„{{ request.title }}“</strong>? Tato akce skryje žádost pro klienta i administrátora.
          </p>
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

      <!-- Toast -->
      <div v-if="toast" id="admin-request-toast" class="toast" :class="'toast-' + toast.type">
        {{ toast.message }}
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Breadcrumb */
.breadcrumb {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--color-gray-500);
  margin-bottom: 14px;
}
.breadcrumb a {
  color: var(--color-gray-500);
  text-decoration: none;
  transition: var(--transition);
}
.breadcrumb a:hover { color: var(--color-primary); }
.breadcrumb-sep { color: var(--color-gray-400); flex-shrink: 0; }
.breadcrumb-current {
  color: var(--color-primary);
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 220px;
}
@media (min-width: 768px) {
  .breadcrumb-current { max-width: 480px; }
}

/* Header */
.request-header {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
}
.header-text { max-width: 780px; min-width: 0; }
.request-title {
  font-size: 26px;
  font-weight: 600;
  color: var(--color-primary);
  margin: 0 0 6px;
  line-height: 1.25;
}
.request-subtitle {
  font-size: 13px;
  color: var(--color-gray-600);
  margin: 0;
  line-height: 1.5;
}
.dot { color: var(--color-gray-400); margin: 0 2px; }
.delete-btn { color: var(--color-danger); flex-shrink: 0; }
.delete-btn:hover { background: var(--color-danger-light); }

/* Controls row: status + due date */
.controls-row {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
  align-items: flex-start;
  padding: 18px 20px;
  background: var(--color-gray-50);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  margin-bottom: 28px;
}
.control-block { min-width: 0; }
.control-eyebrow {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 600;
  color: var(--color-gray-500);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 8px;
}

.status-pills {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border-radius: var(--radius-pill);
  font-size: 13px;
  font-weight: 500;
  border: 1.5px solid var(--color-gray-300);
  background: white;
  color: var(--color-gray-600);
  cursor: pointer;
  transition: var(--transition);
}
.status-pill:hover:not(:disabled):not(.active) {
  border-color: var(--color-mid);
  color: var(--color-primary);
}
.status-pill.active {
  border-color: currentColor;
  /* badge-* class supplies background + color */
}
.status-pill:disabled { cursor: default; opacity: 0.7; }

.due-block { justify-self: stretch; }
.due-row {
  display: flex;
  gap: 8px;
  align-items: center;
}
@media (min-width: 768px) {
  .due-block { justify-self: end; }
}
.due-input { flex: 1; min-width: 0; width: auto; }
@media (min-width: 768px) {
  .due-input { flex: 0 0 auto; width: 170px; }
}

.field-error {
  margin-top: 6px;
  font-size: 12px;
  color: var(--color-danger);
}

/* Conversation */
.conversation { margin-bottom: 20px; }
.conv-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 600;
  color: var(--color-primary);
  margin: 0 0 14px;
}

.conv-item {
  display: flex;
  gap: 12px;
  padding: 16px 18px;
  background: white;
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  margin-bottom: 10px;
}
.conv-item.conv-internal {
  background: var(--color-warning-light);
  border-color: var(--color-warning);
  border-left-width: 3px;
}
.conv-item.conv-root {
  background: var(--color-light);
  border: 1px solid var(--color-mid);
  border-left-width: 3px;
  padding: 18px 20px;
  margin-bottom: 18px;
}

.conv-avatar { background: var(--color-mid); }
.admin-avatar { background: var(--color-primary); }

.conv-body { flex: 1; min-width: 0; }
.conv-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
  flex-wrap: wrap;
}
.conv-author {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-primary);
}
.conv-role-badge {
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-mid);
  background: white;
  padding: 2px 8px;
  border-radius: var(--radius-pill);
  border: 1px solid var(--color-mid);
}
.conv-time {
  font-size: 11px;
  color: var(--color-gray-500);
}
.conv-message {
  font-size: 14px;
  color: var(--color-gray-700);
  line-height: 1.6;
  white-space: pre-wrap;
}
.conv-message-root {
  font-size: 15px;
  color: var(--color-gray-800);
}

.internal-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 10px;
  padding: 2px 8px;
}

.conv-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 24px;
  color: var(--color-gray-500);
  font-size: 13px;
  border: 1px dashed var(--color-gray-300);
  border-radius: var(--radius-lg);
  background: var(--color-gray-50);
}

/* Composer */
.composer {
  background: white;
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  padding: 16px 18px;
  transition: var(--transition);
}
.composer.composer-internal {
  background: var(--color-warning-light);
  border-color: var(--color-warning);
}

.composer-tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 12px;
  border-bottom: 1px solid var(--color-gray-200);
}
.composer.composer-internal .composer-tabs { border-bottom-color: var(--color-warning); }

.composer-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-gray-500);
  cursor: pointer;
  transition: var(--transition);
  margin-bottom: -1px;
}
.composer-tab:hover { color: var(--color-primary); }
.composer-tab.active {
  color: var(--color-primary);
  border-bottom-color: var(--color-primary);
}
.composer-tab-internal.active {
  color: var(--color-warning);
  border-bottom-color: var(--color-warning);
}

.composer-textarea {
  background: white;
  resize: vertical;
  min-height: 90px;
  width: 100%;
  display: block;
  box-sizing: border-box;
}
.composer.composer-internal .composer-textarea {
  background: white;
  border-color: var(--color-warning);
}

.composer-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 12px;
  gap: 12px;
  flex-wrap: wrap;
}
.composer-hint {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--color-gray-600);
}
.composer-hint kbd {
  font-family: inherit;
  font-size: 10px;
  padding: 1px 5px;
  background: var(--color-gray-100);
  border: 1px solid var(--color-gray-300);
  border-radius: 3px;
  color: var(--color-gray-700);
}

/* Modal */
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
  max-width: 460px;
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
.modal-text strong { color: var(--color-primary); font-weight: 600; }
.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.spin { animation: spin 1.5s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Mobile-first. Expand at md: */
@media (min-width: 768px) {
  .request-header { flex-direction: row; }
  .controls-row { grid-template-columns: 1fr auto; gap: 24px 32px; }
}
</style>
