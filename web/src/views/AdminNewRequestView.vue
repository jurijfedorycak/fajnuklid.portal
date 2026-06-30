<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  ClipboardPlus, Loader2, ChevronRight, Eye, Lock,
  Globe, MessageCircle, Phone, Users, Mail,
  AlertTriangle, Wrench, HelpCircle,
} from 'lucide-vue-next'
import {
  adminService,
  REQUEST_SOURCES,
  REQUEST_VISIBILITIES,
  REQUEST_CATEGORIES,
} from '../api'

const router = useRouter()

const sourceIconMap = { Globe, MessageCircle, Phone, Users, Mail }
const visibilityIconMap = { Eye, Lock }
const categoryIconMap = { AlertTriangle, Wrench, HelpCircle }

const clients = ref([])
const companies = ref([])
const loadingClients = ref(true)
const loadingCompanies = ref(false)

const selectedClientId = ref('')
const selectedCompanyId = ref(null)
const source = ref('phone')        // default channel — admin most often logs calls
const visibility = ref('client')
const recordDate = ref('')
const title = ref('')
const description = ref('')
const category = ref(null)

const submitting = ref(false)
const errors = ref({})

onMounted(async () => {
  try {
    const res = await adminService.getMaintenanceRequestFormOptions()
    if (res.success) clients.value = res.data.clients || []
  } finally {
    loadingClients.value = false
  }
})

// Load the chosen client's protistrany; reset any prior company selection.
watch(selectedClientId, async (clientId) => {
  selectedCompanyId.value = null
  companies.value = []
  if (!clientId) return
  loadingCompanies.value = true
  try {
    const res = await adminService.getMaintenanceRequestFormOptions(clientId)
    if (res.success) {
      companies.value = res.data.companies || []
      if (companies.value.length === 1) selectedCompanyId.value = companies.value[0].id
    }
  } finally {
    loadingCompanies.value = false
  }
})

const isValid = computed(() =>
  !!selectedClientId.value && !!title.value.trim() && !!description.value.trim()
)

// Local "today" as YYYY-MM-DD — caps the date picker; a record logs something that
// already happened, so it can never be in the future.
const todayStr = computed(() => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
})

function selectCategory(key) {
  category.value = category.value === key ? null : key
}

async function submit() {
  errors.value = {}
  if (!selectedClientId.value) errors.value.clientId = 'Vyberte klienta.'
  if (!title.value.trim()) errors.value.title = 'Zadejte název záznamu.'
  if (!description.value.trim()) errors.value.description = 'Vyplňte popis.'
  if (recordDate.value && recordDate.value > todayStr.value) errors.value.recordDate = 'Datum nemůže být v budoucnosti.'
  if (Object.keys(errors.value).length) return

  submitting.value = true
  try {
    const res = await adminService.createMaintenanceRequest({
      clientId: Number(selectedClientId.value),
      companyId: selectedCompanyId.value,
      source: source.value,
      visibility: visibility.value,
      recordDate: recordDate.value || null,
      title: title.value.trim(),
      description: description.value.trim(),
      category: category.value,
    })
    if (!res.success) {
      applyBackendErrors(res.errors, res.message)
      submitting.value = false
      return
    }
    router.push(`/admin/zadosti/${res.data.id}`)
  } catch (e) {
    applyBackendErrors(e.response?.data?.errors, e.response?.data?.message || e.message)
    submitting.value = false
  }
}

// Map backend validation errors onto the form. Fields with a dedicated inline slot show
// there; anything else (or a bare message) falls back to the page-level alert so a
// rejection is never silently swallowed.
const INLINE_ERROR_FIELDS = ['clientId', 'title', 'description', 'recordDate', 'companyId']
function applyBackendErrors(backendErrors, message) {
  const errs = backendErrors || {}
  errors.value = { ...errs }
  const hasUnmapped = Object.keys(errs).some(k => !INLINE_ERROR_FIELDS.includes(k))
  if (!Object.keys(errs).length || hasUnmapped) {
    errors.value._ = errs._ || message || 'Nepodařilo se vytvořit záznam.'
  }
}
</script>

