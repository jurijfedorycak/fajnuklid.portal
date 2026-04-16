<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import {
  Users, Plus, Search, Trash2, Upload, FileText, Download,
  ChevronDown, ChevronUp, Eye, EyeOff, User, Save, CheckCircle2, Loader2, Lightbulb, X,
} from 'lucide-vue-next'
import { adminService } from '../api'
import FilePreviewModal from '../components/FilePreviewModal.vue'
import { extractFilename, downloadFile } from '../utils/fileUtils'

// ── State ────────────────────────────────────────────────────────────────────
function uid() { return crypto.randomUUID() }

const loading = ref(true)
const loadError = ref(null)
const saveError = ref(null)
const employees = ref([])
const searchQuery = ref('')
const saving = ref(false)
const saved = ref(false)

// Validation state
const validationErrors = ref(new Map())

// Delete confirmation modal
const deleteConfirm = ref({ show: false, empId: null, empName: '' })

// Toast
const toast = ref(null)
let toastTimer = null
function showToast(type, message) {
  toast.value = { type, message }
  if (toastTimer) clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toast.value = null }, 3000)
}

// File preview modal
const previewModal = ref({ show: false, url: '', filename: '', mimeType: '' })
function openPreview(url, filename) {
  previewModal.value = { show: true, url, filename, mimeType: '' }
}
function closePreview() {
  previewModal.value.show = false
}

// Map API response (snake_case) to frontend (camelCase)
function mapEmployeeFromApi(e) {
  return {
    id: e.id || uid(),
    firstName: e.first_name || '',
    lastName: e.last_name || '',
    role: e.position || '',
    phone: e.phone || '',
    personalId: e.personal_id || '',
    tenureText: e.tenure_text || '',
    bio: e.bio || '',
    hobbies: e.hobbies || '',
    photo: e.photo_url ? extractFilename(e.photo_url) : null,
    photoUrl: e.photo_url || null,
    contractFile: e.contract_file || null,
    contractOriginalName: e.contract_file ? extractFilename(e.contract_file) : null,
    uploadingPhoto: false,
    uploadingContract: false,
    showInPortal: !!e.show_in_portal,
    showPhoto: !!e.show_photo,
    showPhone: !!e.show_phone,
    showRole: e.show_role !== false,
    showHobbies: !!e.show_hobbies,
    showTenure: e.show_tenure !== false,
    showBio: !!e.show_bio,
    expanded: false,
  }
}

// Fetch employees
onMounted(async () => {
  // Set up beforeunload listener for unsaved changes warning
  window.addEventListener('beforeunload', handleBeforeUnload)

  try {
    const response = await adminService.getEmployees()
    if (response.success) {
      employees.value = (response.data || []).map(mapEmployeeFromApi)
    } else {
      loadError.value = response.message || 'Nepodařilo se načíst zaměstnance'
    }
  } catch (err) {
    loadError.value = err.message || 'Nepodařilo se načíst zaměstnance'
  } finally {
    loading.value = false
  }
})

const filtered = computed(() => {
  const q = searchQuery.value.toLowerCase()
  if (!q) return employees.value
  return employees.value.filter(e =>
    `${e.firstName} ${e.lastName}`.toLowerCase().includes(q) ||
    (e.role || '').toLowerCase().includes(q) ||
    (e.phone || '').includes(q)
  )
})

const stats = computed(() => ({
  total: employees.value.length,
  visible: employees.value.filter(e => e.showInPortal).length,
  hidden: employees.value.filter(e => !e.showInPortal).length,
  withContract: employees.value.filter(e => e.contractFile).length,
}))

// ── Validation ───────────────────────────────────────────────────────────────
function validateEmployee(emp) {
  const errors = {}
  if (!emp.firstName?.trim()) errors.firstName = 'Jméno je povinné'
  if (!emp.lastName?.trim()) errors.lastName = 'Příjmení je povinné'
  return errors
}

function validateAll() {
  validationErrors.value.clear()
  let valid = true
  for (const emp of employees.value) {
    const errors = validateEmployee(emp)
    if (Object.keys(errors).length > 0) {
      validationErrors.value.set(emp.id, errors)
      valid = false
    }
  }
  return valid
}

function clearFieldError(empId, field) {
  const errors = validationErrors.value.get(empId)
  if (errors && errors[field]) {
    delete errors[field]
    if (Object.keys(errors).length === 0) {
      validationErrors.value.delete(empId)
    }
  }
}

// ── Actions ──────────────────────────────────────────────────────────────────
function addEmployee() {
  employees.value.unshift({
    id: uid(),
    firstName: '',
    lastName: '',
    role: '',
    phone: '',
    personalId: '',
    tenureText: '',
    bio: '',
    hobbies: '',
    photo: null,
    photoUrl: null,
    contractFile: null,
    contractOriginalName: null,
    uploadingPhoto: false,
    uploadingContract: false,
    showInPortal: false,
    showPhoto: false,
    showPhone: false,
    showRole: true,
    showHobbies: false,
    showTenure: true,
    showBio: false,
    expanded: true,
  })
}

function confirmDelete(emp) {
  deleteConfirm.value = {
    show: true,
    empId: emp.id,
    empName: `${emp.firstName} ${emp.lastName}`.trim() || 'Nový zaměstnanec'
  }
}

function executeDelete() {
  employees.value = employees.value.filter(e => e.id !== deleteConfirm.value.empId)
  validationErrors.value.delete(deleteConfirm.value.empId)
  deleteConfirm.value = { show: false, empId: null, empName: '' }
}

function cancelDelete() {
  deleteConfirm.value = { show: false, empId: null, empName: '' }
}

// Focus management for modal
watch(() => deleteConfirm.value.show, async (isOpen) => {
  if (isOpen) {
    await nextTick()
    const cancelBtn = document.getElementById('emp-delete-cancel-btn')
    cancelBtn?.focus()
  }
})

function handleModalKeydown(event) {
  if (event.key === 'Escape') {
    cancelDelete()
  }
}

