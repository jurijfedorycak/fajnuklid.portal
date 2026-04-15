<script setup>
import { Phone, Mail, MapPin, ChevronDown, ChevronUp, Clock, Loader2, Users } from 'lucide-vue-next'
import { ref, onMounted } from 'vue'
import { contactService } from '../api'
import FilePreviewModal from '../components/FilePreviewModal.vue'

// State
const loading = ref(true)
const error = ref(null)
const contacts = ref([])
const companies = ref([])
const expandedCompany = ref(null)

// Fetch data
onMounted(async () => {
  try {
    const response = await contactService.getContacts()
    if (response.success) {
      contacts.value = response.data.contacts || []
      companies.value = response.data.companies || []
    } else {
      error.value = response.message || 'Nepodařilo se načíst data'
    }
  } catch (err) {
    error.value = err.message || 'Nepodařilo se načíst data'
  } finally {
    loading.value = false
  }
})

function toggleCompany(idx) {
  expandedCompany.value = expandedCompany.value === idx ? null : idx
}

// File preview
const previewModal = ref({ show: false, url: '', filename: '' })
function openPreview(url, filename) {
  previewModal.value = { show: true, url, filename: filename || '' }
}
function closePreview() {
  previewModal.value.show = false
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}

const avatarColors = ['#162438', '#667ea1']

function whatsappUrl(phone) {
  if (!phone) return '#'
  // Strip everything but digits; wa.me wants international form without + or spaces
  return 'https://wa.me/' + phone.replace(/\D/g, '')
}
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Kontakt</h1>
        <p class="page-subtitle">Váš tým FAJN ÚKLID je tu pro vás</p>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám kontakty...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Empty state -->
    <div v-else-if="contacts.length === 0" class="card">
      <div class="empty-state">
        <Users :size="40" class="empty-state-icon" />
        <p class="empty-state-title">Kontakty nejsou k dispozici.</p>
      </div>
    </div>

    <!-- Content -->
    <template v-else>
    <!-- Availability note -->
    <div class="alert alert-info" style="margin-bottom:24px;">
      <Clock :size="18" />
      <span>Volejte ideálně za bílého dne :) <strong>10:00 - 17:00</strong></span>
    </div>

    <!-- Contact persons -->
    <div class="contact-grid">
      <div
        v-for="(c, idx) in contacts"
        :key="c.id"
        class="card contact-card"
      >
        <div
          class="contact-avatar avatar avatar-xl"
          :class="{ 'clickable': c.photo_url }"
          :style="c.photo_url ? {} : { background: avatarColors[idx % avatarColors.length] }"
          @click="c.photo_url && openPreview(c.photo_url, c.name)"
        >
          <img v-if="c.photo_url" :src="c.photo_url" :alt="c.name" class="avatar-img" />
          <template v-else>{{ initials(c.name) }}</template>
        </div>
        <h2 class="contact-name">{{ c.name }}</h2>
        <p class="contact-role">{{ c.role }}</p>

        <div class="contact-actions">
          <a v-if="c.phone" :href="'tel:' + c.phone.replace(/\s/g,'')" class="contact-btn">
            <Phone :size="18" />
            <span>{{ c.phone }}</span>
          </a>
          <a
            v-if="c.phone"
            :id="`contact-whatsapp-${c.id}`"
            :href="whatsappUrl(c.phone)"
            target="_blank"
            rel="noopener noreferrer"
            class="contact-btn contact-btn-whatsapp"
            :title="'Napsat na WhatsApp ' + c.phone"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M19.11 4.91A10.05 10.05 0 0 0 12.04 2C6.55 2 2.08 6.47 2.08 11.96c0 1.76.46 3.47 1.34 4.98L2 22l5.2-1.36a9.93 9.93 0 0 0 4.84 1.23h.01c5.49 0 9.96-4.47 9.96-9.96 0-2.66-1.04-5.16-2.9-7zM12.05 20.2h-.01a8.27 8.27 0 0 1-4.21-1.15l-.3-.18-3.09.81.82-3.01-.2-.31a8.24 8.24 0 0 1-1.27-4.4c0-4.56 3.71-8.27 8.28-8.27 2.21 0 4.29.86 5.85 2.43a8.21 8.21 0 0 1 2.43 5.86c0 4.56-3.72 8.27-8.3 8.27zm4.54-6.19c-.25-.13-1.47-.73-1.7-.81-.23-.08-.4-.13-.56.13-.17.25-.65.81-.79.98-.15.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.39-1.72-.15-.25-.02-.39.11-.51.11-.11.25-.29.37-.43.12-.15.16-.25.25-.41.08-.17.04-.31-.02-.43-.06-.13-.56-1.34-.76-1.84-.2-.49-.41-.42-.56-.43-.14-.01-.31-.01-.48-.01-.17 0-.43.06-.66.31-.23.25-.86.85-.86 2.06 0 1.22.88 2.39 1 2.56.12.17 1.74 2.65 4.21 3.71.59.25 1.05.4 1.4.52.59.19 1.13.16 1.55.1.47-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.11-.23-.17-.48-.29z"/>
            </svg>
            <span>WhatsApp</span>
          </a>
          <a v-if="c.email" :href="'mailto:' + c.email" class="contact-btn">
            <Mail :size="18" />
            <span>{{ c.email }}</span>
          </a>
        </div>
      </div>
    </div>

    <!-- Company info accordion -->
    <div style="margin-top:32px;">
      <h2 class="section-title">Firemní údaje</h2>
      <div class="companies-list">
        <div
          v-for="(company, idx) in companies"
          :key="company.ico"
          class="card company-card"
        >
          <button class="company-header" @click="toggleCompany(idx)">
            <span class="company-name">{{ company.name }}</span>
            <ChevronDown v-if="expandedCompany !== idx" :size="18" />
            <ChevronUp   v-else :size="18" />
          </button>

          <div v-if="expandedCompany === idx" class="company-details">
            <div class="company-row">
              <MapPin :size="14" class="ci-icon" />
              <span>{{ company.address }}</span>
            </div>
            <div class="company-row">
              <span class="ci-label">IČO:</span>
              <span>{{ company.ico }}</span>
            </div>
            <div class="company-row">
              <span class="ci-label">DIČ:</span>
              <span>{{ company.dic }}</span>
            </div>
            <div class="company-row">
              <span class="ci-label">Zápis:</span>
              <span>{{ company.registration }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
    </template>

    <FilePreviewModal
      :show="previewModal.show"
      :url="previewModal.url"
      :filename="previewModal.filename"
      @close="closePreview"
    />
  </div>
</template>

<style scoped>
.contact-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}

