<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { Plus, AlertTriangle, Wrench, HelpCircle, Loader2, Paperclip, X, FileText, ClipboardList } from 'lucide-vue-next'
import { maintenanceRequestService, REQUEST_CATEGORIES, REQUEST_STATUSES, ATTACHMENT_LIMITS } from '../api'
import FilePreviewModal from '../components/FilePreviewModal.vue'

const router = useRouter()
const iconMap = { AlertTriangle, Wrench, HelpCircle }

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

const recentRequests = ref([])
const recentLoading = ref(true)

function statusMeta(key) {
  return REQUEST_STATUSES.find(s => s.key === key) || { label: key, badge: 'badge-gray' }
}

function formatRecentDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' })
}

async function loadRecent() {
  recentLoading.value = true
  try {
    const res = await maintenanceRequestService.list({ limit: 5 })
    if (res.success) recentRequests.value = res.data || []
  } finally {
    recentLoading.value = false
  }
}

onMounted(async () => {
  loadRecent()
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

function formatSize(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} kB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

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
  <div>
    <div id="new-request-header" class="page-header">
      <div>
        <h1 id="new-request-title" class="page-title">
          <Plus :size="22" style="vertical-align:-4px; margin-right:6px; color:var(--color-mid);" />
          Nový požadavek
        </h1>
        <p id="new-request-subtitle" class="page-subtitle">Řekněte nám, co se stalo — co nejdříve se vám ozveme.</p>
      </div>
    </div>

    <div id="new-request-layout" class="new-request-layout">
    <div id="new-request-form" class="card">
      <!-- Protistrana picker -->
      <div v-if="showCompanyPicker" class="form-group">
        <label class="form-label">Protistrana (IČO)</label>
        <div v-if="loadingOptions" class="loc-loading">
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
      <div class="form-group">
        <label class="form-label" for="new-request-title-input">Název <span style="color:var(--color-danger)">*</span></label>
        <input
          id="new-request-title-input"
          v-model="title"
          class="form-input"
          type="text"
          placeholder="Např. Reklamace úklidu v kanceláři"
        />
        <div v-if="errors.title" class="field-error">{{ errors.title }}</div>
      </div>

      <!-- Description -->
      <div class="form-group">
        <label class="form-label" for="new-request-description">Podrobný popis <span style="color:var(--color-danger)">*</span></label>
        <textarea
          id="new-request-description"
          v-model="description"
          class="form-input"
          rows="6"
          placeholder="Popište problém co nejpodrobněji..."
        ></textarea>
        <div v-if="errors.description" class="field-error">{{ errors.description }}</div>
      </div>

      <!-- Category (optional) -->
      <div class="form-group">
        <label class="form-label">Kategorie <span style="color:var(--color-gray-500); font-weight:400;">(volitelné)</span></label>
        <div id="new-request-categories" class="category-grid">
          <button
            v-for="c in REQUEST_CATEGORIES"
            :key="c.key"
            :id="'cat-' + c.key"
            type="button"
            class="category-card"
            :class="{ active: category === c.key }"
            @click="selectCategory(c.key)"
          >
            <component :is="iconMap[c.icon]" :size="22" />
            <span>{{ c.label }}</span>
          </button>
        </div>
      </div>

      <!-- Attachments -->
      <div class="form-group">
        <label class="form-label">
          Přílohy <span style="color:var(--color-gray-500); font-weight:400;">(volitelné, max 5 souborů, 10 MB/soubor, fotky a PDF)</span>
        </label>
        <label id="new-request-attach-trigger" class="attach-btn" for="new-request-attach-input">
          <Paperclip :size="16" />
          <span>Přidat soubor</span>
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
        <ul v-if="files.length" id="new-request-attach-list" class="attach-list">
          <li v-for="(f, i) in files" :key="f.name + f.size + f.lastModified" class="attach-item" :id="'attach-item-' + i">
            <img
              v-if="f.type.startsWith('image/')"
              :id="'attach-thumb-' + i"
              :src="getObjectUrl(f)"
              :alt="f.name"
              class="attach-thumb clickable"
              @click="openFilePreview(f)"
            />
            <template v-else>
              <FileText :size="16" />
            </template>
            <span class="attach-name file-link" @click="openFilePreview(f)">{{ f.name }}</span>
            <span class="attach-size">{{ formatSize(f.size) }}</span>
            <button type="button" class="attach-remove" @click="removeFile(i)" :id="'attach-remove-' + i">
              <X :size="14" />
            </button>
          </li>
        </ul>
      </div>

      <div v-if="errors._" class="alert alert-danger" style="margin-bottom:16px;">{{ errors._ }}</div>

      <div id="new-request-actions" style="display:flex; gap:12px; justify-content:flex-end;">
        <button id="new-request-cancel" class="btn btn-outline" @click="router.push('/zadosti')">Zrušit</button>
        <button
          id="new-request-submit"
          class="btn btn-primary"
          :disabled="!isValid || submitting"
          @click="submit"
        >
          <Loader2 v-if="submitting" :size="16" class="spin" />
          <Plus v-else :size="16" />
          <span>{{ submitting ? 'Odesílám...' : 'Odeslat požadavek' }}</span>
        </button>
      </div>
    </div>

    <aside id="new-request-recent" class="card recent-panel">
      <div class="recent-header">
        <ClipboardList :size="16" style="color:var(--color-mid);" />
        <h3 class="recent-title">Vaše poslední požadavky</h3>
      </div>
      <div v-if="recentLoading" class="recent-loading">
        <Loader2 :size="16" class="spin" />
      </div>
      <div v-else-if="recentRequests.length === 0" class="recent-empty">
        Zatím jste nevytvořili žádný požadavek. Tento bude první.
      </div>
      <ul v-else class="recent-list">
        <li v-for="r in recentRequests" :key="r.id">
          <router-link :to="`/zadosti/${r.id}`" :id="'recent-' + r.id" class="recent-item">
            <div class="recent-item-main">
              <div class="recent-item-title">{{ r.title }}</div>
              <div class="recent-item-date">{{ formatRecentDate(r.createdAt) }}</div>
            </div>
            <span class="badge" :class="statusMeta(r.status).badge">{{ statusMeta(r.status).label }}</span>
          </router-link>
        </li>
      </ul>
    </aside>
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
/* Mobile-first: single column. Split to 2 cols at lg. */
.new-request-layout {
  display: grid;
  grid-template-columns: 1fr;
  gap: 24px;
  align-items: start;
}

.recent-panel {
  padding: 20px;
  position: static;
}

@media (min-width: 1024px) {
  .new-request-layout {
    grid-template-columns: minmax(0, 780px) minmax(280px, 360px);
  }
  .recent-panel {
    position: sticky;
    top: 24px;
  }
}
.recent-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--color-gray-100);
}
.recent-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-primary);
  margin: 0;
}
.recent-loading {
  display: flex;
  justify-content: center;
  padding: 16px;
  color: var(--color-gray-500);
}
.recent-empty {
  font-size: 13px;
  color: var(--color-gray-500);
  padding: 8px 0;
  line-height: 1.5;
}
.recent-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.recent-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 12px;
  border-radius: var(--radius-md);
  background: var(--color-gray-50);
  border: 1px solid var(--color-gray-200);
  text-decoration: none;
  transition: var(--transition);
}
.recent-item:hover {
  border-color: var(--color-mid);
  background: var(--color-white);
}
.recent-item-main {
  flex: 1;
  min-width: 0;
}
.recent-item-title {
  font-size: 13px;
  font-weight: 500;
  color: var(--color-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.recent-item-date {
  font-size: 11px;
  color: var(--color-gray-500);
  margin-top: 2px;
}

/* new-request-layout handled mobile-first above */

/* Mobile-first: 2 cols → 3 at sm */
.category-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}
@media (min-width: 640px) {
  .category-grid { grid-template-columns: repeat(3, 1fr); }
}
.category-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 22px 12px;
  background: var(--color-white);
  border: 1.5px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  color: var(--color-gray-700);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition);
}
.category-card:hover {
  border-color: var(--color-mid);
  color: var(--color-primary);
}
.category-card.active {
  border-color: var(--color-primary);
  background: var(--color-light);
  color: var(--color-primary);
}

.loc-loading {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-500);
  margin-bottom: 14px;
}

.field-error {
  font-size: 12px;
  color: var(--color-danger);
  margin-top: 4px;
}

.attach-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border: 1.5px dashed var(--color-gray-300);
  border-radius: var(--radius-md);
  background: var(--color-gray-50);
  color: var(--color-gray-700);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition);
}
.attach-btn:hover {
  border-color: var(--color-mid);
  color: var(--color-primary);
  background: var(--color-light);
}

.attach-list {
  list-style: none;
  padding: 0;
  margin: 12px 0 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.attach-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: var(--color-gray-50);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-md);
  font-size: 13px;
  color: var(--color-gray-700);
}
.attach-thumb {
  width: 32px;
  height: 32px;
  object-fit: cover;
  border-radius: 4px;
  border: 1px solid var(--color-gray-200);
  flex-shrink: 0;
}
.attach-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.attach-size {
  font-size: 11px;
  color: var(--color-gray-500);
}
.attach-remove {
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
.attach-remove:hover {
  background: var(--color-danger-light);
  color: var(--color-danger);
}

.spin { animation: spin 1.5s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* category-grid handled mobile-first above */
</style>