async function handlePhotoUpload(emp, event) {
  const file = event.target.files?.[0]
  if (!file || emp.uploadingPhoto) return
  event.target.value = ''
  emp.uploadingPhoto = true
  const prevPhoto = emp.photo
  const prevUrl = emp.photoUrl
  emp.photo = file.name
  try {
    const entity = emp.id > 0 ? { type: 'employee', id: emp.id, field: 'photo_url' } : null
    const url = await adminService.uploadFile(file, 'employee-photos', entity)
    if (url) {
      emp.photoUrl = url
      if (entity) captureInitialState()
      showToast('success', 'Fotografie nahrána')
    } else {
      emp.photo = prevPhoto
      emp.photoUrl = prevUrl
      saveError.value = 'Nahrání fotografie selhalo'
    }
  } catch (err) {
    emp.photo = prevPhoto
    emp.photoUrl = prevUrl
    saveError.value = err.response?.data?.message || err.message || 'Nahrání fotografie selhalo'
  } finally {
    emp.uploadingPhoto = false
  }
}

async function handleContractUpload(emp, event) {
  const file = event.target.files?.[0]
  if (!file || emp.uploadingContract) return
  event.target.value = ''
  emp.uploadingContract = true
  const prevFile = emp.contractFile
  const prevName = emp.contractOriginalName
  emp.contractOriginalName = file.name
  try {
    const entity = emp.id > 0 ? { type: 'employee', id: emp.id, field: 'contract_file' } : null
    const url = await adminService.uploadFile(file, 'employee-contracts', entity)
    if (url) {
      emp.contractFile = url
      if (entity) captureInitialState()
      showToast('success', 'Smlouva nahrána')
    } else {
      emp.contractFile = prevFile
      emp.contractOriginalName = prevName
      saveError.value = 'Nahrání smlouvy selhalo'
    }
  } catch (err) {
    emp.contractFile = prevFile
    emp.contractOriginalName = prevName
    saveError.value = err.response?.data?.message || err.message || 'Nahrání smlouvy selhalo'
  } finally {
    emp.uploadingContract = false
  }
}

async function removePhoto(emp) {
  const prevPhoto = emp.photo
  const prevUrl = emp.photoUrl
  emp.photo = null
  emp.photoUrl = null
  if (emp.id > 0) {
    try {
      await adminService.removeFile('employee', emp.id, 'photo_url')
      captureInitialState()
      showToast('success', 'Fotografie odebrána')
    } catch {
      emp.photo = prevPhoto
      emp.photoUrl = prevUrl
      saveError.value = 'Odebrání fotografie selhalo'
    }
  }
}

async function removeContract(emp) {
  const prevFile = emp.contractFile
  const prevName = emp.contractOriginalName
  emp.contractFile = null
  emp.contractOriginalName = null
  if (emp.id > 0) {
    try {
      await adminService.removeFile('employee', emp.id, 'contract_file')
      captureInitialState()
      showToast('success', 'Smlouva odebrána')
    } catch {
      emp.contractFile = prevFile
      emp.contractOriginalName = prevName
      saveError.value = 'Odebrání smlouvy selhalo'
    }
  }
}

async function save() {
  saveError.value = null

  if (!validateAll()) {
    const firstInvalid = employees.value.find(e => validationErrors.value.has(e.id))
    if (firstInvalid) firstInvalid.expanded = true
    return
  }

  saving.value = true
  try {
    const response = await adminService.saveEmployees(employees.value.map(e => ({
      id: e.id,
      firstName: e.firstName,
      lastName: e.lastName,
      role: e.role,
      phone: e.phone,
      personalId: e.personalId,
      tenureText: e.tenureText,
      bio: e.bio,
      hobbies: e.hobbies,
      photo: e.photo,
      photoUrl: e.photoUrl,
      contractFile: e.contractFile,
      showInPortal: e.showInPortal,
      showPhoto: e.showPhoto,
      showPhone: e.showPhone,
      showRole: e.showRole,
      showHobbies: e.showHobbies,
      showTenure: e.showTenure,
      showBio: e.showBio,
    })))
    if (response.success) {
      saved.value = true
      setTimeout(() => { saved.value = false }, 3000)
    } else {
      saveError.value = response.message || 'Uložení se nezdařilo'
    }
  } catch (err) {
    saveError.value = err.message || 'Uložení se nezdařilo'
  } finally {
    saving.value = false
  }
}

function initials(emp) {
  const f = emp.firstName?.[0] || ''
  const l = emp.lastName?.[0] || ''
  return (f + l).toUpperCase() || '?'
}

function toggleCount(emp) {
  let c = 0
  if (emp.showPhoto) c++
  if (emp.showPhone) c++
  if (emp.showRole) c++
  if (emp.showHobbies) c++
  if (emp.showTenure) c++
  if (emp.showBio) c++
  return c
}

function clearSearch() {
  searchQuery.value = ''
}

// ── Unsaved changes tracking ──────────────────────────────────────────────────
const initialEmployeesState = ref(null)
const isDirty = computed(() => {
  if (!initialEmployeesState.value) return false
  const currentState = JSON.stringify(employees.value.map(e => ({
    id: e.id,
    firstName: e.firstName,
    lastName: e.lastName,
    role: e.role,
    phone: e.phone,
    personalId: e.personalId,
    tenureText: e.tenureText,
    bio: e.bio,
    hobbies: e.hobbies,
    photo: e.photo,
    contractFile: e.contractFile,
    showInPortal: e.showInPortal,
    showPhoto: e.showPhoto,
    showPhone: e.showPhone,
    showRole: e.showRole,
    showHobbies: e.showHobbies,
    showTenure: e.showTenure,
    showBio: e.showBio,
  })))
  return currentState !== initialEmployeesState.value
})

// Capture initial state after data loads
watch(loading, (isLoading) => {
  if (!isLoading && !initialEmployeesState.value) {
    captureInitialState()
  }
})

