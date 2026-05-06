<script setup>
import { computed, ref } from 'vue'
import {
  Download, FileText, Lock, Image as ImageIcon, Inbox, Sparkles, ArrowRight,
} from 'lucide-vue-next'
import FilePreviewModal from './FilePreviewModal.vue'
import { downloadFile } from '../utils/fileUtils'
import { REQUEST_STATUSES } from '../api'

const props = defineProps({
  request: { type: Object, required: true },
  viewerRole: { type: String, required: true, validator: v => ['client', 'admin'].includes(v) },
  showInternal: { type: Boolean, default: false },
})

const beforeAttachments = computed(() => props.request.attachments?.before || [])
const afterAttachments = computed(() => props.request.attachments?.after || [])
const showAfterBubble = computed(() =>
  afterAttachments.value.length > 0 &&
  ['resi_se', 'vyreseno'].includes(props.request.status)
)

function statusLabel(key) {
  return REQUEST_STATUSES.find(s => s.key === key)?.label || key
}

function isImage(att) {
  return (att.mimeType || '').startsWith('image/')
}

function initials(name) {
  if (!name) return '?'
  return name.trim().split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase()
}

function formatTime(d) {
  if (!d) return ''
  return new Date(d).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' })
}

function localYmd(d) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}
function formatDayLabel(d) {
  if (!d) return ''
  const date = new Date(d)
  const today = new Date()
  const yest = new Date(); yest.setDate(today.getDate() - 1)
  if (localYmd(date) === localYmd(today)) return 'Dnes'
  if (localYmd(date) === localYmd(yest)) return 'Včera'
  return date.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'long', year: 'numeric' })
}

const items = computed(() => {
  const out = []

  if ((props.request.description && props.request.description.trim()) || beforeAttachments.value.length) {
    out.push({
      kind: 'message',
      key: 'root',
      id: 'root',
      authorType: 'client',
      author: props.request.clientDisplayName || props.request.createdBy || 'Klient',
      message: props.request.description || '',
      createdAt: props.request.createdAt,
      isRoot: true,
      attachments: beforeAttachments.value,
      isInternal: false,
    })
  }

  for (const a of (props.request.activity || [])) {
    if (a.isInternal && !props.showInternal) continue
    if (a.statusChange || a.authorType === 'system') {
      out.push({
        kind: 'system',
        key: 'a-' + a.id,
        id: a.id,
        statusChange: a.statusChange,
        author: a.author,
        createdAt: a.createdAt,
      })
    } else {
      out.push({
        kind: 'message',
        key: 'a-' + a.id,
        id: a.id,
        authorType: a.authorType,
        author: a.author,
        message: a.message || '',
        createdAt: a.createdAt,
        isInternal: !!a.isInternal,
      })
    }
  }

  if (showAfterBubble.value) {
    out.push({
      kind: 'message',
      key: 'after',
      id: 'after',
      authorType: 'admin',
      author: 'Fajn Úklid',
      message: '',
      createdAt: props.request.completedAt || props.request.updatedAt || props.request.createdAt,
      isAfter: true,
      attachments: afterAttachments.value,
      isInternal: false,
    })
  }

  return out
})

const renderRows = computed(() => {
  const rows = []
  let lastDay = null
  for (const it of items.value) {
    const dayKey = it.createdAt ? localYmd(new Date(it.createdAt)) : null
    if (dayKey && dayKey !== lastDay) {
      rows.push({ kind: 'day', key: 'd-' + dayKey, label: formatDayLabel(it.createdAt) })
      lastDay = dayKey
    }
    rows.push(it)
  }
  return rows
})

function isOwn(item) {
  if (item.kind !== 'message') return false
  return item.authorType === props.viewerRole
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
  <section id="request-conversation" class="chat" aria-label="Konverzace">
    <template v-for="row in renderRows" :key="row.key">
      <div v-if="row.kind === 'day'" class="chat-day-wrap">
        <span class="chat-day">{{ row.label }}</span>
      </div>

      <div
        v-else-if="row.kind === 'system'"
        :id="'chat-system-' + row.id"
        class="chat-system-wrap"
      >
        <span class="chat-system">
          <ArrowRight v-if="row.statusChange" :size="11" />
          <template v-if="row.statusChange">{{ statusLabel(row.statusChange) }}</template>
          <template v-else-if="row.message">{{ row.message }}</template>
          <span class="chat-system-meta">· {{ formatTime(row.createdAt) }}</span>
        </span>
      </div>

      <div
        v-else
        :id="'chat-row-' + row.id"
        class="chat-row"
        :class="[isOwn(row) ? 'chat-row--right' : 'chat-row--left']"
      >
        <div
          class="avatar avatar-sm chat-avatar"
          :class="{ 'chat-avatar--admin': row.authorType === 'admin' }"
          :title="row.author"
        >
          {{ initials(row.author) }}
        </div>
        <div class="chat-bubble-wrap">
          <div class="chat-bubble-head">
            <span class="chat-author">{{ row.author }}</span>
            <span v-if="row.isRoot" class="chat-tag chat-tag--root">Žádost od klienta</span>
            <span v-else-if="row.isAfter" class="chat-tag chat-tag--after">
              <ImageIcon :size="10" /> Po vyřešení
            </span>
            <span v-else-if="row.isInternal" class="chat-tag chat-tag--internal">
              <Lock :size="10" /> Interní
            </span>
            <span v-else-if="row.authorType === 'admin'" class="chat-tag chat-tag--admin">Fajn Úklid</span>
            <span v-else-if="row.authorType === 'client'" class="chat-tag chat-tag--client">Klient</span>
            <span class="chat-time">{{ formatTime(row.createdAt) }}</span>
          </div>
          <div
            class="chat-bubble"
            :class="{
              'chat-bubble--own': isOwn(row) && !row.isInternal,
              'chat-bubble--other': !isOwn(row),
              'chat-bubble--internal': row.isInternal,
            }"
          >
            <p v-if="row.message" :id="'chat-message-' + row.id" class="chat-message">{{ row.message }}</p>
            <p v-else-if="row.isAfter" class="chat-message chat-message--muted">
              <Sparkles :size="13" /> Práce dokončena, výsledek najdete v přílohách níže.
            </p>

            <div v-if="row.attachments && row.attachments.length" class="attach-gallery attach-gallery--compact chat-attach">
              <div
                v-for="att in row.attachments"
                :key="att.id"
                :id="'chat-att-' + row.id + '-' + att.id"
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
                  :id="'chat-att-download-' + row.id + '-' + att.id"
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
        </div>
      </div>
    </template>

    <div v-if="renderRows.length === 0" id="chat-empty" class="chat-empty">
      <Inbox :size="20" />
      <span>Zatím žádná konverzace.</span>
    </div>

    <FilePreviewModal
      :show="previewModal.show"
      :url="previewModal.url"
      :filename="previewModal.filename"
      :mime-type="previewModal.mimeType"
      @close="closePreview"
    />
  </section>
