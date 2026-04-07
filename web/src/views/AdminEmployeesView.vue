<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import {
  Users, Plus, Search, Trash2, Upload, FileText,
  ChevronDown, ChevronUp, Eye, EyeOff, User, Save, CheckCircle2, Loader2, Lightbulb, X,
} from 'lucide-vue-next'
import { adminService } from '../api'

// ── State ────────────────────────────────────────────────────────────────────
let _id = 100
function uid() { return `emp-new-${++_id}` }

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

// Pending file uploads
const pendingUploads = ref(new Map())

// Fetch employees
onMounted(async () => {
  try {
    const response = await adminService.getEmployees()
    if (response.success) {
      employees.value = (response.data || []).map(e => ({ ...e, id: e.id || uid(), expanded: false }))
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
    tenureText: '',
    bio: '',
    hobbies: '',
    photo: null,
    photoUrl: null,
    contractFile: null,
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
  pendingUploads.value.delete(deleteConfirm.value.empId)
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

function handlePhotoUpload(emp, event) {
  const file = event.target.files?.[0]
  if (file) {
    emp.photo = file.name
    if (!pendingUploads.value.has(emp.id)) {
      pendingUploads.value.set(emp.id, {})
    }
    pendingUploads.value.get(emp.id).photo = file
  }
}

function handleContractUpload(emp, event) {
  const file = event.target.files?.[0]
  if (file) {
    emp.contractFile = file.name
    if (!pendingUploads.value.has(emp.id)) {
      pendingUploads.value.set(emp.id, {})
    }
    pendingUploads.value.get(emp.id).contract = file
  }
}

function removePhoto(emp) {
  emp.photo = null
  emp.photoUrl = null
  const pending = pendingUploads.value.get(emp.id)
  if (pending) {
    delete pending.photo
  }
}

function removeContract(emp) {
  emp.contractFile = null
  const pending = pendingUploads.value.get(emp.id)
  if (pending) {
    delete pending.contract
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
    // Upload pending files first
    for (const [empId, files] of pendingUploads.value) {
      const emp = employees.value.find(e => e.id === empId)
      if (!emp) continue

      if (files.photo) {
        const url = await adminService.uploadFile(files.photo, 'employee-photos')
        emp.photoUrl = url
      }
      if (files.contract) {
        const url = await adminService.uploadFile(files.contract, 'employee-contracts')
        emp.contractFile = url
      }
    }
    pendingUploads.value.clear()

    const response = await adminService.saveEmployees(employees.value.map(e => ({
      id: e.id,
      firstName: e.firstName,
      lastName: e.lastName,
      role: e.role,
      phone: e.phone,
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
              {{ initials(emp) }}
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
                <p :id="`emp-${emp.id}-phone-hint`" class="field-hint">V MVP se klientům nikdy nezobrazuje.</p>
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
              <div v-if="emp.photo" class="file-uploaded">
                <div class="file-row">
                  <User :size="16" class="text-success" aria-hidden="true" />
                  <span class="fw-500">{{ emp.photo }}</span>
                  <button
                    :id="`emp-${emp.id}-photo-remove`"
                    type="button"
                    class="btn btn-ghost btn-sm"
                    aria-label="Odebrat fotografii"
                    @click="removePhoto(emp)"
                  >
                    <Trash2 :size="13" aria-hidden="true" />
                  </button>
                </div>
                <label :for="`emp-${emp.id}-photo-input`" class="btn btn-outline btn-sm upload-btn">
                  <Upload :size="13" aria-hidden="true" /> Nahrát novou fotku
                  <input
                    :id="`emp-${emp.id}-photo-input`"
                    type="file"
                    accept="image/*"
                    class="sr-only"
                    @change="e => handlePhotoUpload(emp, e)"
                  />
                </label>
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
                  <span class="fw-500">{{ emp.contractFile }}</span>
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
              <p :id="`emp-${emp.id}-gdpr-hint`" class="field-hint gdpr-hint">Nastavte, co z profilu zaměstnance uvidí klient na portálu. Telefon se v MVP nikdy nezobrazuje.</p>

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
                <div class="gdpr-toggle-item gdpr-disabled" title="V MVP se telefon klientům nikdy nezobrazuje">
                  <button
                    :id="`emp-${emp.id}-toggle-phone`"
                    type="button"
                    class="toggle-btn toggle-sm"
                    :class="{ 'toggle-on': emp.showPhone }"
                    role="switch"
                    :aria-checked="emp.showPhone"
                    aria-label="Zobrazit telefon (nedostupné)"
                    disabled
                    @click="emp.showPhone = !emp.showPhone"
                  >
                    <span class="toggle-knob" aria-hidden="true" />
                  </button>
                  <span>Telefon <span class="field-hint gdpr-phone-hint">(MVP: skrytý)</span></span>
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
.emp-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
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
  color: white;
  font-size: 14px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
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

/* Field grid */
.field-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
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

.gdpr-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}
.gdpr-toggle-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-700);
}

.gdpr-disabled {
  opacity: 0.5;
}
.gdpr-disabled button { cursor: not-allowed; }
.gdpr-phone-hint {
  margin: 0;
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

/* Responsive */
@media (max-width: 900px) {
  .emp-stats { grid-template-columns: repeat(2, 1fr); }
  .field-grid-2 { grid-template-columns: 1fr; }
  .gdpr-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 600px) {
  .emp-stats { grid-template-columns: 1fr 1fr; }
  .gdpr-grid { grid-template-columns: 1fr; }
  /* Show condensed badges on mobile instead of hiding */
  .emp-header-badges {
    gap: 2px;
  }
  .emp-header-badges .badge {
    padding: 2px 6px;
    font-size: 10px;
  }
  .emp-header-badges .badge .badge-text {
    display: none;
  }
}
</style>