.contact-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 10px;
  padding: 36px 28px;
}

.contact-avatar {
  margin-bottom: 4px;
}

.contact-name {
  font-size: 20px;
  font-weight: 700;
  color: var(--color-primary);
}

.contact-role {
  font-size: 13px;
  color: var(--color-gray-600);
  margin-top: -4px;
}

.contact-actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
  width: 100%;
  margin-top: 8px;
}

.contact-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 20px;
  border-radius: var(--radius-md);
  background: var(--color-light);
  color: var(--color-primary);
  font-size: 14px;
  font-weight: 500;
  transition: var(--transition);
  text-decoration: none;
}

.contact-btn:hover {
  background: var(--color-light-hover);
  color: var(--color-primary);
}

.contact-btn-whatsapp {
  background: var(--color-light);
  color: #128C7E;
}
.contact-btn-whatsapp:hover {
  background: var(--color-light-hover);
  color: #075E54;
}

/* Companies */
.section-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 12px;
}

.companies-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.company-card {
  padding: 0;
  overflow: hidden;
}

.company-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 16px 20px;
  background: none;
  border: none;
  cursor: pointer;
  text-align: left;
  color: var(--color-primary);
}

.company-header:hover {
  background: var(--color-gray-50);
}

.company-name {
  font-size: 15px;
  font-weight: 600;
}

.company-details {
  padding: 0 20px 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  border-top: 1px solid var(--color-gray-100);
}

.company-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-700);
  padding-top: 8px;
}

.ci-icon {
  color: var(--color-mid);
  flex-shrink: 0;
  margin-top: 1px;
}

.ci-label {
  font-weight: 500;
  color: var(--color-gray-600);
  min-width: 48px;
}

@media (max-width: 640px) {
  .contact-grid {
    grid-template-columns: 1fr;
  }
}
</style>