</template>

<style scoped>
.chat {
  display: flex;
  flex-direction: column;
  gap: 14px;
  max-width: 760px;
  margin: 0 auto;
  padding: 4px 0 8px;
}

.chat-day-wrap {
  display: flex;
  justify-content: center;
  margin: 6px 0 -2px;
}
.chat-day {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-gray-500);
  background: var(--color-gray-100);
  padding: 3px 10px;
  border-radius: var(--radius-pill);
}

.chat-system-wrap {
  display: flex;
  justify-content: center;
  margin: -2px 0;
  opacity: 0.7;
}
.chat-system {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  font-weight: 500;
  color: var(--color-gray-500);
  background: transparent;
  padding: 2px 4px;
  border-radius: var(--radius-pill);
  letter-spacing: 0.02em;
}
.chat-system-meta { color: var(--color-gray-400); margin-left: 2px; }

.chat-row {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  max-width: 100%;
}
.chat-row--right {
  flex-direction: row-reverse;
}

.chat-avatar {
  background: var(--color-mid);
  align-self: flex-end;
  margin-bottom: 4px;
}
.chat-avatar--admin {
  background: var(--color-primary);
}

.chat-bubble-wrap {
  display: flex;
  flex-direction: column;
  min-width: 0;
  max-width: 92%;
  gap: 4px;
}
.chat-row--right .chat-bubble-wrap { align-items: flex-end; }
@media (min-width: 640px) {
  .chat-bubble-wrap { max-width: 80%; }
}

.chat-bubble-head {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 4px;
  flex-wrap: wrap;
}
.chat-row--right .chat-bubble-head { flex-direction: row-reverse; }
.chat-author {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-primary);
}
.chat-time {
  font-size: 10px;
  color: var(--color-gray-500);
}

.chat-tag {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 1px 7px;
  border-radius: var(--radius-pill);
  border: 1px solid transparent;
  line-height: 1.6;
}
.chat-tag--root {
  color: var(--color-primary);
  background: var(--color-light);
  border-color: var(--color-mid);
}
.chat-tag--admin {
  color: var(--color-primary);
  background: var(--color-light);
}
.chat-tag--client {
  color: var(--color-gray-700);
  background: var(--color-gray-100);
}
.chat-tag--internal {
  color: var(--color-warning);
  background: var(--color-warning-light);
  border-color: var(--color-warning);
}
.chat-tag--after {
  color: var(--color-success);
  background: var(--color-success-light);
  border-color: var(--color-success);
}

.chat-bubble {
  padding: 10px 14px;
  border-radius: 14px;
  font-size: 14px;
  line-height: 1.5;
  word-wrap: break-word;
  overflow-wrap: anywhere;
}
.chat-bubble--other {
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  color: var(--color-gray-800);
  border-bottom-left-radius: 4px;
}
.chat-bubble--own {
  background: var(--color-primary);
  color: var(--color-white);
  border-bottom-right-radius: 4px;
}
.chat-bubble--internal {
  background: var(--color-warning-light);
  border: 1px solid var(--color-warning);
  color: var(--color-gray-800);
  border-bottom-right-radius: 4px;
}

.chat-message {
  margin: 0;
  white-space: pre-wrap;
}
.chat-message--muted {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--color-gray-600);
  font-style: italic;
  font-size: 13px;
}

.chat-attach {
  margin-top: 8px;
}
.chat-bubble--own .chat-attach .attach-tile {
  border-color: rgba(255, 255, 255, 0.25);
  background: rgba(255, 255, 255, 0.08);
}
.chat-bubble--own .chat-attach .attach-tile-name {
  color: var(--color-white);
}
.chat-bubble--own .chat-attach .attach-tile-pdf {
  color: var(--color-white);
}
.chat-bubble--own .chat-attach .attach-tile-pdf:hover {
  background: rgba(255, 255, 255, 0.12);
}

.chat-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 28px;
  color: var(--color-gray-500);
  font-size: 13px;
  border: 1px dashed var(--color-gray-300);
  border-radius: var(--radius-lg);
  background: var(--color-gray-50);
}
</style>
