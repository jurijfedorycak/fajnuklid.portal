<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Plus, Zap, Droplet, Wind, Sparkles, Key, HelpCircle, Loader2 } from 'lucide-vue-next'
import { maintenanceRequestService, REQUEST_CATEGORIES } from '../api'

const router = useRouter()

const iconMap = { Zap, Droplet, Wind, Sparkles, Key, HelpCircle }

const title = ref('')
const category = ref(null)
const locationType = ref(null)
const locationValue = ref('')
const selectedCompanyId = ref(null)
const customLocation = ref('')
const description = ref('')
const submitting = ref(false)
const loadingOptions = ref(true)
const errors = ref({})

const officeOptions = ref([])
const commonOptions = ['WC', 'Chodba', 'Kuchyňka', 'Recepce', 'Výtah', 'Schodiště', 'Parkování']

onMounted(async () => {
  try {
    const res = await maintenanceRequestService.getFormOptions()
    if (res.success) {
      officeOptions.value = res.data.offices || []
    }
  } finally {
    loadingOptions.value = false
  }
})

function selectCategory(key) {
  category.value = key
}

function selectOffice(office) {
  locationType.value = 'office'
  locationValue.value = `Kancelář: ${office.name}`
  selectedCompanyId.value = office.companyId
  customLocation.value = ''
}

function selectCommon(value) {
  locationType.value = 'common'
  locationValue.value = value
  selectedCompanyId.value = null
  customLocation.value = ''
}

function onCustomInput() {
  if (customLocation.value.trim()) {
    locationType.value = 'custom'
    locationValue.value = customLocation.value.trim()
  } else if (locationType.value === 'custom') {
    locationType.value = null
    locationValue.value = ''
  }
}

const isValid = computed(() => title.value.trim() && category.value && locationValue.value)

async function submit() {
  errors.value = {}
  if (!title.value.trim()) errors.value.title = 'Zadejte název problému'
  if (!category.value) errors.value.category = 'Vyberte kategorii'
  if (!locationValue.value) errors.value.location = 'Vyberte místo'
  if (Object.keys(errors.value).length) return

  submitting.value = true
  try {
    const res = await maintenanceRequestService.create({
      title: title.value.trim(),
      category: category.value,
      locationType: locationType.value,
      locationValue: locationValue.value,
      companyId: selectedCompanyId.value,
      description: description.value.trim(),
    })
    if (res.success) router.push(`/zadosti/${res.data.id}`)
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
          Nová žádost
        </h1>
        <p id="new-request-subtitle" class="page-subtitle">Řekněte nám, co se stalo — hned se na to podíváme.</p>
      </div>
    </div>

    <div id="new-request-form" class="card" style="max-width:780px;">
      <!-- Title -->
      <div class="form-group">
        <label class="form-label" for="new-request-title-input">Název problému</label>
        <input
          id="new-request-title-input"
          v-model="title"
          class="form-input"
          type="text"
          placeholder="Např. Nefunguje klimatizace"
        />
        <div v-if="errors.title" class="field-error">{{ errors.title }}</div>
      </div>

      <!-- Categories -->
      <div class="form-group">
        <label class="form-label">Kategorie</label>
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
        <div v-if="errors.category" class="field-error">{{ errors.category }}</div>
      </div>

      <!-- Location -->
      <div class="form-group">
        <label class="form-label">Místo</label>

        <div class="loc-sublabel">Vaše kanceláře</div>
        <div v-if="loadingOptions" class="loc-loading">
          <Loader2 :size="16" class="spin" />
          <span>Načítám kanceláře…</span>
        </div>
        <div v-else-if="officeOptions.length === 0" class="loc-empty">
          K vašemu účtu nejsou přiřazeny žádné kanceláře.
        </div>
        <div v-else class="chip-group" style="margin-bottom:14px;">
          <button
            v-for="o in officeOptions"
            :key="o.id"
            type="button"
            :id="'loc-office-' + o.id"
            class="chip"
            :class="{ active: locationType === 'office' && selectedCompanyId === o.companyId && locationValue === `Kancelář: ${o.name}` }"
            @click="selectOffice(o)"
          >
            Kancelář: {{ o.name }}
          </button>
        </div>

        <div class="loc-sublabel">Společné prostory</div>
        <div class="chip-group" style="margin-bottom:14px;">
          <button
            v-for="o in commonOptions"
            :key="o"
            type="button"
            :id="'loc-common-' + o"
            class="chip"
            :class="{ active: locationType === 'common' && locationValue === o }"
            @click="selectCommon(o)"
          >
            {{ o }}
          </button>
        </div>

        <input
          id="new-request-location-custom"
          v-model="customLocation"
          class="form-input"
          type="text"
          placeholder="Nebo napište vlastní místo..."
          @input="onCustomInput"
        />
        <div v-if="errors.location" class="field-error">{{ errors.location }}</div>
      </div>

      <!-- Description -->
      <div class="form-group">
        <label class="form-label" for="new-request-description">Podrobný popis</label>
        <textarea
          id="new-request-description"
          v-model="description"
          class="form-input"
          rows="6"
          placeholder="Popište problém co nejpodrobněji..."
        ></textarea>
      </div>

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
          <span>{{ submitting ? 'Odesílám...' : 'Odeslat žádost' }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.category-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
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

.loc-sublabel {
  font-size: 12px;
  color: var(--color-gray-600);
  margin-bottom: 6px;
}

.loc-loading {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-500);
  margin-bottom: 14px;
}

.loc-empty {
  font-size: 13px;
  color: var(--color-gray-500);
  font-style: italic;
  margin-bottom: 14px;
}

.field-error {
  font-size: 12px;
  color: var(--color-danger);
  margin-top: 4px;
}

.spin { animation: spin 1.5s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 600px) {
  .category-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
