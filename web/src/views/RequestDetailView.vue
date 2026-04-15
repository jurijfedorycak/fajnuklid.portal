<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeft, Calendar, User, Clock, MessageSquare, Download,
  CheckCircle2, XCircle, Loader2, Trash2, Building2, Paperclip, FileText, Image as ImageIcon
} from 'lucide-vue-next'
import { maintenanceRequestService, REQUEST_STATUSES, REQUEST_CATEGORIES } from '../api'
import FilePreviewModal from '../components/FilePreviewModal.vue'
import { downloadFile } from '../utils/fileUtils'

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
const canConfirm = computed(() => request.value?.status === 'ceka_na_potvrzeni')
const canCancel = computed(() => request.value?.status === 'prijato')

const beforeAttachments = computed(() => request.value?.attachments?.before || [])
const afterAttachments = computed(() => request.value?.attachments?.after || [])
const showAfterGallery = computed(() =>
  afterAttachments.value.length > 0 &&
  ['ceka_na_potvrzeni', 'vyreseno'].includes(request.value?.status)
)

const latestAdminMessage = computed(() => {
  const list = (request.value?.activity || []).filter(a => a.authorType === 'admin' && !a.isInternal && a.message)
  return list.length ? list[list.length - 1] : null
})

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

function isImage(att) {
  return (att.mimeType || '').startsWith('image/')
}