<template>
  <div>
    <!-- Breadcrumb -->
    <nav id="admin-new-request-breadcrumb" class="breadcrumb" aria-label="breadcrumb">
      <a id="admin-new-request-bc-admin" href="#" @click.prevent="router.push('/admin')">Admin</a>
      <ChevronRight :size="13" class="breadcrumb-sep" />
      <a id="admin-new-request-bc-list" href="#" @click.prevent="router.push('/admin/zadosti')">Žádosti</a>
      <ChevronRight :size="13" class="breadcrumb-sep" />
      <span id="admin-new-request-bc-current" class="breadcrumb-current">Nový záznam</span>
    </nav>

    <div id="admin-new-request-header" class="page-header">
      <div>
        <h1 id="admin-new-request-title" class="page-title">
          <ClipboardPlus :size="22" style="vertical-align:-4px; margin-right:8px; color:var(--color-mid);" />
          Nový záznam / požadavek
        </h1>
        <p id="admin-new-request-subtitle" class="page-subtitle">
          Zaznamenejte požadavek nebo informaci, kterou klient sdělil mimo portál (WhatsApp, telefon, osobně).
        </p>
      </div>
    </div>

    <div id="admin-new-request-form" class="card">
      <!-- Client -->
      <div class="form-group">
        <label class="form-label" for="admin-new-request-client">Klient <span class="req">*</span></label>
        <div v-if="loadingClients" class="inline-loading">
          <Loader2 :size="16" class="spin" /><span>Načítám klienty…</span>
        </div>
        <select
          v-else
          id="admin-new-request-client"
          v-model="selectedClientId"
          class="form-input"
        >
          <option value="">Vyberte klienta…</option>
          <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <div v-if="errors.clientId" class="field-error">{{ errors.clientId }}</div>
      </div>

      <!-- Protistrana (optional, depends on client) -->
      <div v-if="selectedClientId" class="form-group">
        <label class="form-label">Protistrana (IČO) <span class="opt">(volitelné)</span></label>
        <div v-if="loadingCompanies" class="inline-loading">
          <Loader2 :size="16" class="spin" /><span>Načítám provozovny…</span>
        </div>
        <div v-else-if="companies.length === 0" class="hint-text">Tento klient nemá žádné protistrany.</div>
        <div v-else id="admin-new-request-companies" class="chip-group">
          <button
            v-for="c in companies"
            :key="c.id"
            :id="'admin-new-request-company-' + c.id"
            type="button"
            class="chip"
            :class="{ active: selectedCompanyId === c.id }"
            @click="selectedCompanyId = selectedCompanyId === c.id ? null : c.id"
          >
            {{ c.name }}<span v-if="c.ico" class="chip-ico">· IČO {{ c.ico }}</span>
          </button>
        </div>
        <div v-if="errors.companyId" class="field-error">{{ errors.companyId }}</div>
      </div>

      <!-- Channel / source -->
      <div class="form-group">
        <label class="form-label">Kanál <span class="req">*</span></label>
        <div id="admin-new-request-sources" class="source-grid">
          <button
            v-for="s in REQUEST_SOURCES"
            :key="s.key"
            :id="'admin-new-request-source-' + s.key"
            type="button"
            class="source-card"
            :class="{ active: source === s.key }"
            @click="source = s.key"
          >
            <component :is="sourceIconMap[s.icon]" :size="20" />
            <span>{{ s.label }}</span>
          </button>
        </div>
      </div>

      <!-- Visibility -->
      <div class="form-group">
        <label class="form-label">Viditelnost <span class="req">*</span></label>
        <div id="admin-new-request-visibility" class="visibility-toggle">
          <button
            v-for="v in REQUEST_VISIBILITIES"
            :key="v.key"
            :id="'admin-new-request-visibility-' + v.key"
            type="button"
            class="visibility-option"
            :class="{ active: visibility === v.key, 'is-internal': v.key === 'internal' }"
            @click="visibility = v.key"
          >
            <component :is="visibilityIconMap[v.icon]" :size="15" />
            <span>{{ v.label }}</span>
          </button>
        </div>
        <p class="hint-text">
          <template v-if="visibility === 'internal'">Záznam uvidí pouze tým Fajn Úklid, klientovi se v portálu nezobrazí.</template>
          <template v-else>Záznam se klientovi zobrazí mezi jeho žádostmi v portálu.</template>
        </p>
      </div>

      <!-- Record date -->
      <div class="form-group">
        <label class="form-label" for="admin-new-request-date">Datum záznamu <span class="opt">(volitelné)</span></label>
        <input
          id="admin-new-request-date"
          v-model="recordDate"
          type="date"
          class="form-input date-input"
          :max="todayStr"
        />
        <p class="hint-text">Pokud vyplníte, záznam se zobrazí v docházce na tento den. Jinak se použije dnešní datum.</p>
        <div v-if="errors.recordDate" class="field-error">{{ errors.recordDate }}</div>
      </div>

      <!-- Title -->
      <div class="form-group">
        <label class="form-label" for="admin-new-request-title-input">Název <span class="req">*</span></label>
        <input
          id="admin-new-request-title-input"
          v-model="title"
          class="form-input"
          type="text"
          placeholder="Např. Telefonicky nahlášený požadavek na úklid skladu"
        />
        <div v-if="errors.title" class="field-error">{{ errors.title }}</div>
      </div>

      <!-- Description -->
      <div class="form-group">
        <label class="form-label" for="admin-new-request-description">Popis <span class="req">*</span></label>
        <textarea
          id="admin-new-request-description"
          v-model="description"
          class="form-input"
          rows="6"
          placeholder="Co klient sdělil, na čem jste se domluvili…"
        ></textarea>
        <div v-if="errors.description" class="field-error">{{ errors.description }}</div>
      </div>

      <!-- Category (optional) -->
      <div class="form-group">
        <label class="form-label">Kategorie <span class="opt">(volitelné)</span></label>
        <div id="admin-new-request-categories" class="category-grid">
          <button
            v-for="c in REQUEST_CATEGORIES"
            :key="c.key"
            :id="'admin-new-request-cat-' + c.key"
            type="button"
            class="category-card"
            :class="{ active: category === c.key }"
            @click="selectCategory(c.key)"
          >
            <component :is="categoryIconMap[c.icon]" :size="20" />
            <span>{{ c.label }}</span>
          </button>
        </div>
      </div>

      <div v-if="errors._" id="admin-new-request-error" class="alert alert-danger" style="margin-bottom:16px;">{{ errors._ }}</div>

      <div id="admin-new-request-actions" class="form-actions">
        <button id="admin-new-request-cancel" class="btn btn-outline" @click="router.push('/admin/zadosti')">Zrušit</button>
        <button
          id="admin-new-request-submit"
          class="btn btn-primary"
          :disabled="!isValid || submitting"
          @click="submit"
        >
          <Loader2 v-if="submitting" :size="16" class="spin" />
          <ClipboardPlus v-else :size="16" />
          <span>{{ submitting ? 'Ukládám…' : 'Vytvořit záznam' }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.breadcrumb {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--color-gray-500);
  margin-bottom: 14px;
}
.breadcrumb a { color: var(--color-gray-500); text-decoration: none; transition: var(--transition); }
.breadcrumb a:hover { color: var(--color-primary); }
.breadcrumb-sep { color: var(--color-gray-400); flex-shrink: 0; }
.breadcrumb-current { color: var(--color-primary); font-weight: 500; }

