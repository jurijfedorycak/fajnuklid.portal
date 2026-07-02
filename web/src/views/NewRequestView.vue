<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ChevronLeft, AlertCircle, Sparkles, HelpCircle, Loader2, UploadCloud, X, FileText } from 'lucide-vue-next'
import { maintenanceRequestService, REQUEST_CATEGORIES, ATTACHMENT_LIMITS } from '../api'
import FilePreviewModal from '../components/FilePreviewModal.vue'
import { formatFileSize as formatSize } from '../utils/fileUtils'

const router = useRouter()
const route = useRoute()

// Design uses compact uppercase category chips with their own icon set
const CATEGORY_CARDS = {
  reklamace: { label: 'Reklamace', icon: AlertCircle },
  mimoradna_prace: { label: 'Mimořádná', icon: Sparkles },
  jine: { label: 'Jiné', icon: HelpCircle },
}

const title = ref('')
const description = ref('')
const category = ref(null)
const selectedCompanyId = ref(null)
const submitting = ref(false)
const loadingOptions = ref(true)
const errors = ref({})

const companies = ref([])
const files = ref([])
const fileError = ref('')

onMounted(async () => {
  // Preselect a category when arrived via a deep link (e.g. the dashboard review
  // block routes a low rating here with ?category=reklamace).
  const queryCategory = route.query.category
  if (typeof queryCategory === 'string' && REQUEST_CATEGORIES.some(c => c.key === queryCategory)) {
    category.value = queryCategory
  }

  try {
    const res = await maintenanceRequestService.getFormOptions()
    if (res.success) {
      companies.value = res.data.companies || []
      if (companies.value.length === 1) {
        selectedCompanyId.value = companies.value[0].id
      }
    }
  } finally {
    loadingOptions.value = false
  }
})

const showCompanyPicker = computed(() => companies.value.length > 1)

function selectCategory(key) {
  category.value = category.value === key ? null : key
}

function selectCompany(id) {
  selectedCompanyId.value = id
}

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

// File preview
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

const isValid = computed(() =>
  title.value.trim() &&
  description.value.trim() &&
  selectedCompanyId.value
)

