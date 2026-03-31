<script setup>
import { Phone, Mail, MapPin, ChevronDown, ChevronUp, Clock } from 'lucide-vue-next'
import { ref } from 'vue'
import { contacts, companies } from '../data/mockData.js'

const expandedCompany = ref(null)

function toggleCompany(idx) {
  expandedCompany.value = expandedCompany.value === idx ? null : idx
}

function initials(name) {
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}

const avatarColors = ['#162438', '#667ea1']
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Kontakt</h1>
        <p class="page-subtitle">Váš tým FAJN ÚKLID je tu pro vás</p>
      </div>
    </div>

    <!-- Availability note -->
    <div class="alert alert-info" style="margin-bottom:24px;">
      <Clock :size="18" />
      <span>Volejte ideálně za bílého dne :) <strong>10:00 - 17:00</strong></span>
    </div>

    <!-- Contact persons -->
    <div class="contact-grid">
      <div
        v-for="(c, idx) in contacts"
        :key="c.email"
        class="card contact-card"
      >
        <div
          class="contact-avatar avatar avatar-xl"
          :style="{ background: avatarColors[idx] }"
        >
          {{ initials(c.name) }}
        </div>
        <h2 class="contact-name">{{ c.name }}</h2>
        <p class="contact-role">{{ c.role }}</p>

        <div class="contact-actions">
          <a :href="'tel:' + c.phone.replace(/\s/g,'')" class="contact-btn">
            <Phone :size="18" />
            <span>{{ c.phone }}</span>
          </a>
          <a :href="'mailto:' + c.email" class="contact-btn">
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