.req { color: var(--color-danger); }
.opt { color: var(--color-gray-500); font-weight: 400; }

.inline-loading {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-500);
}

.hint-text {
  font-size: 12px;
  color: var(--color-gray-500);
  margin: 6px 0 0;
  line-height: 1.5;
}

.field-error {
  font-size: 12px;
  color: var(--color-danger);
  margin-top: 4px;
}

.chip-ico { opacity: .7; margin-left: 6px; }

/* Source picker — mobile-first 2 cols → auto-fit on wider screens */
.source-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
}
@media (min-width: 640px) {
  .source-grid { grid-template-columns: repeat(5, 1fr); }
}
.source-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 16px 10px;
  background: var(--color-white);
  border: 1.5px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  color: var(--color-gray-700);
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition);
}
.source-card:hover { border-color: var(--color-mid); color: var(--color-primary); }
.source-card.active {
  border-color: var(--color-primary);
  background: var(--color-light);
  color: var(--color-primary);
}

/* Visibility segmented toggle */
.visibility-toggle {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
@media (min-width: 480px) {
  .visibility-toggle { display: flex; }
}
.visibility-option {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 16px;
  background: var(--color-white);
  border: 1.5px solid var(--color-gray-200);
  border-radius: var(--radius-md);
  color: var(--color-gray-700);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition);
}
@media (min-width: 480px) {
  .visibility-option { flex: 0 1 auto; }
}
.visibility-option:hover { border-color: var(--color-mid); color: var(--color-primary); }
.visibility-option.active {
  border-color: var(--color-primary);
  background: var(--color-light);
  color: var(--color-primary);
}
.visibility-option.is-internal.active {
  border-color: var(--color-warning);
  background: var(--color-warning-light);
  color: var(--color-warning);
}

.date-input { width: auto; min-width: 180px; }

/* Category picker — mirrors client NewRequestView */
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
  padding: 20px 12px;
  background: var(--color-white);
  border: 1.5px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  color: var(--color-gray-700);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition);
}
.category-card:hover { border-color: var(--color-mid); color: var(--color-primary); }
.category-card.active {
  border-color: var(--color-primary);
  background: var(--color-light);
  color: var(--color-primary);
}

.form-actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
}

.spin { animation: spin 1.5s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
