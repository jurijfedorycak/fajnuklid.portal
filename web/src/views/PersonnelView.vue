<script setup>
import { ref, computed, onMounted } from 'vue'
import { Users, Clock, Star, BookOpen, MapPin, Loader2, Sparkles } from 'lucide-vue-next'
import { personnelService } from '../api'
import FilePreviewModal from '../components/FilePreviewModal.vue'

// State
const loading = ref(true)
const error = ref(null)
const personnelByLocation = ref([])

// Active IČO tab
const activeIco = ref(null)

// Fetch data
onMounted(async () => {
  try {
    const response = await personnelService.getPersonnel()
    if (response.success) {
      personnelByLocation.value = response.data || []
      // Set default active IČO
      if (personnelByLocation.value.length > 0) {
        activeIco.value = personnelByLocation.value[0].ico
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

const activeGroup = computed(() => {
  if (!personnelByLocation.value.length) return { objects: [] }
  return personnelByLocation.value.find(g => g.ico === activeIco.value) || personnelByLocation.value[0]
})

const totalStaff = computed(() => {
  if (!activeGroup.value.objects) return 0
  return activeGroup.value.objects.reduce((sum, obj) => sum + (obj.staff?.length || 0), 0)
})

const colors = ['#667ea1', '#198754', '#0d6efd', '#e67e00', '#6f42c1', '#d63384']
// File preview
const previewModal = ref({ show: false, url: '', filename: '' })
function openPreview(url, filename) {
  previewModal.value = { show: true, url, filename: filename || '' }
}
function closePreview() {
  previewModal.value.show = false
}

function avatarColor(id) {
  return colors[((id || 1) - 1) % colors.length]
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}

// Czech pluralization for "pracovník": 1 pracovník, 2-4 pracovníci, 0/5+ pracovníků
function pluralWorkers(n) {
  if (n === 1) return 'pracovník'
  if (n >= 2 && n <= 4) return 'pracovníci'
  return 'pracovníků'
}

function pluralPlaces(n) {
  if (n === 1) return 'provozovna'
  if (n >= 2 && n <= 4) return 'provozovny'
  return 'provozoven'
}
</script>

<template>
  <div>
    <!-- Loading state -->
    <div v-if="loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám personál...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Empty state — onboarding hero -->
    <div v-else-if="personnelByLocation.length === 0" id="personnel-onboarding" class="onboarding-hero">
      <div class="onboarding-hero-icon">
        <Users :size="28" aria-hidden="true" />
      </div>
      <h2 class="onboarding-hero-title">Brzy se seznámíte se svým týmem</h2>
      <p class="onboarding-hero-desc">
        Jakmile vám přiřadíme úklidové pracovníky, uvidíte tu jejich profily –
        fotku, zkušenosti a jméno. Budete přesně vědět, kdo u vás uklízí.
      </p>
      <div class="personnel-onboarding-perks">
        <div class="personnel-perk">
          <Sparkles :size="14" aria-hidden="true" />
          <span>Foto a představení pracovníka</span>
        </div>
        <div class="personnel-perk">
          <Sparkles :size="14" aria-hidden="true" />
          <span>Zkušenosti a délka spolupráce</span>
        </div>
      </div>
    </div>

    <!-- Content -->
    <template v-else>
    <!-- Page header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Personál</h1>
        <p class="page-subtitle">Pracovníci přiřazení na vaše provozovny</p>
      </div>
      <div class="badge badge-info personnel-total-badge">
        {{ totalStaff }} {{ pluralWorkers(totalStaff) }}
      </div>
    </div>

    <!-- IČO tabs -->
    <div class="ico-tabs" v-if="personnelByLocation.length > 1">
      <button
        v-for="group in personnelByLocation"
        :key="group.ico"
        class="ico-tab"
        :class="{ active: activeIco === group.ico }"
        @click="activeIco = group.ico"
      >
        <span class="ico-tab-name">{{ group.icoName }}</span>
        <span class="ico-tab-ico">IČO {{ group.ico }}</span>
        <span class="ico-tab-badge">
          {{ group.objects.reduce((s, o) => s + o.staff.length, 0) }} {{ pluralWorkers(group.objects.reduce((s, o) => s + o.staff.length, 0)) }} ·
          {{ group.objects.length }} {{ pluralPlaces(group.objects.length) }}
        </span>
      </button>
    </div>

    <!-- Objects within the selected IČO -->
    <div class="objects-list">
      <section
        v-for="obj in activeGroup.objects"
        :key="obj.id"
        class="object-section"
      >
        <!-- Object header -->
        <div class="object-header">
          <div class="object-title-wrap">
            <h2 class="object-name">{{ obj.name || 'Vaše provozovna' }}</h2>
            <div v-if="obj.address" class="object-address">
              <MapPin :size="13" />
              {{ obj.address }}
            </div>
          </div>
          <span class="badge badge-gray">{{ obj.staff.length }} {{ pluralWorkers(obj.staff.length) }}</span>
        </div>

        <!-- Empty object -->
        <div v-if="obj.staff.length === 0" class="inline-empty object-inline-empty">
          <span class="inline-empty-icon">
            <Users :size="22" aria-hidden="true" />
          </span>
          <span class="inline-empty-title">Tým ještě nebyl přiřazen</span>
          <span class="inline-empty-desc">
            Jakmile sem nasadíme úklidového pracovníka, uvidíte tu jeho profil.
          </span>
        </div>

        <!-- Staff grid -->
        <div v-else class="personnel-grid">
          <div
            v-for="person in obj.staff"
            :key="person.id"
            class="card person-card"
          >
            <div class="person-header">
              <div
                class="avatar avatar-lg person-avatar"
                :class="{ 'clickable': person.photoUrl }"
                :style="person.photoUrl ? {} : { background: avatarColor(person.id) }"
                @click="person.photoUrl && openPreview(person.photoUrl, person.name)"
              >
                <img v-if="person.photoUrl" :src="person.photoUrl" :alt="person.name" class="avatar-img" />
                <span v-else>{{ initials(person.name) }}</span>
              </div>
              <div class="person-meta">
                <h3 class="person-name">{{ person.name }}</h3>
                <div v-if="person.showRole" class="badge badge-info person-role">
                  {{ person.role }}
                </div>
              </div>
            </div>

            <div class="person-details">
              <div v-if="person.showTenure" class="detail-row">
                <Clock :size="14" class="detail-icon" />
                <span>{{ person.tenure }}</span>
              </div>
              <!-- MVP: phone is never shown to clients -->
            </div>

            <hr v-if="person.showBio || person.showHobbies" class="divider" />

            <div v-if="person.showBio && person.bio" class="person-bio">
              <BookOpen :size="13" style="color:var(--color-mid); flex-shrink:0; margin-top:2px;" />
              <p>{{ person.bio }}</p>
            </div>

            <div v-if="person.showHobbies && person.hobbies" class="person-hobbies">
              <Star :size="13" style="color:var(--color-mid); flex-shrink:0; margin-top:2px;" />
              <p>{{ person.hobbies }}</p>
            </div>
          </div>
        </div>
      </section>
    </div>

    <p class="gdpr-note">
      Zobrazené informace jsou sdíleny se souhlasem pracovníků v souladu se zásadami ochrany osobních údajů.
    </p>
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
/* IČO tabs */
.ico-tabs {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 28px;
}

.ico-tab {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  padding: 12px 18px;
  border-radius: var(--radius-lg);
  border: 2px solid var(--color-gray-200);
  background: white;
  cursor: pointer;
  transition: var(--transition);
  text-align: left;
  gap: 2px;
  width: 100%;
}
@media (min-width: 640px) {
  .ico-tab {
    width: auto;
    min-width: 200px;
  }
}

.ico-tab:hover {
  border-color: var(--color-mid);
}

.ico-tab.active {
  border-color: var(--color-primary);
  background: var(--color-light);
}

.ico-tab-name {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-primary);
}

.ico-tab-ico {
  font-size: 12px;
  color: var(--color-gray-500);
  font-weight: 400;
}

.ico-tab-badge {
  margin-top: 4px;
  font-size: 11px;
  font-weight: 500;
  color: var(--color-mid);
}

/* Objects list */
.objects-list {
  display: flex;
  flex-direction: column;
  gap: 32px;
}

/* Object section */
.object-section {}

.object-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
  padding: 14px 18px;
  background: var(--color-gray-50);
  border-radius: var(--radius-lg);
  border-left: 4px solid var(--color-primary);
}

.object-title-wrap {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.object-name {
  font-size: 17px;
  font-weight: 700;
  color: var(--color-primary);
}

.object-address {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  color: var(--color-gray-600);
}

/* Staff grid — mobile-first: 1 col → 2 at sm → 3 at lg */
.personnel-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}
@media (min-width: 640px) {
  .personnel-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (min-width: 1280px) {
  .personnel-grid { grid-template-columns: repeat(3, 1fr); }
}

.person-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.person-header {
  display: flex;
  align-items: center;
  gap: 14px;
}

.person-avatar {
  flex-shrink: 0;
}

.person-meta {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.person-name {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-primary);
}

.person-role {
  font-size: 12px;
  align-self: flex-start;
}

.person-details {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.detail-row {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  color: var(--color-gray-700);
}

.detail-icon { color: var(--color-mid); flex-shrink: 0; }
.detail-link { color: var(--color-mid); }
.detail-link:hover { color: var(--color-primary); }

.person-bio,
.person-hobbies {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-600);
  line-height: 1.5;
}

.person-bio p,
.person-hobbies p { margin: 0; }

.gdpr-note {
  margin-top: 32px;
  font-size: 12px;
  color: var(--color-gray-500);
  text-align: center;
  line-height: 1.55;
}

.personnel-total-badge {
  font-size: 13px;
  padding: 6px 14px;
  align-self: flex-start;
}
@media (min-width: 640px) {
  .personnel-total-badge { align-self: auto; }
}

.personnel-onboarding-perks {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 14px;
  width: 100%;
  max-width: 380px;
}

.personnel-perk {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-700);
  padding: 8px 12px;
  background: var(--color-gray-50);
  border-radius: var(--radius-md);
}
.personnel-perk svg {
  color: var(--color-accent);
  flex-shrink: 0;
}

.object-inline-empty {
  background: var(--color-gray-50);
  border: 1px dashed var(--color-gray-300);
  border-radius: var(--radius-lg);
  padding: 26px 20px;
}

/* .personnel-grid + .ico-tab handled mobile-first above */
</style>