function captureInitialState() {
  initialEmployeesState.value = JSON.stringify(employees.value.map(e => ({
    id: e.id,
    firstName: e.firstName,
    lastName: e.lastName,
    role: e.role,
    phone: e.phone,
    personalId: e.personalId,
    tenureText: e.tenureText,
    bio: e.bio,
    hobbies: e.hobbies,
    photo: e.photo,
    contractFile: e.contractFile,
    showInPortal: e.showInPortal,
    showPhoto: e.showPhoto,
    showPhone: e.showPhone,
    showRole: e.showRole,
    showHobbies: e.showHobbies,
    showTenure: e.showTenure,
    showBio: e.showBio,
  })))
}

// Update initial state after successful save
watch(saved, (wasSaved) => {
  if (wasSaved) {
    captureInitialState()
  }
})

// Navigation guard for unsaved changes
onBeforeRouteLeave((to, from) => {
  if (isDirty.value) {
    const answer = window.confirm('Máte neuložené změny. Opravdu chcete odejít?')
    if (!answer) return false
  }
})

// Browser beforeunload event for page refresh/close
function handleBeforeUnload(e) {
  if (isDirty.value) {
    e.preventDefault()
    e.returnValue = ''
  }
}

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
})
</script>

<template>
  <div id="emp-page">
    <!-- Top bar -->
    <div id="emp-page-header" class="page-header">
      <div>
        <h1 id="emp-page-title" class="page-title emp-page-title">
          <Users :size="24" class="text-mid" aria-hidden="true" />
          Zaměstnanci
        </h1>
        <p id="emp-page-subtitle" class="page-subtitle">Admin sekce · správa zaměstnanců, pracovní smlouvy, GDPR viditelnost v portálu</p>
      </div>
      <div id="emp-top-actions" class="top-actions">
        <button id="emp-add-btn" class="btn btn-primary" :disabled="saving" @click="addEmployee">
          <Plus :size="16" aria-hidden="true" /> Přidat zaměstnance
        </button>
      </div>
    </div>

    <!-- Stats -->
    <div id="emp-stats" class="emp-stats">
      <div id="emp-stats-total" class="card emp-stat">
        <div class="stat-num">{{ stats.total }}</div>
        <div class="stat-lbl">Celkem</div>
      </div>
      <div id="emp-stats-visible" class="card emp-stat">
        <div class="stat-num text-success">{{ stats.visible }}</div>
        <div class="stat-lbl">Viditelných v portálu</div>
      </div>
      <div id="emp-stats-hidden" class="card emp-stat">
        <div class="stat-num text-muted">{{ stats.hidden }}</div>
        <div class="stat-lbl">Skrytých</div>
      </div>
      <div id="emp-stats-contracts" class="card emp-stat">
        <div class="stat-num text-mid">{{ stats.withContract }}</div>
        <div class="stat-lbl">Se smlouvou</div>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" id="emp-loading" class="card emp-loading-card">
      <Loader2 :size="32" class="spin text-mid" aria-hidden="true" />
      <p class="emp-loading-text">Načítám zaměstnance...</p>
    </div>

    <!-- Load Error state -->
    <div v-else-if="loadError" id="emp-load-error" class="alert alert-danger" role="alert">
      {{ loadError }}
    </div>

    <!-- Main content -->
    <div v-else id="emp-main-card" class="card">
      <!-- Save error toast -->
      <div v-if="saveError" id="emp-save-error" class="alert alert-danger emp-save-error" role="alert">
        {{ saveError }}
        <button type="button" class="btn btn-ghost btn-sm" aria-label="Zavřít chybu" @click="saveError = null">
          <X :size="14" aria-hidden="true" />
        </button>
      </div>

      <div id="emp-toolbar" class="table-toolbar">
        <h2 id="emp-list-title" class="card-title emp-list-title">Seznam zaměstnanců</h2>
        <div id="emp-search-wrap" class="search-wrap">
          <Search :size="15" class="search-icon" aria-hidden="true" />
          <input
            id="emp-search-input"
            v-model="searchQuery"
            type="search"
            class="form-input search-input"
            placeholder="Hledat jméno, pozici, telefon..."
            aria-label="Hledat zaměstnance"
          />
          <button
            v-if="searchQuery"
            id="emp-search-clear"
            type="button"
            class="search-clear"
            aria-label="Vymazat hledání"
            @click="clearSearch"
          >
            <X :size="14" aria-hidden="true" />
          </button>
        </div>
      </div>

      <!-- Empty state (no employees) -->
      <div v-if="filtered.length === 0 && !searchQuery" id="emp-empty-state" class="empty-state-guide emp-empty-state">
        <div class="empty-state-guide-icon"><Users :size="28" aria-hidden="true" /></div>
        <div class="empty-state-guide-title">Zatím nemáte žádné zaměstnance</div>
        <div class="empty-state-guide-desc">
          Zaměstnanci se zobrazí klientům v portálu. Každý má nastavení GDPR viditelnosti.
        </div>
        <button id="emp-empty-add-btn" class="btn btn-primary" :disabled="saving" @click="addEmployee">
          <Plus :size="16" aria-hidden="true" /> Přidat prvního zaměstnance
        </button>
        <div class="empty-state-guide-tip">
          <Lightbulb :size="14" aria-hidden="true" />
          Tip: Nezapomeňte nastavit, co z profilu bude klient vidět (GDPR).
        </div>
      </div>

      <!-- Empty state (no search results) -->
      <div v-else-if="filtered.length === 0" id="emp-no-results" class="empty-state-guide emp-no-results">
        <div class="empty-state-guide-icon"><Search :size="28" aria-hidden="true" /></div>
        <div class="empty-state-guide-title">Žádní zaměstnanci neodpovídají hledání</div>
        <div class="empty-state-guide-desc">
          Zkuste upravit hledaný výraz nebo <button type="button" class="link-button" @click="clearSearch">vymazat hledání</button>.
        </div>
      </div>

      <!-- Employee cards -->
      <div id="emp-list" class="emp-list" role="list" aria-label="Seznam zaměstnanců">
        <div v-for="emp in filtered" :key="emp.id" :id="`emp-card-${emp.id}`" class="emp-card" role="listitem">

          <!-- Collapsed header (keyboard accessible) -->
          <div
            :id="`emp-card-header-${emp.id}`"
            class="emp-card-header"
            role="button"
            tabindex="0"
            :aria-expanded="emp.expanded"
            :aria-controls="`emp-card-body-${emp.id}`"
            @click="emp.expanded = !emp.expanded"
            @keydown.enter.prevent="emp.expanded = !emp.expanded"
            @keydown.space.prevent="emp.expanded = !emp.expanded"
          >
            <div :id="`emp-avatar-${emp.id}`" class="emp-avatar" aria-hidden="true">
              <img v-if="emp.photoUrl" :src="emp.photoUrl" :alt="emp.photo" class="emp-avatar-img" />
              <template v-else>{{ initials(emp) }}</template>
            </div>
            <div class="emp-header-info">
              <span :id="`emp-name-${emp.id}`" class="emp-name">
                {{ emp.firstName || emp.lastName ? `${emp.firstName} ${emp.lastName}`.trim() : '(Nový zaměstnanec)' }}
              </span>
              <span class="emp-role text-muted">{{ emp.role || '—' }}</span>
            </div>
            <div :id="`emp-badges-${emp.id}`" class="emp-header-badges">
              <span v-if="emp.showInPortal" class="badge badge-success badge-sm">
                <Eye :size="11" aria-hidden="true" />
                <span class="badge-text">V portálu</span>
              </span>
              <span v-else class="badge badge-gray badge-sm">
                <EyeOff :size="11" aria-hidden="true" />
                <span class="badge-text">Skrytý</span>
              </span>
              <span v-if="emp.contractFile" class="badge badge-info badge-sm">
                <FileText :size="11" aria-hidden="true" />
                <span class="badge-text">Smlouva</span>
              </span>
              <span class="badge badge-gray badge-sm" :title="'GDPR: ' + toggleCount(emp) + '/6 polí viditelných'">
                {{ toggleCount(emp) }}/6 GDPR
              </span>
            </div>
            <button
              :id="`emp-delete-btn-${emp.id}`"
              type="button"
              class="btn btn-ghost btn-sm danger-hover"
              :aria-label="`Smazat ${emp.firstName || ''} ${emp.lastName || ''}`.trim() || 'Smazat zaměstnance'"
              @click.stop="confirmDelete(emp)"
            >
              <Trash2 :size="14" aria-hidden="true" />
            </button>
            <ChevronUp v-if="emp.expanded" :size="16" class="text-muted" aria-hidden="true" />
            <ChevronDown v-else :size="16" class="text-muted" aria-hidden="true" />
          </div>

          <!-- Expanded body -->
          <div v-if="emp.expanded" :id="`emp-card-body-${emp.id}`" class="emp-card-body">

            <!-- Back to list button -->
            <button
              :id="`emp-${emp.id}-back-btn`"
              type="button"
              class="btn btn-ghost btn-sm emp-back-btn"
              @click="emp.expanded = false"
            >
              <ChevronUp :size="14" aria-hidden="true" />
              Zpět na seznam
            </button>

            <!-- Basic info -->
            <div class="field-grid-2">
              <div class="form-group">
                <label :for="`emp-${emp.id}-firstName`" class="form-label">Jméno *</label>
                <input
                  :id="`emp-${emp.id}-firstName`"
                  v-model="emp.firstName"
                  type="text"
                  class="form-input"
                  :class="{ 'input-error': validationErrors.get(emp.id)?.firstName }"
                  placeholder="Jméno"
                  :aria-invalid="!!validationErrors.get(emp.id)?.firstName"
                  :aria-describedby="validationErrors.get(emp.id)?.firstName ? `emp-${emp.id}-firstName-error` : undefined"
                  @input="clearFieldError(emp.id, 'firstName')"
                />
                <p v-if="validationErrors.get(emp.id)?.firstName" :id="`emp-${emp.id}-firstName-error`" class="field-error" role="alert">
                  {{ validationErrors.get(emp.id).firstName }}
                </p>
              </div>
              <div class="form-group">
                <label :for="`emp-${emp.id}-lastName`" class="form-label">Příjmení *</label>
                <input
                  :id="`emp-${emp.id}-lastName`"
                  v-model="emp.lastName"
                  type="text"
                  class="form-input"
                  :class="{ 'input-error': validationErrors.get(emp.id)?.lastName }"
                  placeholder="Příjmení"
                  :aria-invalid="!!validationErrors.get(emp.id)?.lastName"
                  :aria-describedby="validationErrors.get(emp.id)?.lastName ? `emp-${emp.id}-lastName-error` : undefined"
                  @input="clearFieldError(emp.id, 'lastName')"
                />
                <p v-if="validationErrors.get(emp.id)?.lastName" :id="`emp-${emp.id}-lastName-error`" class="field-error" role="alert">
                  {{ validationErrors.get(emp.id).lastName }}
                </p>
              </div>
              <div class="form-group">
                <label :for="`emp-${emp.id}-role`" class="form-label">Pozice / Role</label>
                <input :id="`emp-${emp.id}-role`" v-model="emp.role" type="text" class="form-input" placeholder="Vedoucí týmu, Úklidový pracovník…" />
              </div>
              <div class="form-group">
                <label :for="`emp-${emp.id}-phone`" class="form-label">Telefon</label>
                <input
                  :id="`emp-${emp.id}-phone`"
                  v-model="emp.phone"
                  type="tel"
                  class="form-input"
                  placeholder="+420 7xx xxx xxx"
                  :aria-describedby="`emp-${emp.id}-phone-hint`"
                />
                <p :id="`emp-${emp.id}-phone-hint`" class="field-hint">Zobrazení v portálu nastavte níže v GDPR sekci.</p>
              </div>
              <div class="form-group">
                <label :for="`emp-${emp.id}-personal-id`" class="form-label">Osobní ID</label>
                <input
                  :id="`emp-${emp.id}-personal-id`"
                  v-model="emp.personalId"
                  type="text"
                  class="form-input"
                  placeholder="např. EMP-00123"
                  :aria-describedby="`emp-${emp.id}-personal-id-hint`"
                />
                <p :id="`emp-${emp.id}-personal-id-hint`" class="field-hint">Identifikátor pro propojení s docházkovým systémem.</p>
              </div>
              <div class="form-group">
                <label :for="`emp-${emp.id}-tenure`" class="form-label">Délka spolupráce</label>
                <input
                  :id="`emp-${emp.id}-tenure`"
                  v-model="emp.tenureText"
                  type="text"
                  class="form-input"
                  placeholder="2 roky, 6 měsíců…"
                  :aria-describedby="`emp-${emp.id}-tenure-hint`"
                />
                <p :id="`emp-${emp.id}-tenure-hint`" class="field-hint">Ručně vyplněný text, např. „3 roky".</p>
              </div>
            </div>

            <div class="field-grid-2">
              <div class="form-group">
                <label :for="`emp-${emp.id}-bio`" class="form-label">O zaměstnanci (bio)</label>
                <textarea :id="`emp-${emp.id}-bio`" v-model="emp.bio" class="form-input form-textarea" rows="3" placeholder="Krátký popis pracovníka pro portál..." />
              </div>
              <div class="form-group">
                <label :for="`emp-${emp.id}-hobbies`" class="form-label">Záliby</label>
                <textarea :id="`emp-${emp.id}-hobbies`" v-model="emp.hobbies" class="form-input form-textarea" rows="3" placeholder="Sport, cestování, vaření…" />
              </div>
            </div>

            <!-- Photo upload -->
            <div :id="`emp-${emp.id}-photo-section`" class="upload-section">
              <div :id="`emp-${emp.id}-photo-label`" class="form-label upload-label">
                <User :size="14" aria-hidden="true" /> Fotografie
              </div>
              <div v-if="emp.photoUrl" class="file-uploaded">
                <div :id="`emp-${emp.id}-photo-tile`" class="file-media-tile" :title="emp.photo">
                  <img
                    :id="`emp-${emp.id}-photo-thumb`"
                    :src="emp.photoUrl"
                    :alt="emp.photo"
                    class="file-media-img clickable"
                    @click="openPreview(emp.photoUrl, emp.photo)"
                  />
                  <div v-if="emp.uploadingPhoto" class="file-media-overlay file-media-overlay-loading">
                    <Loader2 :size="24" class="spin" style="color:var(--color-white);" />
                  </div>
                  <div v-else class="file-media-overlay">
                    <button
                      :id="`emp-${emp.id}-photo-download`"
                      type="button"
                      class="file-media-btn"
                      aria-label="Stáhnout fotografii"
                      @click.stop="downloadFile(emp.photoUrl, emp.photo)"
                    >
                      <Download :size="14" aria-hidden="true" />
                    </button>
                    <button
                      :id="`emp-${emp.id}-photo-remove`"
                      type="button"
                      class="file-media-btn file-media-btn-danger"
                      aria-label="Odebrat fotografii"
                      @click.stop="removePhoto(emp)"
                    >
                      <Trash2 :size="14" aria-hidden="true" />
                    </button>
                  </div>
                </div>
                <label :for="`emp-${emp.id}-photo-input`" class="btn btn-outline btn-sm upload-btn">
                  <Upload :size="13" aria-hidden="true" /> Změnit fotku
                  <input
                    :id="`emp-${emp.id}-photo-input`"
                    type="file"
                    accept="image/*"
                    class="sr-only"
                    @change="e => handlePhotoUpload(emp, e)"
                  />
                </label>
              </div>
              <div v-else-if="emp.uploadingPhoto" class="file-uploading">
                <div class="file-media-tile file-media-tile-loading">
                  <Loader2 :size="24" class="spin" style="color:var(--color-gray-400);" />
                </div>
                <span class="field-hint">Nahrávám...</span>
              </div>
              <div v-else class="file-empty">
                <p :id="`emp-${emp.id}-photo-hint`" class="field-hint upload-hint">Pokud fotka chybí, zobrazí se výchozí avatar s iniciálami.</p>
                <label :for="`emp-${emp.id}-photo-input-empty`" class="btn btn-outline btn-sm upload-btn">
                  <Upload :size="14" aria-hidden="true" /> Nahrát fotografii
                  <input
                    :id="`emp-${emp.id}-photo-input-empty`"
                    type="file"
                    accept="image/*"
                    class="sr-only"
                    :aria-describedby="`emp-${emp.id}-photo-hint`"
                    @change="e => handlePhotoUpload(emp, e)"
                  />
                </label>
              </div>
            </div>

            <!-- Contract upload -->
            <div :id="`emp-${emp.id}-contract-section`" class="upload-section">
              <div :id="`emp-${emp.id}-contract-label`" class="form-label upload-label">
                <FileText :size="14" aria-hidden="true" /> Pracovní smlouva
              </div>
              <div v-if="emp.contractFile" class="file-uploaded">
                <div class="file-row file-row-success">
                  <FileText :size="16" class="text-success" aria-hidden="true" />
                  <button
                    :id="`emp-${emp.id}-contract-name`"
                    type="button"
                    class="file-name-btn fw-500"
                    @click="openPreview(emp.contractFile, emp.contractOriginalName || extractFilename(emp.contractFile))"
                  >{{ emp.contractOriginalName || extractFilename(emp.contractFile) }}</button>
                  <div class="file-actions">
                    <button
                      :id="`emp-${emp.id}-contract-download`"
                      type="button"
                      class="btn btn-ghost btn-sm"
                      aria-label="Stáhnout smlouvu"
                      @click="downloadFile(emp.contractFile, emp.contractOriginalName || extractFilename(emp.contractFile))"
                    >
                      <Download :size="13" aria-hidden="true" />
                    </button>
                    <button
                      :id="`emp-${emp.id}-contract-remove`"
                      type="button"
                      class="btn btn-ghost btn-sm"
                      aria-label="Odebrat smlouvu"
                      @click="removeContract(emp)"
                    >
                      <Trash2 :size="13" aria-hidden="true" />
                    </button>
                  </div>
                </div>
                <label :for="`emp-${emp.id}-contract-input`" class="btn btn-outline btn-sm upload-btn">
                  <Upload :size="13" aria-hidden="true" /> Nahrát novou verzi
                  <input
                    :id="`emp-${emp.id}-contract-input`"
                    type="file"
                    accept=".pdf,.doc,.docx"
                    class="sr-only"
                    @change="e => handleContractUpload(emp, e)"
                  />
                </label>
              </div>
              <div v-else-if="emp.uploadingContract" class="file-uploading">
                <div class="file-row">
                  <Loader2 :size="16" class="spin" style="color:var(--color-gray-400);" />
                  <span class="fw-500">{{ emp.contractOriginalName }}</span>
                </div>
              </div>
              <div v-else class="file-empty">
                <label :for="`emp-${emp.id}-contract-input-empty`" class="btn btn-primary btn-sm upload-btn">
                  <Upload :size="14" aria-hidden="true" /> Nahrát smlouvu
                  <input
                    :id="`emp-${emp.id}-contract-input-empty`"
                    type="file"
                    accept=".pdf,.doc,.docx"
                    class="sr-only"
                    @change="e => handleContractUpload(emp, e)"
                  />
                </label>
              </div>
            </div>

            <!-- GDPR portal visibility toggles -->
            <fieldset :id="`emp-${emp.id}-gdpr-section`" class="gdpr-section">
              <legend class="form-label gdpr-legend">Viditelnost v portálu</legend>
              <p :id="`emp-${emp.id}-gdpr-hint`" class="field-hint gdpr-hint">Nastavte, co z profilu zaměstnance uvidí klient na portálu.</p>

              <!-- Master toggle -->
              <div class="gdpr-master">
                <div class="gdpr-toggle-item gdpr-master-item">
                  <button
                    :id="`emp-${emp.id}-toggle-portal`"
                    type="button"
                    class="toggle-btn"
                    :class="{ 'toggle-on': emp.showInPortal }"
                    role="switch"
                    :aria-checked="emp.showInPortal"
                    aria-label="Zobrazit v portálu"
                    @click="emp.showInPortal = !emp.showInPortal"
                  >
                    <span class="toggle-knob" aria-hidden="true" />
                  </button>
                  <span class="gdpr-toggle-label">Zobrazit v portálu</span>
                  <span class="badge badge-sm" :class="emp.showInPortal ? 'badge-success' : 'badge-gray'">
                    {{ emp.showInPortal ? 'ANO' : 'NE' }}
                  </span>
                </div>
              </div>

              <div class="gdpr-grid">
                <div class="gdpr-toggle-item">
                  <button
                    :id="`emp-${emp.id}-toggle-photo`"
                    type="button"
                    class="toggle-btn toggle-sm"
                    :class="{ 'toggle-on': emp.showPhoto }"
                    role="switch"
                    :aria-checked="emp.showPhoto"
                    aria-label="Zobrazit fotografii"
                    @click="emp.showPhoto = !emp.showPhoto"
                  >
                    <span class="toggle-knob" aria-hidden="true" />
                  </button>
                  <span>Fotografie</span>
                </div>
                <div class="gdpr-toggle-item">
                  <button
                    :id="`emp-${emp.id}-toggle-role`"
                    type="button"
                    class="toggle-btn toggle-sm"
                    :class="{ 'toggle-on': emp.showRole }"
                    role="switch"
                    :aria-checked="emp.showRole"
                    aria-label="Zobrazit pozici"
                    @click="emp.showRole = !emp.showRole"
                  >
                    <span class="toggle-knob" aria-hidden="true" />
                  </button>
                  <span>Pozice</span>
                </div>
                <div class="gdpr-toggle-item">
                  <button
                    :id="`emp-${emp.id}-toggle-tenure`"
                    type="button"
                    class="toggle-btn toggle-sm"
                    :class="{ 'toggle-on': emp.showTenure }"
                    role="switch"
                    :aria-checked="emp.showTenure"
                    aria-label="Zobrazit délku spolupráce"
                    @click="emp.showTenure = !emp.showTenure"
                  >
                    <span class="toggle-knob" aria-hidden="true" />
                  </button>
                  <span>Délka spolupráce</span>
                </div>
                <div class="gdpr-toggle-item">
                  <button
                    :id="`emp-${emp.id}-toggle-bio`"
                    type="button"
                    class="toggle-btn toggle-sm"
                    :class="{ 'toggle-on': emp.showBio }"
                    role="switch"
                    :aria-checked="emp.showBio"
                    aria-label="Zobrazit bio"
                    @click="emp.showBio = !emp.showBio"
                  >
                    <span class="toggle-knob" aria-hidden="true" />
                  </button>
                  <span>Bio</span>
                </div>
                <div class="gdpr-toggle-item">
                  <button
                    :id="`emp-${emp.id}-toggle-hobbies`"
                    type="button"
                    class="toggle-btn toggle-sm"
                    :class="{ 'toggle-on': emp.showHobbies }"
                    role="switch"
                    :aria-checked="emp.showHobbies"
                    aria-label="Zobrazit záliby"
                    @click="emp.showHobbies = !emp.showHobbies"
                  >
                    <span class="toggle-knob" aria-hidden="true" />
                  </button>
                  <span>Záliby</span>
                </div>
                <div class="gdpr-toggle-item">
                  <button
                    :id="`emp-${emp.id}-toggle-phone`"
                    type="button"
                    class="toggle-btn toggle-sm"
                    :class="{ 'toggle-on': emp.showPhone }"
                    role="switch"
                    :aria-checked="emp.showPhone"
                    aria-label="Zobrazit telefon"
                    @click="emp.showPhone = !emp.showPhone"
                  >
                    <span class="toggle-knob" aria-hidden="true" />
                  </button>
                  <span>Telefon</span>
                </div>
              </div>
            </fieldset>

          </div><!-- /emp-card-body -->
        </div>
      </div>
    </div>

    <!-- Bottom save bar (only show when there are employees to save) -->
    <div v-if="employees.length > 0" id="emp-save-bar" class="bottom-save-bar">
      <span v-if="saved" id="emp-saved-msg" class="saved-msg" role="status">
        <CheckCircle2 :size="15" aria-hidden="true" /> Změny uloženy
      </span>
      <button id="emp-save-btn" class="btn btn-primary" :disabled="saving" @click="save">
        <Loader2 v-if="saving" :size="16" class="spin" aria-hidden="true" />
        <Save v-else :size="16" aria-hidden="true" />
        {{ saving ? 'Ukládám...' : 'Uložit změny' }}
      </button>
    </div>

    <FilePreviewModal
      :show="previewModal.show"
      :url="previewModal.url"
      :filename="previewModal.filename"
      :mime-type="previewModal.mimeType"
      @close="closePreview"
    />

    <!-- Delete confirmation modal -->
    <Teleport to="body">
      <div v-if="deleteConfirm.show" id="emp-delete-modal" class="modal-overlay" @click.self="cancelDelete">
        <div
          id="emp-delete-modal-content"
          class="modal-content"
          role="alertdialog"
          aria-modal="true"
          aria-labelledby="emp-delete-modal-title"
          aria-describedby="emp-delete-modal-desc"
        >
          <h3 id="emp-delete-modal-title" class="modal-title">Smazat zaměstnance?</h3>
          <p id="emp-delete-modal-desc" class="modal-desc">
            Opravdu chcete smazat <strong>{{ deleteConfirm.empName }}</strong>? Tuto akci nelze vrátit zpět.
          </p>
          <div class="modal-actions">
            <button id="emp-delete-cancel-btn" type="button" class="btn btn-ghost" @click="cancelDelete">
              Zrušit
            </button>
            <button id="emp-delete-confirm-btn" type="button" class="btn btn-danger" @click="executeDelete">
              <Trash2 :size="14" aria-hidden="true" /> Smazat
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <div v-if="toast" id="emp-toast" class="toast" :class="'toast-' + toast.type">
      {{ toast.message }}
    </div>
  </div>
