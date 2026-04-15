<script setup>
import { computed, watch, onBeforeUnmount } from 'vue'
import { X, Download } from 'lucide-vue-next'
import { isImageUrl, isVideoUrl, isPdfUrl, downloadFile, extractFilename } from '../utils/fileUtils'

const props = defineProps({
  show: { type: Boolean, default: false },
  url: { type: String, default: '' },
  filename: { type: String, default: '' },
  mimeType: { type: String, default: '' },
})

const emit = defineEmits(['close'])

const displayName = computed(() => props.filename || extractFilename(props.url))

const fileType = computed(() => {
  const mime = props.mimeType
  const url = props.url
  if (isImageUrl(mime) || isImageUrl(url)) return 'image'
  if (isVideoUrl(mime) || isVideoUrl(url)) return 'video'
  if (isPdfUrl(mime) || isPdfUrl(url)) return 'pdf'
  return 'unknown'
})

function close() {
  emit('close')
}

function onBackdropClick(e) {
  if (e.target === e.currentTarget) close()
}

function onKeydown(e) {
  if (e.key === 'Escape') close()
}

function handleDownload() {
  downloadFile(props.url, displayName.value)
}

watch(() => props.show, (val) => {
  if (val) {
    document.addEventListener('keydown', onKeydown)
    document.body.style.overflow = 'hidden'
  } else {
    document.removeEventListener('keydown', onKeydown)
    document.body.style.overflow = ''
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <div v-if="show" id="file-preview-modal-backdrop" class="file-preview-backdrop" @click="onBackdropClick">
      <div id="file-preview-modal" class="file-preview-modal" role="dialog" aria-modal="true" aria-labelledby="file-preview-modal-filename">
        <div id="file-preview-modal-header" class="file-preview-header">
          <span id="file-preview-modal-filename" class="file-preview-filename" :title="displayName">{{ displayName }}</span>
          <div class="file-preview-actions">
            <button id="file-preview-modal-download" class="file-preview-btn" @click="handleDownload" title="Stáhnout" aria-label="Stáhnout">
              <Download :size="18" />
            </button>
            <button id="file-preview-modal-close" class="file-preview-btn" @click="close" title="Zavřít" aria-label="Zavřít">
              <X :size="18" />
            </button>
          </div>
        </div>

        <div id="file-preview-modal-content" class="file-preview-content">
          <img
            v-if="fileType === 'image'"
            id="file-preview-modal-image"
            :src="url"
            :alt="displayName"
            class="file-preview-image"
          />
          <video
            v-else-if="fileType === 'video'"
            id="file-preview-modal-video"
            :src="url"
            controls
            class="file-preview-video"
          />
          <iframe
            v-else-if="fileType === 'pdf'"
            id="file-preview-modal-pdf"
            :src="url"
            class="file-preview-pdf"
            :title="'Náhled: ' + displayName"
          />
          <div v-else id="file-preview-modal-unsupported" class="file-preview-unsupported">
            <p>Náhled není dostupný pro tento typ souboru.</p>
            <button class="btn btn-primary btn-sm" @click="handleDownload">
              <Download :size="16" /> Stáhnout soubor
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.file-preview-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.85);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: 20px;
}

.file-preview-modal {
  background: var(--color-white);
  border-radius: var(--radius-lg);
  width: 100%;
  max-width: 1100px;
  max-height: 92vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 8px 40px rgba(0, 0, 0, 0.4);
}

.file-preview-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid var(--color-gray-200);
  gap: 12px;
  flex-shrink: 0;
}

.file-preview-filename {
  font-size: 14px;
  font-weight: 500;
  color: var(--color-gray-800);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}

.file-preview-actions {
  display: flex;
  gap: 4px;
  flex-shrink: 0;
}

.file-preview-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: none;
  border-radius: var(--radius-md);
  background: transparent;
  color: var(--color-gray-600);
  cursor: pointer;
  transition: background var(--transition), color var(--transition);
}
.file-preview-btn:hover {
  background: var(--color-gray-100);
  color: var(--color-gray-900);
}

.file-preview-content {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: auto;
  min-height: 0;
  background: var(--color-gray-50);
}

.file-preview-image {
  max-width: 100%;
  max-height: calc(92vh - 60px);
  object-fit: contain;
  display: block;
}

.file-preview-video {
  max-width: 100%;
  max-height: calc(92vh - 60px);
  display: block;
}

.file-preview-pdf {
  width: 100%;
  height: calc(92vh - 60px);
  border: none;
}

.file-preview-unsupported {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  padding: 40px;
  color: var(--color-gray-500);
  font-size: 14px;
}
</style>
