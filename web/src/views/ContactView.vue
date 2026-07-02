<script setup>
import { Phone, Mail, MapPin, Clock, Info, Loader2, Users } from 'lucide-vue-next'
import { ref, computed, onMounted } from 'vue'
import { contactService } from '../api'
import { handleExternalClick } from '../utils/openExternal'

const loading = ref(true)
const error = ref(null)
const contacts = ref([])
const companies = ref([])
const office = ref(null)
const whatsappGroupUrl = ref(null)

onMounted(async () => {
  try {
    const response = await contactService.getContacts()
    if (response.success) {
      contacts.value = response.data.contacts || []
      companies.value = response.data.companies || []
      office.value = response.data.office || null
      whatsappGroupUrl.value = response.data.whatsappGroupUrl || null
    } else {
      error.value = response.message || 'Nepodařilo se načíst data'
    }
  } catch (err) {
    error.value = err.message || 'Nepodařilo se načíst data'
  } finally {
    loading.value = false
  }
})

const phoneContacts = computed(() => contacts.value.filter(c => c.phone))

// One row per unique e-mail; shared mailboxes list all owners' names
const emailRows = computed(() => {
  const byEmail = new Map()
  for (const c of contacts.value) {
    if (!c.email) continue
    if (!byEmail.has(c.email)) {
      byEmail.set(c.email, { id: c.id, email: c.email, names: [] })
    }
    byEmail.get(c.email).names.push(c.name)
  }
  return [...byEmail.values()].map(r => ({ ...r, names: r.names.join(', ') }))
})

const hasAnyContent = computed(() => Boolean(
  phoneContacts.value.length
  || emailRows.value.length
  || office.value
  || companies.value.length
  || whatsappGroupUrl.value
))
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
    <div v-if="loading" id="contact-loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám kontakty...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" id="contact-error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Empty state -->
    <div v-else-if="!hasAnyContent" id="contact-empty" class="card">
      <div class="empty-state">
        <Users :size="40" class="empty-state-icon" />
        <p class="empty-state-title">Kontakty nejsou k dispozici.</p>
      </div>
    </div>

    <!-- Content -->
    <template v-else>
      <!-- WhatsApp CTA -->
      <a
        v-if="whatsappGroupUrl"
        id="contact-whatsapp-cta"
        :href="whatsappGroupUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="wa-cta"
        title="Napsat nám na WhatsApp"
        @click="handleExternalClick($event, whatsappGroupUrl)"
      >
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M19.11 4.91A10.05 10.05 0 0 0 12.04 2C6.55 2 2.08 6.47 2.08 11.96c0 1.76.46 3.47 1.34 4.98L2 22l5.2-1.36a9.93 9.93 0 0 0 4.84 1.23h.01c5.49 0 9.96-4.47 9.96-9.96 0-2.66-1.04-5.16-2.9-7zM12.05 20.2h-.01a8.27 8.27 0 0 1-4.21-1.15l-.3-.18-3.09.81.82-3.01-.2-.31a8.24 8.24 0 0 1-1.27-4.4c0-4.56 3.71-8.27 8.28-8.27 2.21 0 4.29.86 5.85 2.43a8.21 8.21 0 0 1 2.43 5.86c0 4.56-3.72 8.27-8.3 8.27zm4.54-6.19c-.25-.13-1.47-.73-1.7-.81-.23-.08-.4-.13-.56.13-.17.25-.65.81-.79.98-.15.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.39-1.72-.15-.25-.02-.39.11-.51.11-.11.25-.29.37-.43.12-.15.16-.25.25-.41.08-.17.04-.31-.02-.43-.06-.13-.56-1.34-.76-1.84-.2-.49-.41-.42-.56-.43-.14-.01-.31-.01-.48-.01-.17 0-.43.06-.66.31-.23.25-.86.85-.86 2.06 0 1.22.88 2.39 1 2.56.12.17 1.74 2.65 4.21 3.71.59.25 1.05.4 1.4.52.59.19 1.13.16 1.55.1.47-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.11-.23-.17-.48-.29z"/>
        </svg>
        <span>Napište nám na WhatsApp</span>
      </a>

      <div id="contact-info-grid" class="info-grid">
        <!-- Phone numbers -->
        <div v-if="phoneContacts.length" id="contact-phones-card" class="card info-card">
          <span class="info-icon"><Phone :size="20" /></span>
          <div class="info-body">
            <h2 class="info-label">Telefonní čísla</h2>
            <a
              v-for="c in phoneContacts"
              :key="'phone-' + c.id"
              :id="`contact-phone-${c.id}`"
              :href="'tel:' + c.phone.replace(/\s/g, '')"
              class="info-row"
            >
              <span class="info-value">{{ c.phone }}</span>
              <span class="info-person">{{ c.name }}</span>
            </a>
            <p id="contact-phones-note" class="info-note">
              <Clock :size="13" />
              <span>Volejte ideálně za bílého dne :) <strong>10:00&nbsp;–&nbsp;17:00</strong></span>
            </p>
          </div>
        </div>

        <!-- E-mail -->
        <div v-if="emailRows.length" id="contact-email-card" class="card info-card">
          <span class="info-icon"><Mail :size="20" /></span>
          <div class="info-body">
            <h2 class="info-label">E-mail</h2>
            <a
              v-for="r in emailRows"
              :key="'email-' + r.email"
              :id="`contact-email-${r.id}`"
              :href="'mailto:' + r.email"
              class="info-row"
            >
              <span class="info-value">{{ r.email }}</span>
              <span class="info-person">{{ r.names }}</span>
            </a>
          </div>
        </div>

        <!-- Office -->
        <div v-if="office" id="contact-office-card" class="card info-card">
          <span class="info-icon"><MapPin :size="20" /></span>
          <div class="info-body">
            <h2 class="info-label">Kancelář</h2>
            <p class="info-value">{{ office.name }}</p>
            <p class="info-address">{{ office.addressLine1 }}<br />{{ office.addressLine2 }}</p>
            <p v-if="office.note" id="contact-office-note" class="info-note info-note-italic">
              <Info :size="13" />
              <span>{{ office.note }}</span>
            </p>
          </div>
        </div>
      </div>

      <!-- Company billing info -->
      <div v-if="companies.length" id="contact-companies" class="companies-section">
        <div
          v-for="company in companies"
          :key="company.ico"
          class="company-block"
        >
          <h2 :id="`company-heading-${company.ico}`" class="company-heading">{{ company.name }}</h2>
          <div :id="`company-card-${company.ico}`" class="card company-card">
            <p class="info-label">Fakturační údaje</p>
            <p class="company-address">{{ company.address }}</p>
            <div class="company-ids">
              <div>
                <p class="info-label">IČO</p>
                <p class="company-id-value">{{ company.ico }}</p>
              </div>
              <div v-if="company.dic">
                <p class="info-label">DIČ</p>
                <p class="company-id-value">{{ company.dic }}</p>
              </div>
            </div>
            <p class="company-registration">{{ company.registration }}</p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* WhatsApp CTA */
