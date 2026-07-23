<script setup>
import { ref, onBeforeUnmount } from 'vue'
import { UploadCloud, X, FileText } from 'lucide-vue-next'
import { ATTACHMENT_LIMITS } from '../api'
import FilePreviewModal from './FilePreviewModal.vue'
import { formatFileSize as formatSize } from '../utils/fileUtils'

const files = defineModel({ type: Array, default: () => [] })

defineProps({
  idPrefix: { type: String, required: true },
  disabled: { type: Boolean, default: false },
})

const fileError = ref('')

function onFilesChosen(e) {
  fileError.value = ''
  const chosen = Array.from(e.target.files || [])
  for (const f of chosen) {
    if (files.value.length >= ATTACHMENT_LIMITS.maxFiles) {
      fileError.value = `Maximálně ${ATTACHMENT_LIMITS.maxFiles} příloh.`
      break
    }
    if (f.size > ATTACHMENT_LIMITS.maxBytes) {
      fileError.value = `Soubor ${f.name} je větší než 10 MB.`
      continue
    }
    if (!ATTACHMENT_LIMITS.acceptedMimes.includes(f.type)) {
      fileError.value = `Soubor ${f.name}: nepodporovaný typ. Povoleno: obrázky a PDF.`
      continue
    }
    // Same file picked twice would also duplicate the list :key
    if (files.value.some(x => x.name === f.name && x.size === f.size && x.lastModified === f.lastModified)) {
      continue
    }
    files.value.push(f)
  }
  e.target.value = ''
}

const objectUrls = new Map()
function getObjectUrl(file) {
  if (!objectUrls.has(file)) {
    objectUrls.set(file, URL.createObjectURL(file))
  }
  return objectUrls.get(file)
}

const previewModal = ref({ show: false, url: '', filename: '', mimeType: '' })
function openFilePreview(file) {
  previewModal.value = {
    show: true,
    url: getObjectUrl(file),
    filename: file.name,
    mimeType: file.type || '',
  }
}
function closePreview() {
  previewModal.value.show = false
}

function removeFile(index) {
  const file = files.value[index]
  if (objectUrls.has(file)) {
    URL.revokeObjectURL(objectUrls.get(file))
    objectUrls.delete(file)
  }
  files.value.splice(index, 1)
}

onBeforeUnmount(() => {
  objectUrls.forEach(url => URL.revokeObjectURL(url))
  objectUrls.clear()
})
</script>

<template>
  <div :id="idPrefix + '-attach'" class="ap-root">
    <label
      :id="idPrefix + '-attach-trigger'"
      class="ap-dropzone"
      :class="{ 'ap-dropzone--disabled': disabled }"
      :for="idPrefix + '-attach-input'"
    >
      <span class="ap-dropzone-icon">
        <UploadCloud :size="18" />
      </span>
      <span class="ap-dropzone-title">Přidat soubor</span>
      <span class="ap-dropzone-hint">Max {{ ATTACHMENT_LIMITS.maxFiles }} souborů, 10 MB/soubor</span>
    </label>
    <input
      :id="idPrefix + '-attach-input'"
      type="file"
      multiple
      :accept="ATTACHMENT_LIMITS.acceptAttr"
      :disabled="disabled"
      style="display:none;"
      @change="onFilesChosen"
    />
    <div v-if="fileError" :id="idPrefix + '-attach-error'" class="field-error">{{ fileError }}</div>
    <ul v-if="files.length" :id="idPrefix + '-attach-list'" class="ap-attach-list">
      <li
        v-for="(f, i) in files"
        :id="idPrefix + '-attach-item-' + i"
        :key="f.name + f.size + f.lastModified"
        class="ap-attach-item"
      >
        <img
          v-if="f.type.startsWith('image/')"
          :id="idPrefix + '-attach-thumb-' + i"
          :src="getObjectUrl(f)"
          :alt="f.name"
          class="ap-attach-thumb clickable"
          @click="openFilePreview(f)"
        />
        <template v-else>
          <FileText :size="16" />
        </template>
        <span class="ap-attach-name file-link" @click="openFilePreview(f)">{{ f.name }}</span>
        <span class="ap-attach-size">{{ formatSize(f.size) }}</span>
        <button
          :id="idPrefix + '-attach-remove-' + i"
          type="button"
          class="ap-attach-remove"
          :disabled="disabled"
          :aria-label="'Odebrat ' + f.name"
          @click="removeFile(i)"
        >
          <X :size="14" />
        </button>
      </li>
    </ul>

    <FilePreviewModal
      :show="previewModal.show"
      :url="previewModal.url"
      :filename="previewModal.filename"
      :mime-type="previewModal.mimeType"
      @close="closePreview"
    />
  </div>
</template>

<style scoped>
.ap-dropzone {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
  padding: 26px 16px;
  border: 1.5px dashed var(--color-gray-300);
  border-radius: var(--radius-xl);
  background: var(--color-white);
  cursor: pointer;
  text-align: center;
  transition: var(--transition);
}
.ap-dropzone:hover {
  border-color: var(--color-blue);
  background: var(--color-blue-light);
}
.ap-dropzone--disabled {
  opacity: 0.6;
  pointer-events: none;
}

.ap-dropzone-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--color-blue-light);
  color: var(--color-blue);
  margin-bottom: 2px;
}

.ap-dropzone-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-blue);
}

.ap-dropzone-hint {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-gray-400);
}

.ap-attach-list {
  list-style: none;
  padding: 0;
  margin: 12px 0 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.ap-attach-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  font-size: 13px;
  color: var(--color-gray-700);
}
.ap-attach-thumb {
  width: 32px;
  height: 32px;
  object-fit: cover;
  border-radius: 6px;
  border: 1px solid var(--color-gray-200);
  flex-shrink: 0;
}
.ap-attach-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ap-attach-size {
  font-size: 11px;
  color: var(--color-gray-500);
}
.ap-attach-remove {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: none;
  background: transparent;
  color: var(--color-gray-500);
  cursor: pointer;
}
.ap-attach-remove:hover {
  background: var(--color-danger-light);
  color: var(--color-danger);
}
.ap-attach-remove:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.field-error {
  font-size: 12px;
  color: var(--color-danger);
  margin-top: 6px;
}
</style>