</template>

<style scoped>
/* Page title with flex alignment */
.emp-page-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Stats */
/* Mobile-first: 2 cols → 4 at lg */
.emp-stats {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
  margin-bottom: 20px;
  max-width: 900px;
}
.emp-stat { text-align: center; }
.stat-num {
  font-size: 28px;
  font-weight: 700;
  color: var(--color-primary);
}
.stat-lbl {
  font-size: 12px;
  color: var(--color-gray-600);
  margin-top: 4px;
}

/* Loading card */
.emp-loading-card {
  padding: 40px;
  text-align: center;
}
.emp-loading-text {
  margin-top: 12px;
  color: var(--color-gray-600);
}

/* Save error */
.emp-save-error {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

/* Toolbar */
.table-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}
.emp-list-title {
  margin-bottom: 0;
}
.search-wrap {
  position: relative;
  max-width: 280px;
  width: 100%;
}
.search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-gray-500);
  pointer-events: none;
}
.search-input {
  width: 100%;
  padding-left: 32px;
  padding-right: 32px;
  font-size: 13px;
}

/* Search clear button */
.search-clear {
  position: absolute;
  right: 4px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: var(--color-gray-500);
  cursor: pointer;
  padding: 8px;
  border-radius: var(--radius-sm);
  display: flex;
  align-items: center;
  justify-content: center;
}
.search-clear:hover {
  color: var(--color-gray-700);
  background: var(--color-gray-100);
}
.search-clear:focus-visible {
  outline: 2px solid var(--color-mid);
  outline-offset: 2px;
}