async function submit() {
  errors.value = {}
  if (!title.value.trim()) errors.value.title = 'Zadejte název požadavku.'
  if (!description.value.trim()) errors.value.description = 'Vyplňte podrobný popis.'
  if (!selectedCompanyId.value) errors.value.companyId = 'Vyberte protistranu.'
  if (Object.keys(errors.value).length) return

  submitting.value = true
  try {
    const res = await maintenanceRequestService.create({
      title: title.value.trim(),
      description: description.value.trim(),
      category: category.value,
      companyId: selectedCompanyId.value,
    })
    if (!res.success) {
      errors.value = res.errors || { _: res.message || 'Nepodařilo se vytvořit požadavek.' }
      submitting.value = false
      return
    }
    const newId = res.data.id

    for (const f of files.value) {
      try {
        await maintenanceRequestService.uploadAttachment(newId, f)
      } catch (e) {
        // continue uploading the rest
        console.error('Attachment upload failed', e)
      }
    }

    router.push(`/zadosti/vytvoreno/${newId}`)
  } catch (e) {
    errors.value = { _: e.response?.data?.message || e.message || 'Nepodařilo se vytvořit požadavek.' }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div id="new-request-page" class="nr-page">
    <div id="new-request-header" class="nr-head">
      <button id="new-request-back" class="nr-back" aria-label="Zpět na požadavky" @click="router.push('/zadosti')">
        <ChevronLeft :size="20" />
      </button>
      <h1 id="new-request-title" class="nr-title">Nový požadavek</h1>
    </div>
    <p id="new-request-subtitle" class="nr-subtitle">Řekněte nám, co se stalo – co nejdříve se vám ozveme.</p>

    <div id="new-request-form" class="nr-form">
      <!-- Protistrana picker -->
      <div v-if="showCompanyPicker" class="nr-group">
        <label class="nr-label">Protistrana (IČO) <span class="nr-required">*</span></label>
        <div v-if="loadingOptions" class="nr-options-loading">
          <Loader2 :size="16" class="spin" />
          <span>Načítám…</span>
        </div>
        <div v-else id="new-request-companies" class="chip-group">
          <button
            v-for="c in companies"
            :key="c.id"
            type="button"
            :id="'company-' + c.id"
            class="chip"
            :class="{ active: selectedCompanyId === c.id }"
            @click="selectCompany(c.id)"
          >
            {{ c.name }}<span v-if="c.ico" style="opacity:.7; margin-left:6px;">· IČO {{ c.ico }}</span>
          </button>
        </div>
        <div v-if="errors.companyId" class="field-error">{{ errors.companyId }}</div>
      </div>

      <!-- Title -->
      <div class="nr-group">
        <label class="nr-label" for="new-request-title-input">Název <span class="nr-required">*</span></label>
        <input
          id="new-request-title-input"
          v-model="title"
          class="nr-input"
          type="text"
          placeholder="Např. Reklamace úklidu v kanceláři"
        />
        <div v-if="errors.title" class="field-error">{{ errors.title }}</div>
      </div>

      <!-- Description -->
      <div class="nr-group">
        <label class="nr-label" for="new-request-description">Podrobný popis <span class="nr-required">*</span></label>
        <textarea
          id="new-request-description"
          v-model="description"
          class="nr-input nr-textarea"
          rows="5"
          placeholder="Popište problém co nejpodrobněji..."
        ></textarea>
        <div v-if="errors.description" class="field-error">{{ errors.description }}</div>
      </div>

      <!-- Category (optional) -->
      <div class="nr-group">
        <label class="nr-label">Kategorie <span class="nr-label-hint">(volitelné)</span></label>
        <div id="new-request-categories" class="nr-category-grid">
          <button
            v-for="c in REQUEST_CATEGORIES"
            :key="c.key"
            :id="'cat-' + c.key"
            type="button"
            class="nr-category-card"
            :class="{ active: category === c.key }"
            :aria-pressed="category === c.key"
            @click="selectCategory(c.key)"
          >
            <span class="nr-category-icon">
              <component :is="CATEGORY_CARDS[c.key]?.icon || HelpCircle" :size="16" />
            </span>
            <span class="nr-category-label">{{ CATEGORY_CARDS[c.key]?.label || c.label }}</span>
          </button>
        </div>
      </div>

      <!-- Attachments -->
      <div class="nr-group">
        <label class="nr-label">Přílohy</label>
        <label id="new-request-attach-trigger" class="nr-dropzone" for="new-request-attach-input">
          <span class="nr-dropzone-icon">
            <UploadCloud :size="18" />
          </span>
          <span class="nr-dropzone-title">Přidat soubor</span>
          <span class="nr-dropzone-hint">Max {{ ATTACHMENT_LIMITS.maxFiles }} souborů, 10 MB/soubor</span>
        </label>
        <input
          id="new-request-attach-input"
          type="file"
          multiple
          :accept="ATTACHMENT_LIMITS.acceptAttr"
          style="display:none;"
          @change="onFilesChosen"
        />
        <div v-if="fileError" class="field-error">{{ fileError }}</div>
        <ul v-if="files.length" id="new-request-attach-list" class="nr-attach-list">
          <li v-for="(f, i) in files" :key="f.name + f.size + f.lastModified" class="nr-attach-item" :id="'attach-item-' + i">
            <img
              v-if="f.type.startsWith('image/')"
              :id="'attach-thumb-' + i"
              :src="getObjectUrl(f)"
              :alt="f.name"
              class="nr-attach-thumb clickable"
              @click="openFilePreview(f)"
            />
            <template v-else>
              <FileText :size="16" />
            </template>
            <span class="nr-attach-name file-link" @click="openFilePreview(f)">{{ f.name }}</span>
            <span class="nr-attach-size">{{ formatSize(f.size) }}</span>
            <button type="button" class="nr-attach-remove" @click="removeFile(i)" :id="'attach-remove-' + i" :aria-label="'Odebrat ' + f.name">
              <X :size="14" />
            </button>
          </li>
        </ul>
      </div>

      <div v-if="errors._" class="alert alert-danger" style="margin-bottom:16px;">{{ errors._ }}</div>

      <div id="new-request-actions" class="nr-actions">
        <button id="new-request-cancel" class="btn btn-outline" @click="router.push('/zadosti')">Zrušit</button>
        <button
          id="new-request-submit"
          class="nr-submit"
          :disabled="!isValid || submitting"
          @click="submit"
        >
          <Loader2 v-if="submitting" :size="16" class="spin" />
          <span>{{ submitting ? 'Odesílám...' : 'Odeslat požadavek' }}</span>
        </button>
      </div>
    </div>

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
.nr-page {
  max-width: 640px;
}

.nr-head {
  display: flex;
  align-items: center;
  gap: 12px;
}

.nr-back {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  border: none;
  background: transparent;
  color: var(--color-primary);
  flex-shrink: 0;
  margin-left: -7px;
  transition: var(--transition);
}
.nr-back:hover {
  background: var(--color-gray-100);
}

.nr-title {
  font-size: var(--fs-2xl);
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.2;
}

.nr-subtitle {
  font-size: 14px;
  color: var(--color-gray-500);
  line-height: 1.5;
  margin: 4px 0 24px;
}

.nr-group {
  margin-bottom: 22px;
}

.nr-label {
  display: block;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 8px;
}

.nr-required {
  color: var(--color-danger);
}

.nr-label-hint {
  font-size: 12px;
  font-weight: 400;
  color: var(--color-gray-400);
}

.nr-input {
  width: 100%;
  padding: 13px 14px;
  border: 1.5px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  background: var(--color-white);
  color: var(--color-gray-800);
  /* 16px prevents iOS Safari from auto-zooming the page on field focus */
  font-size: 16px;
  transition: var(--transition);
  outline: none;
}
@media (min-width: 768px) {
  .nr-input { font-size: 14px; }
}
.nr-input::placeholder {
  color: var(--color-gray-400);
  opacity: 1;
}
.nr-input:focus {
  border-color: var(--color-blue);
  box-shadow: 0 0 0 3px var(--color-blue-light);
}

.nr-textarea {
  resize: vertical;
  min-height: 120px;
}

.nr-options-loading {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-500);
}

/* ═══ Category cards ═══ */
.nr-category-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}

