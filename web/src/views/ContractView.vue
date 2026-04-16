<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { FileSignature, Download, Phone, Mail, Loader2, Clock } from 'lucide-vue-next'
import { contractService } from '../api'
import FilePreviewModal from '../components/FilePreviewModal.vue'

// State
const loading = ref(true)
const error = ref(null)
const contract = ref({
  contractsEnabled: false,
  hasPdf: false,
  filename: null,
  uploadedAt: null,
})
const pdfPreviewUrl = ref(null)
const pdfPreviewFailed = ref(false)

// Fetch data
onMounted(async () => {
  try {
    const response = await contractService.getContract()
    if (response.success) {
      contract.value = response.data
      if (response.data.hasPdf && response.data.companyId) {
        loadPdfPreview(response.data.companyId)
      }
    } else {
      error.value = response.message || 'Nepodařilo se načíst data'
    }
  } catch (err) {
    error.value = err.message || 'Nepodařilo se načíst data'
  } finally {
    loading.value = false
  }
})

async function loadPdfPreview(companyId) {
  try {
    const blob = await contractService.downloadContract(companyId)
    pdfPreviewUrl.value = window.URL.createObjectURL(blob)
  } catch {
    pdfPreviewFailed.value = true
  }
}

onBeforeUnmount(() => {
  if (pdfPreviewUrl.value) {
    window.URL.revokeObjectURL(pdfPreviewUrl.value)
  }
})

function formatDate(d) {
  if (!d) return ''
  const [y, m, day] = d.split('-')
  return `${day}.${m}.${y}`
}

const previewModal = ref({ show: false })
function openPreview() {
  if (pdfPreviewUrl.value) {
    previewModal.value.show = true
  }
}
function closePreview() {
  previewModal.value.show = false
}

async function downloadContract() {
  try {
    const blob = await contractService.downloadContract(contract.value.companyId)
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = contract.value.filename || 'smlouva.pdf'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)
  } catch (err) {
    console.error('Failed to download contract:', err)
  }
}
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Smlouva</h1>
        <p class="page-subtitle">Vaše podepsaná smlouva o poskytování úklidových služeb</p>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám smlouvu...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Contract available -->
    <div v-else-if="contract.contractsEnabled && contract.hasPdf" class="contract-available">
      <div class="card contract-main">
        <div class="contract-icon-wrap">
          <FileSignature :size="48" color="#162438" />
        </div>
        <h2 class="contract-title">Vaše smlouva</h2>
        <p class="contract-desc">
          Podepsaná smlouva je k dispozici ke stažení. Dokument byl nahrán {{ formatDate(contract.uploadedAt) }}.
        </p>

        <div class="contract-file-info">
          <FileSignature :size="18" />
          <span class="file-link" @click="openPreview">{{ contract.filename }}</span>
        </div>

        <button class="btn btn-primary btn-lg" style="margin-top:8px;" @click="downloadContract">
          <Download :size="20" />
          Stáhnout smlouvu (PDF)
        </button>
      </div>

      <!-- PDF preview -->
      <div id="contract-pdf-preview" class="card pdf-preview">
        <iframe
          v-if="pdfPreviewUrl"
          id="contract-pdf-iframe"
          :src="pdfPreviewUrl"
          class="pdf-iframe"
          title="Náhled smlouvy"
        />
        <div v-else-if="pdfPreviewFailed" class="pdf-placeholder">
          <FileSignature :size="48" style="color:var(--color-gray-300);" />
          <p class="text-muted" style="font-size:13px; margin-top:12px;">Náhled není dostupný</p>
          <button class="btn btn-outline btn-sm" style="margin-top:12px;" @click="downloadContract">
            <Download :size="15" /> Stáhnout PDF
          </button>
        </div>
        <div v-else class="pdf-placeholder">
          <Loader2 :size="32" class="spin" style="color:var(--color-gray-300);" />
          <p class="text-muted" style="font-size:13px; margin-top:12px;">Načítám náhled...</p>
        </div>
      </div>
    </div>

    <!-- Contract missing (enabled but no PDF) — friendly onboarding tone -->
    <div v-else-if="contract.contractsEnabled && !contract.hasPdf" id="contract-pending" class="onboarding-hero contract-pending">
      <div class="onboarding-hero-icon onboarding-hero-icon--soft">
        <FileSignature :size="28" aria-hidden="true" />
      </div>
      <h2 id="contract-pending-title" class="onboarding-hero-title">
        Vaši smlouvu tu brzy najdete
      </h2>
      <p id="contract-pending-desc" class="onboarding-hero-desc">
        Jakmile podepsanou smlouvu nahrajeme, uvidíte ji tady i s tlačítkem pro stažení PDF.
        Potřebujete mezitím kopii nebo máte dotaz? Napište nám.
      </p>
      <div class="contract-pending-meta">
        <span class="contract-pending-meta-item">
          <Clock :size="14" aria-hidden="true" />
          Obvykle do 2 pracovních dnů od podpisu
        </span>
      </div>
      <div class="onboarding-hero-actions">
        <a id="contract-pending-call" href="tel:+420773023608" class="btn btn-primary btn-sm">
          <Phone :size="14" aria-hidden="true" />
          +420 773 023 608
        </a>
        <a id="contract-pending-email" href="mailto:jurij.fedorycak@fajnuklid.cz" class="btn btn-outline btn-sm">
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
          <p class="empty-state-title">Sekce smlouvy není pro váš účet aktivní.</p>
          <p class="empty-state-text">V případě dotazů nás kontaktujte.</p>
        </div>
      </div>
    </div>
    <FilePreviewModal
      :show="previewModal.show"
      :url="pdfPreviewUrl || ''"
      :filename="contract.filename || 'smlouva.pdf'"
      @close="closePreview"
    />
  </div>
</template>

<style scoped>
/* Mobile-first: stacked; two-column at ≥768 */
.contract-available {
  display: grid;
  grid-template-columns: 1fr;
  gap: 20px;
  align-items: start;
}
@media (min-width: 768px) {
  .contract-available {
    grid-template-columns: 1fr 1fr;
  }
}

.contract-main {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 40px 32px;
  gap: 12px;
}

.contract-icon-wrap {
  width: 80px;
  height: 80px;
  background: var(--color-light);
  border-radius: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 4px;
}

.contract-title {
  font-size: var(--fs-2xl);
  font-weight: 700;
  color: var(--color-primary);
}

.contract-desc {
  font-size: 14px;
  color: var(--color-gray-600);
  line-height: 1.6;
}

.contract-file-info {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  background: var(--color-gray-50);
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-gray-700);
  border: 1px solid var(--color-gray-200);
}

/* PDF preview */
.pdf-preview {
  min-height: 360px;
  overflow: hidden;
}

.pdf-iframe {
  width: 100%;
  height: clamp(360px, 70vh, 600px);
  border: none;
  display: block;
}

.pdf-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  min-height: 300px;
  border: 2px dashed var(--color-gray-200);
  border-radius: 8px;
  padding: 40px;
}

/* Contract pending (onboarding tone) */
.contract-pending {
  max-width: 560px;
  margin: 0 auto;
}

.contract-pending-meta {
  display: flex;
  justify-content: center;
  margin-top: 4px;
}

.contract-pending-meta-item {
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

/* .contract-available handled mobile-first above */
</style>