.wa-cta {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  padding: 14px 20px;
  margin-bottom: 16px;
  border-radius: var(--radius-lg);
  background: var(--color-whatsapp);
  color: var(--color-white);
  font-size: var(--fs-md);
  font-weight: 600;
  text-decoration: none;
  transition: var(--transition);
}

.wa-cta:hover {
  background: var(--color-whatsapp-hover);
  color: var(--color-white);
}

/* Info cards */
.info-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}
@media (min-width: 640px) {
  .info-grid {
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
  }
}

.info-card {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 20px;
}

.info-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-md);
  background: var(--color-light);
  color: var(--color-accent);
}

.info-body {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}

.info-label {
  font-size: var(--fs-xs);
  font-weight: 500;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-gray-500);
}

.info-row {
  display: flex;
  flex-direction: column;
  text-decoration: none;
}

.info-row:hover .info-value {
  color: var(--color-accent);
}

.info-value {
  font-size: var(--fs-lg);
  font-weight: 700;
  color: var(--color-primary);
  transition: var(--transition);
  overflow-wrap: anywhere;
}

.info-person {
  font-size: var(--fs-xs);
  color: var(--color-gray-500);
}

.info-address {
  font-size: var(--fs-md);
  color: var(--color-gray-700);
  line-height: 1.5;
}

.info-note {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  margin-top: 6px;
  font-size: var(--fs-xs);
  color: var(--color-gray-500);
}

.info-note svg {
  flex-shrink: 0;
  margin-top: 1px;
}

.info-note-italic {
  font-style: italic;
}

/* Companies */
.companies-section {
  display: flex;
  flex-direction: column;
  gap: 20px;
  margin-top: 28px;
}

.company-heading {
  font-size: var(--fs-lg);
  font-weight: 700;
  color: var(--color-primary);
  margin-bottom: 10px;
}

.company-card {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 20px;
}

.company-address {
  font-size: var(--fs-md);
  color: var(--color-gray-600);
}

.company-ids {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
  margin-top: 4px;
}

.company-id-value {
  font-size: var(--fs-lg);
  font-weight: 700;
  color: var(--color-primary);
  margin-top: 2px;
}

.company-registration {
  font-size: var(--fs-xs);
  font-style: italic;
  color: var(--color-gray-400);
  margin-top: 4px;
}

@media (min-width: 1024px) {
  .companies-section {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    align-items: start;
  }
}
</style>
