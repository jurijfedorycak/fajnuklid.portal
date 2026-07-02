<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import {
  FileSignature, FileText, FileSpreadsheet, Image as ImageIcon, File as FileIcon,
  Download, Phone, Mail, Loader2, Clock, Eye,
} from 'lucide-vue-next'
import { contractService } from '../api'
import FilePreviewModal from '../components/FilePreviewModal.vue'
import { formatFileSize as formatSize } from '../utils/fileUtils'

const loading = ref(true)
const error = ref(null)
const data = ref({
  contractsEnabled: false,
  hasDocuments: false,
  companies: [],
  contact: null,
})

// Cache object URLs per document id so preview + download don't re-fetch, and so we can
// revoke them all on unmount.
const objectUrls = new Map()
const busyDocId = ref(null)

const previewModal = ref({ show: false, url: '', filename: '', mimeType: '' })

const showCompanyHeaders = computed(() => data.value.companies.length > 1)

onMounted(async () => {
  try {
    const response = await contractService.getContract()
    if (response.success) {
      data.value = response.data
    } else {
      error.value = response.message || 'Nepodařilo se načíst data'
    }
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'Nepodařilo se načíst data'
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  for (const url of objectUrls.values()) {
    window.URL.revokeObjectURL(url)
  }
  objectUrls.clear()
})

// Group a company's documents by their (free-text) type so the client sees a tidy,
// categorised list. Uncategorised documents fall into a "Ostatní dokumenty" bucket.
function groupedDocuments(documents) {
  const groups = new Map()
  for (const doc of documents) {
    const key = doc.documentType || 'Ostatní dokumenty'
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(doc)
  }
  return Array.from(groups, ([type, docs]) => ({ type, docs }))
}

function docIcon(mimeType) {
  if (mimeType === 'application/pdf') return FileText
  if (mimeType?.startsWith('image/')) return ImageIcon
  if (mimeType?.includes('spreadsheet') || mimeType?.includes('ms-excel')) return FileSpreadsheet
  if (mimeType?.includes('word')) return FileText
  return FileIcon
}

function formatDate(d) {
  if (!d) return ''
  const datePart = String(d).split(' ')[0]
  const [y, m, day] = datePart.split('-')
  if (!y || !m || !day) return ''
  return `${day}.${m}.${y}`
}

async function resolveUrl(doc) {
  if (objectUrls.has(doc.id)) return objectUrls.get(doc.id)
  const blob = await contractService.downloadDocument(doc.id)
  const url = window.URL.createObjectURL(blob)
  objectUrls.set(doc.id, url)
  return url
}

async function openPreview(doc) {
  if (busyDocId.value) return
  busyDocId.value = doc.id
  try {
    const url = await resolveUrl(doc)
    previewModal.value = { show: true, url, filename: doc.filename, mimeType: doc.mimeType }
  } catch {
    error.value = 'Náhled dokumentu se nepodařilo načíst'
  } finally {
    busyDocId.value = null
  }
}

function closePreview() {
  previewModal.value.show = false
}

async function downloadDocument(doc) {
  if (busyDocId.value) return
  busyDocId.value = doc.id
  try {
    const url = await resolveUrl(doc)
    const link = document.createElement('a')
    link.href = url
    link.download = doc.filename || doc.title || 'dokument'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
  } catch {
    error.value = 'Stažení dokumentu se nepodařilo'
  } finally {
    busyDocId.value = null
  }
}
</script>

<template>
  <div id="contract-page" class="page-shell page-shell--md">
    <div class="page-header">
      <div>
        <h1 class="page-title">Smlouvy a dokumenty</h1>
        <p class="page-subtitle">Vaše smlouvy, dodatky a další dokumenty ke stažení</p>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám dokumenty...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Documents available -->
    <div v-else-if="data.contractsEnabled && data.hasDocuments" class="documents-wrap">
      <section
        v-for="company in data.companies"
        v-show="company.documents.length > 0"
        :id="`documents-company-${company.id}`"
        :key="company.id"
        class="card documents-company"
      >
        <header v-if="showCompanyHeaders" class="documents-company-header">
          <h2 class="documents-company-name">{{ company.name }}</h2>
          <span v-if="company.registrationNumber" class="documents-company-ico">
            IČO {{ company.registrationNumber }}
          </span>
        </header>

        <div
          v-for="group in groupedDocuments(company.documents)"
          :key="group.type"
          class="document-group"
        >
          <h3 :id="`document-group-${company.id}-${group.type}`" class="document-group-title">
            {{ group.type }}
          </h3>

          <ul class="document-list">
            <li
              v-for="doc in group.docs"
              :id="`document-row-${doc.id}`"
              :key="doc.id"
              class="document-row"
              role="button"
              tabindex="0"
              @click="openPreview(doc)"
              @keydown.enter="openPreview(doc)"
              @keydown.space.prevent="openPreview(doc)"
            >
              <span class="document-icon" aria-hidden="true">
                <component :is="docIcon(doc.mimeType)" :size="20" />
              </span>

              <span class="document-info">
                <span class="document-title">{{ doc.title }}</span>
                <span class="document-meta">
                  <span class="document-filename">{{ doc.filename }}</span>
                  <span v-if="formatSize(doc.sizeBytes)" class="document-dot">·</span>
                  <span v-if="formatSize(doc.sizeBytes)">{{ formatSize(doc.sizeBytes) }}</span>
                  <span v-if="formatDate(doc.uploadedAt)" class="document-dot">·</span>
                  <span v-if="formatDate(doc.uploadedAt)">{{ formatDate(doc.uploadedAt) }}</span>
                </span>
              </span>

              <span class="document-actions">
                <Loader2 v-if="busyDocId === doc.id" :size="16" class="spin" style="color:var(--color-gray-400);" />
                <button
                  :id="`document-preview-${doc.id}`"
                  class="btn btn-ghost btn-sm"
                  aria-label="Zobrazit náhled"
                  @click.stop="openPreview(doc)"
                >
                  <Eye :size="15" />
                </button>
                <button
                  :id="`document-download-${doc.id}`"
                  class="btn btn-ghost btn-sm"
                  aria-label="Stáhnout dokument"
                  @click.stop="downloadDocument(doc)"
                >
                  <Download :size="15" />
                </button>
              </span>
            </li>
          </ul>
        </div>
      </section>
    </div>

    <!-- No documents yet (enabled but empty) — friendly onboarding tone -->
    <div
      v-else-if="data.contractsEnabled && !data.hasDocuments"
      id="documents-pending"
      class="onboarding-hero documents-pending"
    >
      <div class="onboarding-hero-icon onboarding-hero-icon--soft">
        <FileSignature :size="28" aria-hidden="true" />
      </div>
      <h2 id="documents-pending-title" class="onboarding-hero-title">
        Vaše dokumenty tu brzy najdete
      </h2>
      <p id="documents-pending-desc" class="onboarding-hero-desc">
        Jakmile nahrajeme vaši smlouvu, dodatky nebo další dokumenty, uvidíte je tady
        i s možností náhledu a stažení. Potřebujete mezitím kopii nebo máte dotaz? Napište nám.
      </p>
      <div class="documents-pending-meta">
        <span class="documents-pending-meta-item">
          <Clock :size="14" aria-hidden="true" />
          Obvykle do 2 pracovních dnů od podpisu
        </span>
      </div>
      <div class="onboarding-hero-actions">
        <a
          v-if="data.contact?.phone"
          id="documents-pending-call"
          :href="`tel:${data.contact.phone}`"
          class="btn btn-primary btn-sm"
        >
          <Phone :size="14" aria-hidden="true" />
          {{ data.contact.phone }}
        </a>
        <a
          v-if="data.contact?.email"
          id="documents-pending-email"
          :href="`mailto:${data.contact.email}`"
          class="btn btn-outline btn-sm"
        >
          <Mail :size="14" aria-hidden="true" />
          Napsat e-mail
        </a>
      </div>
    </div>

    <!-- Section disabled -->
    <div v-else>
      <div class="card">
        <div class="empty-state">
          <FileSignature :size="40" class="empty-state-icon" />
          <p class="empty-state-title">Sekce dokumentů není pro váš účet aktivní.</p>
          <p class="empty-state-text">V případě dotazů nás kontaktujte.</p>
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
  </div>
</template>

<style scoped>
/* Mobile-first: stacked cards; documents list is one column on every breakpoint. */
.documents-wrap {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.documents-company {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.documents-company-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--color-gray-200);
}

.documents-company-name {
  font-size: var(--fs-lg);
  font-weight: 700;
  color: var(--color-primary);
}

.documents-company-ico {
  font-size: 13px;
  color: var(--color-gray-500);
}

.document-group {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.document-group-title {
  font-size: 13px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-mid);
}

.document-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  list-style: none;
  margin: 0;
  padding: 0;
}

/* Clickable rows carry a solid border (interactive blocks may; static ones stay flat). */
.document-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-md);
  background: var(--color-white);
  cursor: pointer;
  transition: border-color var(--transition), box-shadow var(--transition);
}
.document-row:hover {
  border-color: var(--color-mid);
}
.document-row:focus-visible {
  outline: none;
  border-color: var(--color-mid);
  box-shadow: 0 0 0 3px var(--color-light);
}

.document-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-md);
  background: var(--color-light);
  color: var(--color-primary);
}

.document-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}

.document-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-gray-800);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.document-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--color-gray-500);
}

.document-filename {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 200px;
}

.document-dot {
  color: var(--color-gray-300);
}

.document-actions {
  display: flex;
  align-items: center;
  gap: 2px;
  flex-shrink: 0;
}

.documents-pending {
  max-width: 560px;
  margin: 0 auto;
}

.documents-pending-meta {
  display: flex;
  justify-content: center;
  margin-top: 4px;
}

.documents-pending-meta-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  background: var(--color-gray-50);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-pill);
  font-size: 12px;
  color: var(--color-gray-600);
}
</style>