const previewModal = ref({ show: false, url: '', filename: '', mimeType: '' })
function openPreview(att) {
  previewModal.value = { show: true, url: att.url, filename: att.filename, mimeType: att.mimeType || '' }
}
function closePreview() {
  previewModal.value.show = false
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

      <div id="request-detail-description-section" style="margin-top:24px;">
        <div class="section-label">Popis</div>
        <div id="request-detail-description" class="card description-card">
          <p style="white-space:pre-wrap;">{{ request.description || '—' }}</p>
        </div>
      </div>

      <!-- Attachments: before -->
      <div v-if="beforeAttachments.length" id="request-detail-attachments" style="margin-top:24px;">
        <div class="section-label"><Paperclip :size="14" style="vertical-align:-2px;" /> Přílohy</div>
        <div class="attach-gallery">
          <div
            v-for="att in beforeAttachments"
            :key="att.id"
            :id="'att-before-' + att.id"
            class="attach-tile"
          >
            <template v-if="isImage(att)">
              <img
                :src="att.url" :alt="att.filename"
                class="attach-tile-clickable"
                role="button" tabindex="0"
                @click="openPreview(att)" @keydown.enter="openPreview(att)"
              />
              <span
                class="attach-tile-name"
                role="button" tabindex="0"
                @click="openPreview(att)" @keydown.enter="openPreview(att)"
              >{{ att.filename }}</span>
            </template>
            <div
              v-else class="attach-tile-pdf"
              role="button" tabindex="0"
              @click="openPreview(att)" @keydown.enter="openPreview(att)"
            >
              <FileText :size="28" />
              <span>{{ att.filename }}</span>
            </div>
            <button
              :id="'att-before-download-' + att.id"
              class="attach-tile-download"
              title="Stáhnout"
              aria-label="Stáhnout"
              @click.stop="downloadFile(att.url, att.filename)"
            >
              <Download :size="14" />
            </button>
          </div>
        </div>
      </div>

      <!-- Attachments: after (po vyřešení) -->
      <div v-if="showAfterGallery" id="request-detail-attachments-after" style="margin-top:24px;">
        <div class="section-label"><ImageIcon :size="14" style="vertical-align:-2px;" /> Přílohy – po vyřešení</div>
        <div class="attach-gallery">
          <div
            v-for="att in afterAttachments"
            :key="att.id"
            :id="'att-after-' + att.id"
            class="attach-tile"
          >
            <template v-if="isImage(att)">
              <img
                :src="att.url" :alt="att.filename"
                class="attach-tile-clickable"
                role="button" tabindex="0"
                @click="openPreview(att)" @keydown.enter="openPreview(att)"
              />
              <span
                class="attach-tile-name"
                role="button" tabindex="0"
                @click="openPreview(att)" @keydown.enter="openPreview(att)"
              >{{ att.filename }}</span>
            </template>
            <div
              v-else class="attach-tile-pdf"
              role="button" tabindex="0"
              @click="openPreview(att)" @keydown.enter="openPreview(att)"
            >
              <FileText :size="28" />
              <span>{{ att.filename }}</span>
            </div>
            <button
              :id="'att-after-download-' + att.id"
              class="attach-tile-download"
              title="Stáhnout"
              aria-label="Stáhnout"
              @click.stop="downloadFile(att.url, att.filename)"
            >
              <Download :size="14" />
            </button>
          </div>
        </div>
      </div>

      <FilePreviewModal
        :show="previewModal.show"
        :url="previewModal.url"
        :filename="previewModal.filename"
        :mime-type="previewModal.mimeType"
        @close="closePreview"
      />

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
        <div v-if="latestAdminMessage" id="request-detail-admin-comment" class="admin-comment">
          <div class="admin-comment-label">Vzkaz od Fajn Úklid</div>
          <p>{{ latestAdminMessage.message }}</p>
        </div>

        <p class="confirm-prompt">Práce je hotová — potvrďte prosím vyřešení požadavku.</p>

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

      <div id="request-detail-activity" style="margin-top:24px;">
        <div class="section-label">
          <MessageSquare :size="14" style="vertical-align:-2px;" />
          Historie ({{ request.activity.length }})
        </div>
        <div v-if="request.activity.length === 0" class="card" style="color:var(--color-gray-500); font-size:13px;">
          Zatím žádná aktivita.
        </div>
        <div v-else class="activity-list">
          <div v-for="a in request.activity" :key="a.id" :id="'activity-' + a.id" class="activity-item">
            <div class="avatar avatar-sm" :class="{'admin-avatar': a.authorType === 'admin'}">
              {{ (a.author || '?').charAt(0) }}
            </div>
            <div class="activity-body">
              <div class="activity-head">
                <span class="activity-author">{{ a.author }}</span>
                <span v-if="a.authorType === 'admin'" class="role-badge">Fajn Úklid</span>
                <span v-else-if="a.authorType === 'client'" class="role-badge role-client">Klient</span>
                <span class="activity-time">{{ formatDateTime(a.createdAt) }}</span>
              </div>
              <div v-if="a.message" class="activity-message">{{ a.message }}</div>
              <div v-if="a.statusChange" class="activity-status">→ {{ a.statusChange }}</div>
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

.attach-gallery {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 10px;
}
.attach-tile {
  position: relative;
  display: flex;
  flex-direction: column;
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-md);
  overflow: hidden;
  background: var(--color-gray-50);
  color: inherit;
}
.attach-tile img {
  width: 100%;
  aspect-ratio: 1;
  object-fit: cover;
  display: block;
}
.attach-tile-clickable {
  cursor: pointer;
  transition: opacity var(--transition);
}
.attach-tile-clickable:hover {
  opacity: 0.85;
}
.attach-tile-name {
  display: block;
  padding: 6px 8px;
  font-size: 11px;
  color: var(--color-accent);
  cursor: pointer;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.attach-tile-name:hover {
  text-decoration: underline;
}
.attach-tile-pdf {
  width: 100%;
  aspect-ratio: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px;
  color: var(--color-gray-700);
  font-size: 11px;
  text-align: center;
  word-break: break-word;
  cursor: pointer;
}
.attach-tile-pdf:hover {
  background: var(--color-gray-100);
}
.attach-tile-download {
  position: absolute;
  top: 6px;
  right: 6px;
  width: 28px;
  height: 28px;
  border-radius: var(--radius-sm);
  border: none;
  background: rgba(255, 255, 255, 0.85);
  color: var(--color-gray-600);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  opacity: 0;
  transition: opacity var(--transition), background var(--transition);
}
.attach-tile:hover .attach-tile-download,
.attach-tile:focus-within .attach-tile-download,
.attach-tile-download:focus {
  opacity: 1;
}
.attach-tile-download:hover {
  background: var(--color-white);
  color: var(--color-gray-900);
}

.confirm-card {
  margin-top: 20px;
  padding: 20px;
  background: var(--color-light);
  border: 1px solid var(--color-mid);
}
.admin-comment {
  background: var(--color-white);
  border-radius: var(--radius-md);
  padding: 12px 14px;
  margin-bottom: 14px;
  border: 1px solid var(--color-gray-200);
}
.admin-comment-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--color-gray-500);
  text-transform: uppercase;
  margin-bottom: 4px;
}
.admin-comment p {
  margin: 0;
  font-size: 14px;
  color: var(--color-gray-800);
  white-space: pre-wrap;
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
  flex-wrap: wrap;
}
.activity-author {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-primary);
}
.role-badge {
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  padding: 2px 6px;
  border-radius: 4px;
  background: var(--color-primary);
  color: var(--color-white);
}
.role-badge.role-client {
  background: var(--color-mid);
}
.activity-time {
  font-size: 11px;
  color: var(--color-gray-500);
}
.activity-message {
  font-size: 14px;
  color: var(--color-gray-700);
  white-space: pre-wrap;
}
.activity-status {
  font-size: 11px;
  color: var(--color-gray-500);
  margin-top: 4px;
}

.spin { animation: spin 1.5s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 700px) {
  .meta-grid { grid-template-columns: 1fr; }
}
</style>