.top-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

/* Saved msg */
.saved-msg {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  color: var(--color-success);
  font-weight: 500;
}

/* Empty state styles */
.emp-empty-state {
  margin-top: 16px;
}
.emp-no-results {
  margin-top: 16px;
}
.link-button {
  background: none;
  border: none;
  color: var(--color-mid);
  text-decoration: underline;
  cursor: pointer;
  font-size: inherit;
  padding: 0;
}
.link-button:hover {
  color: var(--color-primary);
}
.link-button:focus-visible {
  outline: 2px solid var(--color-mid);
  outline-offset: 2px;
}

/* Employee list */
.emp-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 16px;
}

.emp-card {
  border: 1.5px solid var(--color-gray-200);
  border-radius: var(--radius-md);
  overflow: hidden;
  background: white;
}

.emp-card-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  cursor: pointer;
  border: none;
  background: none;
  width: 100%;
  text-align: left;
}
.emp-card-header:hover { background: var(--color-gray-50); }
.emp-card-header:focus-visible {
  outline: 2px solid var(--color-mid);
  outline-offset: -2px;
  background: var(--color-gray-50);
}

.emp-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--color-mid);
  color: var(--color-white);
  font-size: 14px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;
}
.emp-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.emp-header-info {
  display: flex;
  flex-direction: column;
  flex: 1;
  gap: 2px;
  min-width: 0;
}
.emp-name { font-weight: 600; font-size: 14px; color: var(--color-primary); }
.emp-role { font-size: 12px; }

