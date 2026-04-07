<script setup>
import { ref, computed, onMounted } from 'vue'
import {
  Users, Plus, Search, Trash2, Upload, FileText,
  ChevronDown, ChevronUp, Eye, EyeOff, User, Save, CheckCircle2, Loader2, Lightbulb,
} from 'lucide-vue-next'
import { useRouter } from 'vue-router'
import { adminService } from '../api'

const router = useRouter()

// ── State ────────────────────────────────────────────────────────────────────
let _id = 100
function uid() { return `emp-new-${++_id}` }

const loading = ref(true)
const error = ref(null)
const employees = ref([])
const searchQuery = ref('')
const saving = ref(false)
const saved = ref(false)

// Fetch employees
onMounted(async () => {
  try {
    const response = await adminService.getEmployees()
    if (response.success) {
      employees.value = (response.data || []).map(e => ({ ...e, id: e.id || uid(), expanded: false }))
    } else {
      error.value = response.message || 'Nepodařilo se načíst zaměstnance'
    }
  } catch (err) {
    error.value = err.message || 'Nepodařilo se načíst zaměstnance'
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

function removeEmployee(id) {
  employees.value = employees.value.filter(e => e.id !== id)
}

function handlePhotoUpload(emp, event) {
  const file = event.target.files?.[0]
  if (file) emp.photo = file.name
}

function handleContractUpload(emp, event) {
  const file = event.target.files?.[0]
  if (file) emp.contractFile = file.name
}

async function save() {
  saving.value = true
  try {
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
      error.value = response.message || 'Uložení se nezdařilo'
    }
  } catch (err) {
    error.value = err.message || 'Uložení se nezdařilo'
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
</script>

<template>
  <div>
    <!-- Top bar -->
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <Users :size="24" style="vertical-align:middle; margin-right:8px; color:var(--color-mid);" />
          Zaměstnanci
        </h1>
        <p class="page-subtitle">Admin sekce · správa zaměstnanců, pracovní smlouvy, GDPR viditelnost v portálu</p>
      </div>
      <div class="top-actions">
        <span v-if="saved" class="saved-msg"><CheckCircle2 :size="15" /> Uloženo</span>
        <button class="btn btn-primary" @click="addEmployee">
          <Plus :size="16" /> Přidat zaměstnance
        </button>
      </div>
    </div>

    <!-- Stats -->
    <div class="emp-stats">
      <div class="card emp-stat">
        <div class="stat-num">{{ stats.total }}</div>
        <div class="stat-lbl">Celkem</div>
      </div>
      <div class="card emp-stat">
        <div class="stat-num text-success">{{ stats.visible }}</div>
        <div class="stat-lbl">Viditelných v portálu</div>
      </div>
      <div class="card emp-stat">
        <div class="stat-num text-muted">{{ stats.hidden }}</div>
        <div class="stat-lbl">Skrytých</div>
      </div>
      <div class="card emp-stat">
        <div class="stat-num" style="color:var(--color-mid);">{{ stats.withContract }}</div>
        <div class="stat-lbl">Se smlouvou</div>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám zaměstnance...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Search -->
    <div v-else class="card">
      <div class="table-toolbar">
        <h3 class="card-title" style="margin-bottom:0;">Seznam zaměstnanců</h3>
        <div class="search-wrap">
          <Search :size="15" class="search-icon" />
          <input
            v-model="searchQuery"
            type="text"
            class="form-input search-input"
            placeholder="Hledat jméno, pozici, telefon..."
          />
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="filtered.length === 0 && !searchQuery" class="empty-state-guide" style="margin-top:16px;">
        <div class="empty-state-guide-icon"><Users :size="28" /></div>
        <div class="empty-state-guide-title">Zatím nemáte žádné zaměstnance</div>
        <div class="empty-state-guide-desc">
          Zaměstnanci se zobrazí klientům v portálu. Každý má nastavení GDPR viditelnosti.
        </div>
        <button class="btn btn-primary" @click="addEmployee">
          <Plus :size="16" /> Přidat prvního zaměstnance
        </button>
        <div class="empty-state-guide-tip">
          <Lightbulb :size="14" style="vertical-align:middle;margin-right:4px;" />
          Tip: Nezapomeňte nastavit, co z profilu bude klient vidět (GDPR).
        </div>
      </div>
      <div v-else-if="filtered.length === 0" class="empty-list-hint" style="margin-top:16px;">
        <Users :size="28" /> Žádní zaměstnanci neodpovídají hledání.
      </div>

      <!-- Employee cards -->
      <div class="emp-list">
        <div v-for="emp in filtered" :key="emp.id" class="emp-card">

          <!-- Collapsed header -->
          <div class="emp-card-header" @click="emp.expanded = !emp.expanded">
            <div class="emp-avatar">
              {{ initials(emp) }}
            </div>
            <div class="emp-header-info">
              <span class="emp-name">
                {{ emp.firstName || emp.lastName ? `${emp.firstName} ${emp.lastName}`.trim() : '(Nový zaměstnanec)' }}
              </span>
              <span class="emp-role text-muted">{{ emp.role || '—' }}</span>
            </div>
            <div class="emp-header-badges">
              <span v-if="emp.showInPortal" class="badge badge-success" style="font-size:11px;">
                <Eye :size="11" /> V portálu
              </span>
              <span v-else class="badge badge-gray" style="font-size:11px;">
                <EyeOff :size="11" /> Skrytý
              </span>
              <span v-if="emp.contractFile" class="badge badge-info" style="font-size:11px;">
                <FileText :size="11" /> Smlouva
              </span>
              <span class="badge badge-gray" style="font-size:11px;" :title="'GDPR: ' + toggleCount(emp) + '/6 polí viditelných'">
                {{ toggleCount(emp) }}/6 GDPR
              </span>
            </div>
            <button class="btn btn-ghost btn-sm danger-hover" @click.stop="removeEmployee(emp.id)" title="Smazat">
              <Trash2 :size="14" />
            </button>
            <ChevronUp v-if="emp.expanded" :size="16" class="text-muted" />
            <ChevronDown v-else :size="16" class="text-muted" />
          </div>

          <!-- Expanded body -->
          <div v-if="emp.expanded" class="emp-card-body">

            <!-- Basic info -->
            <div class="field-grid-2">
              <div class="form-group">
                <label class="form-label">Jméno *</label>
                <input v-model="emp.firstName" type="text" class="form-input" placeholder="Jméno" />
              </div>
              <div class="form-group">
                <label class="form-label">Příjmení *</label>
                <input v-model="emp.lastName" type="text" class="form-input" placeholder="Příjmení" />
              </div>
              <div class="form-group">
                <label class="form-label">Pozice / Role</label>
                <input v-model="emp.role" type="text" class="form-input" placeholder="Vedoucí týmu, Úklidový pracovník…" />
              </div>
              <div class="form-group">
                <label class="form-label">Telefon</label>
                <input v-model="emp.phone" type="tel" class="form-input" placeholder="+420 7xx xxx xxx" />
                <p class="field-hint">V MVP se klientům nikdy nezobrazuje.</p>
              </div>
              <div class="form-group">
                <label class="form-label">Délka spolupráce</label>
                <input v-model="emp.tenureText" type="text" class="form-input" placeholder="2 roky, 6 měsíců…" />
                <p class="field-hint">Ručně vyplněný text, např. „3 roky".</p>
              </div>
            </div>

            <div class="field-grid-2">
              <div class="form-group">
                <label class="form-label">O zaměstnanci (bio)</label>
                <textarea v-model="emp.bio" class="form-input form-textarea" rows="3" placeholder="Krátký popis pracovníka pro portál..." />
              </div>
              <div class="form-group">
                <label class="form-label">Záliby</label>
                <textarea v-model="emp.hobbies" class="form-input form-textarea" rows="3" placeholder="Sport, cestování, vaření…" />
              </div>
            </div>

            <!-- Photo upload -->
            <div class="upload-section">
              <div class="form-label" style="margin-bottom:8px;">
                <User :size="14" style="vertical-align:middle;" /> Fotografie
              </div>
              <div v-if="emp.photo" class="file-uploaded">
                <div class="file-row">
                  <User :size="16" class="text-success" />
                  <span class="fw-500">{{ emp.photo }}</span>
                  <button class="btn btn-ghost btn-sm" @click="emp.photo = null">
                    <Trash2 :size="13" />
                  </button>
                </div>
                <label class="btn btn-outline btn-sm" style="cursor:pointer; margin-top:8px;">
                  <Upload :size="13" /> Nahrát novou fotku
                  <input type="file" accept="image/*" style="display:none;" @change="e => handlePhotoUpload(emp, e)" />
                </label>
              </div>
              <div v-else class="file-empty">
                <p class="field-hint" style="margin-bottom:8px;">Pokud fotka chybí, zobrazí se výchozí avatar s iniciálami.</p>
                <label class="btn btn-outline btn-sm" style="cursor:pointer;">
                  <Upload :size="14" /> Nahrát fotografii
                  <input type="file" accept="image/*" style="display:none;" @change="e => handlePhotoUpload(emp, e)" />
                </label>
              </div>
            </div>

            <!-- Contract upload -->
            <div class="upload-section">
              <div class="form-label" style="margin-bottom:8px;">
                <FileText :size="14" style="vertical-align:middle;" /> Pracovní smlouva
              </div>
              <div v-if="emp.contractFile" class="file-uploaded">
                <div class="file-row file-row-success">
                  <FileText :size="16" class="text-success" />
                  <span class="fw-500">{{ emp.contractFile }}</span>
                  <button class="btn btn-ghost btn-sm" @click="emp.contractFile = null">
                    <Trash2 :size="13" />
                  </button>
                </div>
                <label class="btn btn-outline btn-sm" style="cursor:pointer; margin-top:8px;">
                  <Upload :size="13" /> Nahrát novou verzi
                  <input type="file" accept=".pdf,.doc,.docx" style="display:none;" @change="e => handleContractUpload(emp, e)" />
                </label>
              </div>
              <div v-else class="file-empty">
                <label class="btn btn-primary btn-sm" style="cursor:pointer;">
                  <Upload :size="14" /> Nahrát smlouvu
                  <input type="file" accept=".pdf,.doc,.docx" style="display:none;" @change="e => handleContractUpload(emp, e)" />
                </label>
              </div>
            </div>

            <!-- GDPR portal visibility toggles -->
            <div class="gdpr-section">
              <div class="form-label" style="margin-bottom:4px;">Viditelnost v portálu</div>
              <p class="field-hint" style="margin-bottom:12px;">Nastavte, co z profilu zaměstnance uvidí klient na portálu. Telefon se v MVP nikdy nezobrazuje.</p>

              <!-- Master toggle -->
              <div class="gdpr-master">
                <label class="gdpr-toggle-item" style="font-weight:600; font-size:14px;">
                  <button class="toggle-btn" :class="{ 'toggle-on': emp.showInPortal }" @click="emp.showInPortal = !emp.showInPortal">
                    <span class="toggle-knob" />
                  </button>
                  <span>Zobrazit v portálu</span>
                  <span class="badge" :class="emp.showInPortal ? 'badge-success' : 'badge-gray'" style="font-size:11px; margin-left:4px;">
                    {{ emp.showInPortal ? 'ANO' : 'NE' }}
                  </span>
                </label>
              </div>

              <div class="gdpr-grid">
                <label class="gdpr-toggle-item">
                  <button class="toggle-btn toggle-sm" :class="{ 'toggle-on': emp.showPhoto }" @click="emp.showPhoto = !emp.showPhoto">
                    <span class="toggle-knob" />
                  </button>
                  <span>Fotografie</span>
                </label>
                <label class="gdpr-toggle-item">
                  <button class="toggle-btn toggle-sm" :class="{ 'toggle-on': emp.showRole }" @click="emp.showRole = !emp.showRole">
                    <span class="toggle-knob" />
                  </button>
                  <span>Pozice</span>
                </label>
                <label class="gdpr-toggle-item">
                  <button class="toggle-btn toggle-sm" :class="{ 'toggle-on': emp.showTenure }" @click="emp.showTenure = !emp.showTenure">
                    <span class="toggle-knob" />
                  </button>
                  <span>Délka spolupráce</span>
                </label>
                <label class="gdpr-toggle-item">
                  <button class="toggle-btn toggle-sm" :class="{ 'toggle-on': emp.showBio }" @click="emp.showBio = !emp.showBio">
                    <span class="toggle-knob" />
                  </button>
                  <span>Bio</span>
                </label>
                <label class="gdpr-toggle-item">
                  <button class="toggle-btn toggle-sm" :class="{ 'toggle-on': emp.showHobbies }" @click="emp.showHobbies = !emp.showHobbies">
                    <span class="toggle-knob" />
                  </button>
                  <span>Záliby</span>
                </label>
                <label class="gdpr-toggle-item gdpr-disabled" title="V MVP se telefon klientům nikdy nezobrazuje">
                  <button class="toggle-btn toggle-sm" :class="{ 'toggle-on': emp.showPhone }" @click="emp.showPhone = !emp.showPhone" disabled>
                    <span class="toggle-knob" />
                  </button>
                  <span>Telefon <span class="field-hint" style="margin:0;">(MVP: skrytý)</span></span>
                </label>
              </div>
            </div>

          </div><!-- /emp-card-body -->
        </div>
      </div>
    </div>

    <!-- Bottom save bar (only show when there are employees to save) -->
    <div v-if="employees.length > 0" id="employees-save-bar" class="bottom-save-bar">
      <span v-if="saved" class="saved-msg"><CheckCircle2 :size="15" /> Změny uloženy</span>
      <button id="employees-save-btn" class="btn btn-primary" :disabled="saving" @click="save">
        <Save :size="16" />
        {{ saving ? 'Ukládám...' : 'Uložit změny' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
/* Stats */
.emp-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 20px;
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

/* Toolbar */
.table-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
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
}
.search-input {
  width: 100%;
  padding-left: 32px;
  font-size: 13px;
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
}
.emp-card-header:hover { background: var(--color-gray-50); }

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

.form-textarea { resize: vertical; min-height: 72px; }

/* Upload sections */
.upload-section {
  padding: 14px 0;
  border-top: 1px solid var(--color-gray-100);
  margin-top: 4px;
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

.gdpr-master {
  padding-bottom: 12px;
  margin-bottom: 12px;
  border-bottom: 1px solid var(--color-gray-200);
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
  cursor: pointer;
}

.gdpr-disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.gdpr-disabled button { cursor: not-allowed; }

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

/* Empty hint */
.empty-list-hint {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 20px 16px;
  border: 2px dashed var(--color-gray-200);
  border-radius: var(--radius-md);
  font-size: 13px;
  color: var(--color-gray-500);
}

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

/* Responsive */
@media (max-width: 900px) {
  .emp-stats { grid-template-columns: repeat(2, 1fr); }
  .field-grid-2 { grid-template-columns: 1fr; }
  .gdpr-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 600px) {
  .emp-stats { grid-template-columns: 1fr 1fr; }
  .gdpr-grid { grid-template-columns: 1fr; }
  .emp-header-badges { display: none; }
}
</style>
