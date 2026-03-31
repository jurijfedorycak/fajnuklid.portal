<script setup>
import { FileSignature, Download, AlertTriangle, Phone, Mail } from 'lucide-vue-next'
import { contract, contacts } from '../data/mockData.js'

function formatDate(d) {
  const [y, m, day] = d.split('-')
  return `${day}.${m}.${y}`
}

function downloadContract() {
  alert(`Stáhnout: ${contract.filename}\n(Ve finální aplikaci zde bude odkaz na dokument z Airtable.)`)
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

    <!-- Contract available -->
    <div v-if="contract.contractsEnabled && contract.hasPdf" class="contract-available">
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
          <span>{{ contract.filename }}</span>
        </div>

        <button class="btn btn-primary btn-lg" style="margin-top:8px;" @click="downloadContract">
          <Download :size="20" />
          Stáhnout smlouvu (PDF)
        </button>
      </div>

      <!-- PDF preview placeholder -->
      <div class="card pdf-preview">
        <div class="pdf-placeholder">
          <FileSignature :size="64" style="color:var(--color-gray-300);" />
          <p class="text-muted" style="font-size:13px; margin-top:12px;">Náhled dokumentu</p>
          <p class="text-muted" style="font-size:12px;">(Ve finální aplikaci bude zobrazen náhled PDF)</p>
          <button class="btn btn-outline btn-sm" style="margin-top:16px;" @click="downloadContract">
            <Download :size="15" />
            Otevřít PDF
          </button>
        </div>
      </div>
    </div>

    <!-- Contract missing (enabled but no PDF) -->
    <div v-else-if="contract.contractsEnabled && !contract.hasPdf">
      <div class="card contract-missing">
        <AlertTriangle :size="48" color="#e67e00" />
        <h2 class="missing-title">Smlouva není k dispozici</h2>
        <p class="missing-text">
          Smlouva zde není nahraná, prosím obraťte se na nás telefonicky či e-mailem.
        </p>
        <div class="missing-contacts">
          <a :href="'tel:+420773023608'" class="btn btn-outline">
            <Phone :size="16" />
            +420 773 023 608
          </a>
          <a href="mailto:jurij.fedorycak@fajnuklid.cz" class="btn btn-outline">
            <Mail :size="16" />
            jurij.fedorycak@fajnuklid.cz
          </a>
        </div>
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
  </div>
</template>

<style scoped>
.contract-available {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  align-items: start;
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
  font-size: 22px;
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

/* Missing contract */
.contract-missing {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 60px 32px;
  gap: 16px;
  max-width: 480px;
  margin: 0 auto;
}

.missing-title {
  font-size: 20px;
  font-weight: 600;
  color: var(--color-warning);
}

.missing-text {
  font-size: 14px;
  color: var(--color-gray-600);
  line-height: 1.6;
}

.missing-contacts {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  justify-content: center;
}

@media (max-width: 768px) {
  .contract-available {
    grid-template-columns: 1fr;
  }
}
</style>