.emp-header-badges { display: flex; gap: 4px; flex-wrap: wrap; }

/* Badge small variant */
.badge-sm {
  font-size: 11px;
}

/* Body */
.emp-card-body {
  padding: 0 16px 16px;
  border-top: 1px solid var(--color-gray-100);
  padding-top: 14px;
}

/* Back button */
.emp-back-btn {
  margin-bottom: 12px;
  color: var(--color-mid);
}

/* Field grid */
.field-grid-2 {
  display: grid;
  grid-template-columns: 1fr;
  gap: 14px;
}
@media (min-width: 768px) {
  .field-grid-2 { grid-template-columns: 1fr 1fr; }
}

.field-hint {
  font-size: 11px;
  color: var(--color-gray-500);
  margin-top: 4px;
}

/* Validation error */
.input-error {
  border-color: var(--color-danger);
}
.input-error:focus {
  border-color: var(--color-danger);
  box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.15);
}
.field-error {
  color: var(--color-danger);
  font-size: 12px;
  margin-top: 4px;
}

.form-textarea { resize: vertical; min-height: 72px; }

/* Upload sections */
.upload-section {
  padding: 14px 0;
  border-top: 1px solid var(--color-gray-100);
  margin-top: 4px;
}
.upload-label {
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.upload-hint {
  margin-bottom: 8px;
}
.upload-btn {
  cursor: pointer;
  margin-top: 8px;
}

.file-uploaded { display: flex; flex-direction: column; }
.file-uploading { display: flex; flex-direction: column; gap: 6px; }

.file-media-tile {
  position: relative;
  width: 96px;
  height: 96px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-gray-200);
  overflow: hidden;
  cursor: pointer;
}
.file-media-tile-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-gray-50);
  cursor: default;
}
.file-media-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.file-media-overlay {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  opacity: 0;
  transition: opacity var(--transition);
}
.file-media-overlay-loading {
  opacity: 1;
}
.file-media-tile:hover .file-media-overlay:not(.file-media-overlay-loading),
.file-media-tile:focus-within .file-media-overlay:not(.file-media-overlay-loading) {
  opacity: 1;
}
.file-media-btn {
  width: 32px;
  height: 32px;
  border-radius: var(--radius-sm);
  border: none;
  background: rgba(255, 255, 255, 0.9);
  color: var(--color-gray-700);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background var(--transition), color var(--transition);
}
.file-media-btn:hover {
  background: var(--color-white);
  color: var(--color-gray-900);
}
.file-media-btn-danger:hover {
  background: var(--color-danger-light);
  color: var(--color-danger);
}
.file-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  background: var(--color-gray-50);
  border-radius: var(--radius-md);
  font-size: 13px;
}
.file-row-success { background: var(--color-success-light); }
.file-name-btn {
  background: none;
  border: none;
  padding: 0;
  font: inherit;
  color: var(--color-accent);
  cursor: pointer;
  text-align: left;
  text-decoration: underline;
  text-decoration-color: transparent;
  transition: text-decoration-color var(--transition);
}
.file-name-btn:hover {
  text-decoration-color: var(--color-accent);
}
.file-actions {
  display: flex;
  gap: 2px;
  margin-left: auto;
}

