<script setup>
import { ref, computed, onMounted } from 'vue'
import { Clock, Star, BookOpen, Phone } from 'lucide-vue-next'
import { personnelService } from '../api'
import FilePreviewModal from '../components/FilePreviewModal.vue'
import EmptyRequestsIllustration from '../components/EmptyRequestsIllustration.vue'

const loading = ref(true)
const error = ref(null)
const personnelByLocation = ref([])

onMounted(async () => {
  try {
    const response = await personnelService.getPersonnel()
    if (response.success) {
      personnelByLocation.value = response.data || []
    } else {
      error.value = response.message || 'Nepodařilo se načíst data'
    }
  } catch (err) {
    error.value = err.message || 'Nepodařilo se načíst data'
  } finally {
    loading.value = false
  }
})

const companies = computed(() =>
  personnelByLocation.value.filter(g => g.icoName)
)

// Unpinned employees are returned once per location of a company — dedupe by id
const allStaff = computed(() => {
  const seen = new Set()
  const staff = []
  for (const group of personnelByLocation.value) {
    for (const obj of group.objects || []) {
      for (const person of obj.staff || []) {
        if (seen.has(person.id)) continue
        seen.add(person.id)
        staff.push(person)
      }
    }
  }
  return staff
})

const previewModal = ref({ show: false, url: '', filename: '' })
function openPreview(url, filename) {
  previewModal.value = { show: true, url, filename: filename || '' }
}
function closePreview() {
  previewModal.value.show = false
}

function avatarColorClass(id) {
  return 'person-avatar-c' + (((id || 1) - 1) % 4)
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}

// Czech pluralization: 1 pracovník, 2-4 pracovníci, 0/5+ pracovníků
function pluralWorkers(n) {
  if (n === 1) return 'pracovník'
  if (n >= 2 && n <= 4) return 'pracovníci'
  return 'pracovníků'
}
</script>

<template>
  <div id="personnel-page" class="page-shell page-shell--lg">
    <h1 id="personnel-title" class="personnel-title">Personál</h1>

    <!-- Loading skeleton -->
    <div v-if="loading" id="personnel-skeleton">
      <h2 class="personnel-section-label">Přiřazený tým</h2>
      <div class="personnel-chips">
        <span class="skeleton skeleton-chip skeleton-chip-wide"></span>
        <span class="skeleton skeleton-chip"></span>
      </div>
      <div class="personnel-list">
        <div v-for="i in 2" :key="i" class="person-card skeleton-card">
          <span class="skeleton skeleton-avatar"></span>
          <div class="skeleton-lines">
            <span class="skeleton skeleton-line skeleton-line-name"></span>
            <span class="skeleton skeleton-line skeleton-line-role"></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Error state -->
    <div v-else-if="error" id="personnel-error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Empty state -->
    <div v-else-if="allStaff.length === 0" id="personnel-empty" class="personnel-empty">
      <EmptyRequestsIllustration id="personnel-empty-art" class="personnel-empty-art" role="presentation" aria-hidden="true" />
      <h2 id="personnel-empty-title" class="personnel-empty-title">Tým se připravuje</h2>
      <p id="personnel-empty-text" class="personnel-empty-text">
        Váš stálý tým pro tuto budovu právě sestavujeme.
        Jakmile bude potvrzen, uvidíte zde jejich profily.
      </p>
    </div>

    <!-- Loaded content -->
    <template v-else>
      <h2 id="personnel-section-label" class="personnel-section-label">Přiřazený tým</h2>

      <div id="personnel-chips" class="personnel-chips">
        <span
          v-for="group in companies"
          :key="group.ico"
          :id="'personnel-chip-' + group.ico"
          class="personnel-chip personnel-chip-company"
        >
          {{ group.icoName }}
        </span>
        <span id="personnel-chip-count" class="personnel-chip personnel-chip-count">
          {{ allStaff.length }} {{ pluralWorkers(allStaff.length) }}
        </span>
      </div>

      <div id="personnel-list" class="personnel-list">
        <article
          v-for="person in allStaff"
          :key="person.id"
          :id="'person-card-' + person.id"
          class="person-card"
        >
          <div class="person-header">
            <div
              class="person-avatar"
              :class="[{ clickable: person.photoUrl }, person.photoUrl ? '' : avatarColorClass(person.id)]"
              :role="person.photoUrl ? 'button' : undefined"
              :tabindex="person.photoUrl ? 0 : undefined"
              :aria-label="person.photoUrl ? 'Zobrazit fotografii – ' + person.name : undefined"
              @click="person.photoUrl && openPreview(person.photoUrl, person.name)"
              @keydown.enter="person.photoUrl && openPreview(person.photoUrl, person.name)"
              @keydown.space.prevent="person.photoUrl && openPreview(person.photoUrl, person.name)"
            >
              <img v-if="person.photoUrl" :src="person.photoUrl" :alt="person.name" class="person-avatar-img" />
              <span v-else>{{ initials(person.name) }}</span>
            </div>
            <div class="person-meta">
              <h3 class="person-name">{{ person.name }}</h3>
              <span v-if="person.showRole && person.role" class="person-role-pill">{{ person.role }}</span>
            </div>
          </div>

          <div v-if="person.showTenure && person.tenure" class="person-tenure">
            <Clock :size="14" aria-hidden="true" />
            <span>{{ person.tenure }}</span>
          </div>

          <a
            v-if="person.showPhone && person.phone"
            :id="'person-phone-' + person.id"
            :href="'tel:' + person.phone.replace(/\s+/g, '')"
            class="person-phone"
          >
            <Phone :size="14" aria-hidden="true" />
            <span>{{ person.phone }}</span>
          </a>

          <div v-if="(person.showBio && person.bio) || (person.showHobbies && person.hobbies)" class="person-details">
            <div v-if="person.showBio && person.bio" class="person-detail-row">
              <BookOpen :size="14" aria-hidden="true" />
              <p>{{ person.bio }}</p>
            </div>
            <div v-if="person.showHobbies && person.hobbies" class="person-detail-row">
              <Star :size="14" aria-hidden="true" />
              <p>{{ person.hobbies }}</p>
            </div>
          </div>
        </article>
      </div>

      <p id="personnel-gdpr-note" class="personnel-gdpr-note">
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
.personnel-title {
  font-size: var(--fs-2xl);
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.2;
  margin-bottom: 18px;
}