.nr-category-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 82px;
  padding: 14px 8px;
  background: var(--color-white);
  border: 1.5px solid var(--color-gray-200);
  border-radius: var(--radius-xl);
  transition: var(--transition);
}
.nr-category-card:hover {
  border-color: var(--color-blue-border);
}

.nr-category-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--color-gray-100);
  color: var(--color-gray-500);
  transition: var(--transition);
}

.nr-category-label {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-gray-500);
  transition: var(--transition);
}

.nr-category-card.active {
  background: var(--color-blue-light);
  border-color: var(--color-blue);
}
.nr-category-card.active .nr-category-icon {
  background: var(--color-blue);
  color: var(--color-white);
}
.nr-category-card.active .nr-category-label {
  color: var(--color-blue);
}

/* ═══ Attachments dropzone ═══ */
.nr-dropzone {
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
.nr-dropzone:hover {
  border-color: var(--color-blue);
  background: var(--color-blue-light);
}

.nr-dropzone-icon {
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

.nr-dropzone-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-blue);
}

.nr-dropzone-hint {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-gray-400);
}

.nr-attach-list {
  list-style: none;
  padding: 0;
  margin: 12px 0 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.nr-attach-item {
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
.nr-attach-thumb {
  width: 32px;
  height: 32px;
  object-fit: cover;
  border-radius: 6px;
  border: 1px solid var(--color-gray-200);
  flex-shrink: 0;
}
.nr-attach-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nr-attach-size {
  font-size: 11px;
  color: var(--color-gray-500);
}
.nr-attach-remove {
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
.nr-attach-remove:hover {
  background: var(--color-danger-light);
  color: var(--color-danger);
}

/* ═══ Actions — mobile-first: stacked full-width, row at sm ═══ */
.nr-actions {
  display: flex;
  flex-direction: column-reverse;
  gap: 10px;
  margin-top: 4px;
}
.nr-actions .btn-outline {
  justify-content: center;
}

.nr-submit {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 13px 24px;
  border: none;
  border-radius: var(--radius-lg);
  background: var(--color-blue);
  color: var(--color-white);
  font-size: 15px;
  font-weight: 600;
  transition: var(--transition);
}
.nr-submit:hover {
  background: var(--color-blue-hover);
}
.nr-submit:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

@media (min-width: 640px) {
  .nr-actions {
    flex-direction: row;
    justify-content: flex-end;
  }
}

.field-error {
  font-size: 12px;
  color: var(--color-danger);
  margin-top: 6px;
}
</style>