/* GDPR section */
.gdpr-section {
  padding: 14px;
  background: var(--color-gray-50);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-gray-200);
  margin-top: 12px;
}
.gdpr-legend {
  margin-bottom: 4px;
}
.gdpr-hint {
  margin-bottom: 12px;
}

.gdpr-master {
  padding-bottom: 12px;
  margin-bottom: 12px;
  border-bottom: 1px solid var(--color-gray-200);
}
.gdpr-master-item {
  font-weight: 600;
  font-size: 14px;
}
.gdpr-toggle-label {
  flex: 1;
}

/* Mobile-first GDPR grid: 1 → 2 (sm) → 3 (md) */
.gdpr-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
}
@media (min-width: 640px) {
  .gdpr-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (min-width: 768px) {
  .gdpr-grid { grid-template-columns: repeat(3, 1fr); }
}
.gdpr-toggle-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-700);
}


/* Toggle switch (matches AdminClientEditView) */
.toggle-btn {
  position: relative;
  width: 44px;
  height: 24px;
  border-radius: 12px;
  background: var(--color-gray-300);
  border: none;
  cursor: pointer;
  transition: background 0.2s;
  flex-shrink: 0;
}
.toggle-btn.toggle-on { background: var(--color-success); }
.toggle-btn.toggle-sm { width: 36px; height: 20px; border-radius: 10px; }
.toggle-btn:disabled { cursor: not-allowed; }
.toggle-btn:focus-visible {
  outline: 2px solid var(--color-mid);
  outline-offset: 2px;
}