.personnel-section-label {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-gray-400);
  margin-bottom: 10px;
}

/* ═══ Chips ═══ */
.personnel-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 18px;
}

.personnel-chip {
  display: inline-flex;
  align-items: center;
  padding: 6px 12px;
  border-radius: var(--radius-pill);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  white-space: nowrap;
}

.personnel-chip-company {
  background: var(--color-primary);
  color: var(--color-white);
}

.personnel-chip-count {
  background: var(--color-gray-100);
  color: var(--color-gray-600);
}

/* ═══ Staff cards ═══ */
.personnel-list {
  display: grid;
  grid-template-columns: 1fr;
  gap: 14px;
  max-width: 720px;
}
@media (min-width: 1024px) {
  .personnel-list {
    grid-template-columns: repeat(2, 1fr);
    max-width: none;
  }
}

.person-card {
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-xl);
  padding: 16px 18px 18px;
  box-shadow: var(--shadow-sm);
}

.person-header {
  display: flex;
  align-items: center;
  gap: 14px;
}

.person-avatar {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  overflow: hidden;
  flex-shrink: 0;
  color: var(--color-white);
  font-size: 18px;
  font-weight: 600;
}
.person-avatar.clickable {
  cursor: pointer;
}

.person-avatar-c0 { background: var(--color-primary); }
.person-avatar-c1 { background: var(--color-accent); }
.person-avatar-c2 { background: var(--color-blue); }
.person-avatar-c3 { background: var(--color-success); }

.person-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.person-meta {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  min-width: 0;
}

.person-name {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-primary);
  line-height: 1.3;
}

.person-role-pill {
  padding: 4px 10px;
  border-radius: var(--radius-sm);
  background: var(--color-blue-light);
  color: var(--color-blue);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.person-tenure {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-top: 12px;
  font-size: 13px;
  color: var(--color-gray-500);
}
.person-tenure svg {
  color: var(--color-gray-400);
  flex-shrink: 0;
}

.person-phone {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  margin-top: 12px;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-blue);
  text-decoration: none;
  transition: color 0.15s ease;
}
.person-phone svg {
  color: var(--color-blue);
  flex-shrink: 0;
}
.person-phone:hover {
  color: var(--color-primary);
}

.person-details {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--color-gray-100);
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.person-detail-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: 13px;
  line-height: 1.5;
  color: var(--color-gray-600);
}
.person-detail-row svg {
  color: var(--color-gray-400);
  flex-shrink: 0;
  margin-top: 2px;
}
.person-detail-row p {
  margin: 0;
}

/* ═══ Loading skeleton ═══ */
.skeleton-chip {
  width: 88px;
  height: 26px;
  border-radius: var(--radius-pill);
}
.skeleton-chip-wide {
  width: 148px;
}

.skeleton-card {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  min-height: 132px;
}

.skeleton-avatar {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  flex-shrink: 0;
}

.skeleton-lines {
  display: flex;
  flex-direction: column;
  gap: 10px;
  flex: 1;
}

.skeleton-line {
  height: 14px;
  border-radius: var(--radius-sm);
}
.skeleton-line-name {
  width: 70%;
}
.skeleton-line-role {
  width: 45%;
}

/* ═══ Empty state ═══ */
.personnel-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 40px 8px 24px;
}
@media (min-width: 640px) {
  .personnel-empty {
    padding: 64px 24px 40px;
  }
}

.personnel-empty-art {
  width: 218px;
  height: auto;
  margin-bottom: 28px;
}

.personnel-empty-title {
  font-size: 20px;
  font-weight: 700;
  color: var(--color-primary);
  margin-bottom: 10px;
}

.personnel-empty-text {
  font-size: 15px;
  line-height: 1.55;
  color: var(--color-gray-500);
  max-width: 34ch;
}

.personnel-gdpr-note {
  margin-top: 28px;
  font-size: 12px;
  color: var(--color-gray-500);
  text-align: center;
  line-height: 1.55;
  max-width: 720px;
  margin-inline: auto;
}
</style>