.toggle-knob {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: white;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
  transition: left 0.2s;
}
.toggle-sm .toggle-knob { width: 16px; height: 16px; }
.toggle-on .toggle-knob { left: calc(100% - 22px); }
.toggle-sm.toggle-on .toggle-knob { left: calc(100% - 18px); }

/* Danger hover */
.danger-hover:hover { color: var(--color-danger) !important; }

/* Bottom save bar */
.bottom-save-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  padding: 20px 0 8px;
  border-top: 1px solid var(--color-gray-200);
  margin-top: 20px;
}

/* Modal */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.modal-content {
  background: white;
  padding: 24px;
  border-radius: var(--radius-lg);
  max-width: 400px;
  width: 90%;
  box-shadow: var(--shadow-lg);
}
.modal-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 8px;
}
.modal-desc {
  font-size: 14px;
  color: var(--color-gray-600);
  line-height: 1.5;
}
.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 20px;
}

/* Responsive — grids handled mobile-first above. Enhance emp-stats at lg: */
@media (min-width: 1024px) {
  .emp-stats {
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
  }
}

/* Condensed badges on mobile — keep text hidden but maintain 12px size for accessibility */
.emp-header-badges { gap: 2px; }
.emp-header-badges .badge { padding: 3px 7px; font-size: 12px; }
.emp-header-badges .badge .badge-text { display: none; }
@media (min-width: 640px) {
  .emp-header-badges { gap: 6px; }
  .emp-header-badges .badge { padding: 3px 10px; }
  .emp-header-badges .badge .badge-text { display: inline; }
}
</style>
