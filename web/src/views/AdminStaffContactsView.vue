<script setup>
import { ref, onMounted } from 'vue'
import {
  Phone, Mail, Plus, Pencil, Trash2, Loader2, Upload, X, Save, Users, GripVertical,
  KeyRound, ShieldOff, ShieldCheck,
} from 'lucide-vue-next'
import { adminService } from '../api'
import FilePreviewModal from '../components/FilePreviewModal.vue'

const loading = ref(true)
const loadError = ref(null)
const contacts = ref([])

// Modal state
const modal = ref({
  show: false,
  mode: 'create', // 'create' | 'edit'
  saving: false,
  error: null,
  fieldErrors: {},
  uploading: false,
  form: emptyForm(),
})

// Delete confirmation
const deleteConfirm = ref({ show: false, id: null, name: '' })

// Password modal
const passwordModal = ref({
  show: false,
  saving: false,
  error: null,
  fieldErrors: {},
  staff: null,
  password: '',
  passwordConfirm: '',
})

// Revoke confirmation
const revokeConfirm = ref({ show: false, saving: false, id: null, name: '' })

// Toast
const toast = ref(null)
let toastTimer = null
function showToast(type, message) {
  toast.value = { type, message }
  if (toastTimer) clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toast.value = null }, 3000)
}

// File preview
const previewModal = ref({ show: false, url: '', filename: '' })
function openPreview(url, filename) {
  previewModal.value = { show: true, url, filename: filename || '' }
}
function closePreview() {
  previewModal.value.show = false
}

// Drag-and-drop reorder state
const dragIndex = ref(null)
const dragOverIndex = ref(null)
const reorderSaving = ref(false)
const reorderError = ref(null)

function emptyForm() {
  return {
    id: null,
    name: '',
    position: '',
    phone: '',
    email: '',
    photo_url: '',
  }
}

async function fetchContacts() {
  loading.value = true
  loadError.value = null
  try {
    const response = await adminService.getStaffContacts(1, 100)
    if (response.success) {
      contacts.value = response.data || []
    } else {
      loadError.value = response.message || 'Nepodařilo se načíst kontakty'
    }
  } catch (err) {
    loadError.value = err.response?.data?.message || err.message || 'Nepodařilo se načíst kontakty'
  } finally {
    loading.value = false
  }
}

onMounted(fetchContacts)

function openCreate() {
  modal.value = {
    show: true,
    mode: 'create',
    saving: false,
    error: null,
    fieldErrors: {},
    uploading: false,
    form: emptyForm(),
  }
}

function openEdit(contact) {
  modal.value = {
    show: true,
    mode: 'edit',
    saving: false,
    error: null,
    fieldErrors: {},
    uploading: false,
    form: {
      id: contact.id,
      name: contact.name || '',
      position: contact.position || '',
      phone: contact.phone || '',
      email: contact.email || '',
      photo_url: contact.photo_url || '',
    },
  }
}

function closeModal() {
  modal.value.show = false
}

function validateForm() {
  const errs = {}
  if (!modal.value.form.name.trim()) {
    errs.name = 'Jméno je povinné'
  }
  const email = modal.value.form.email.trim()
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errs.email = 'Neplatný formát e-mailu'
  }
  modal.value.fieldErrors = errs
  return Object.keys(errs).length === 0
}

async function saveContact() {
  if (!validateForm()) return
  modal.value.saving = true
  modal.value.error = null

  const payload = {
    name: modal.value.form.name.trim(),
    position: modal.value.form.position.trim() || null,
    phone: modal.value.form.phone.trim() || null,
    email: modal.value.form.email.trim() || null,
    photo_url: modal.value.form.photo_url || null,
  }

  try {
    const response = modal.value.mode === 'create'
      ? await adminService.createStaffContact(payload)
      : await adminService.updateStaffContact(modal.value.form.id, payload)

    if (response.success) {
      closeModal()
      await fetchContacts()
    } else {
      modal.value.error = response.message || 'Uložení selhalo'
    }
  } catch (err) {
    const data = err.response?.data
    if (data?.errors) modal.value.fieldErrors = data.errors
    modal.value.error = data?.message || err.message || 'Uložení selhalo'
  } finally {
    modal.value.saving = false
  }
}

async function uploadPhoto(event) {
  const file = event.target.files?.[0]
  if (!file) return
  modal.value.uploading = true
  modal.value.error = null
  try {
    const entity = modal.value.mode === 'edit' && modal.value.form.id
      ? { type: 'staff_contact', id: modal.value.form.id, field: 'photo_url' }
      : null
    const url = await adminService.uploadFile(file, 'staff-contacts', entity)
    if (url) {
      modal.value.form.photo_url = url
      showToast('success', 'Fotografie nahrána')
    } else {
      modal.value.error = 'Nahrání fotografie selhalo'
    }
  } catch (err) {
    modal.value.error = err.response?.data?.message || err.message || 'Nahrání fotografie selhalo'
  } finally {
    modal.value.uploading = false
    event.target.value = ''
  }
}

function askDelete(contact) {
  deleteConfirm.value = { show: true, id: contact.id, name: contact.name }
}

async function confirmDelete() {
  const id = deleteConfirm.value.id
  deleteConfirm.value.show = false
  try {
    const response = await adminService.deleteStaffContact(id)
    if (response.success) {
      await fetchContacts()
    } else {
      loadError.value = response.message || 'Smazání selhalo'
    }
  } catch (err) {
    loadError.value = err.response?.data?.message || err.message || 'Smazání selhalo'
  }
}

function openPasswordModal(contact) {
  passwordModal.value = {
    show: true,
    saving: false,
    error: null,
    fieldErrors: {},
    staff: { id: contact.id, name: contact.name, email: contact.email, login_status: contact.login_status },
    password: '',
    passwordConfirm: '',
  }
}

function closePasswordModal() {
  passwordModal.value.show = false
}

function validatePasswordForm() {
  const errs = {}
  if (!passwordModal.value.password || passwordModal.value.password.length < 8) {
    errs.password = 'Heslo musí mít alespoň 8 znaků'
  }
  if (passwordModal.value.password !== passwordModal.value.passwordConfirm) {
    errs.passwordConfirm = 'Hesla se neshodují'
  }
  passwordModal.value.fieldErrors = errs
  return Object.keys(errs).length === 0
}

async function savePassword() {
  if (!validatePasswordForm()) return
  passwordModal.value.saving = true
  passwordModal.value.error = null
  try {
    const response = await adminService.setStaffContactPassword(
      passwordModal.value.staff.id,
      passwordModal.value.password,
    )
    if (response.success) {
      closePasswordModal()
      showToast('success', 'Heslo bylo nastaveno')
      await fetchContacts()
    } else {
      passwordModal.value.error = response.message || 'Uložení hesla selhalo'
    }
  } catch (err) {
    const data = err.response?.data
    if (data?.errors) passwordModal.value.fieldErrors = data.errors
    passwordModal.value.error = data?.message || err.message || 'Uložení hesla selhalo'
  } finally {
    passwordModal.value.saving = false
  }
}

function askRevoke(contact) {
  revokeConfirm.value = { show: true, saving: false, id: contact.id, name: contact.name }
}

async function confirmRevoke() {
  revokeConfirm.value.saving = true
  try {
    const response = await adminService.revokeStaffContactLogin(revokeConfirm.value.id)
    if (response.success) {
      revokeConfirm.value.show = false
      showToast('success', 'Přístup byl zrušen')
      await fetchContacts()
    } else {
      loadError.value = response.message || 'Zrušení přístupu selhalo'
    }
  } catch (err) {
    loadError.value = err.response?.data?.message || err.message || 'Zrušení přístupu selhalo'
  } finally {
    revokeConfirm.value.saving = false
  }
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}

// ── Drag and drop reordering ────────────────────────────────────────────────
function onDragStart(index, event) {
  dragIndex.value = index
  event.dataTransfer.effectAllowed = 'move'
  // Required for Firefox to actually start the drag
  event.dataTransfer.setData('text/plain', String(index))
}

function onDragOver(index, event) {
  event.preventDefault()
  event.dataTransfer.dropEffect = 'move'
  if (dragOverIndex.value !== index) {
    dragOverIndex.value = index
  }
}

function onDragLeave(index) {
  if (dragOverIndex.value === index) {
    dragOverIndex.value = null
  }
}

async function onDrop(index, event) {
  event.preventDefault()
  const from = dragIndex.value
  dragIndex.value = null
  dragOverIndex.value = null
  if (from === null || from === index) return

  const next = contacts.value.slice()
  const [moved] = next.splice(from, 1)
  next.splice(index, 0, moved)
  contacts.value = next

  await persistOrder()
}

function onDragEnd() {
  dragIndex.value = null
  dragOverIndex.value = null
}

async function persistOrder() {
  reorderSaving.value = true
  reorderError.value = null
  try {
    const ids = contacts.value.map(c => c.id)
    const response = await adminService.reorderStaffContacts(ids)
    if (!response.success) {
      reorderError.value = response.message || 'Uložení pořadí selhalo'
      await fetchContacts()
    }
  } catch (err) {
    reorderError.value = err.response?.data?.message || err.message || 'Uložení pořadí selhalo'
    await fetchContacts()
  } finally {
    reorderSaving.value = false
  }
}
</script>

<template>
  <div id="admin-staff-contacts-view">
    <div id="admin-staff-contacts-header" class="page-header">
      <div>
        <h1 id="admin-staff-contacts-title" class="page-title">Tým FAJN ÚKLID</h1>
        <p class="page-subtitle">Kontaktní osoby zobrazené klientům na stránce Kontakt</p>
      </div>
      <button id="admin-staff-contacts-add-btn" class="btn btn-primary" @click="openCreate">
        <Plus :size="18" />
        Přidat člena
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" id="admin-staff-contacts-loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám kontakty...</p>
    </div>

    <!-- Error -->
    <div v-else-if="loadError" id="admin-staff-contacts-error" class="alert alert-danger">
      {{ loadError }}
    </div>

    <!-- Empty -->
    <div v-else-if="contacts.length === 0" id="admin-staff-contacts-empty" class="card">
      <div class="empty-state">
        <Users :size="40" class="empty-state-icon" />
        <p class="empty-state-title">Zatím nejsou žádné kontakty.</p>
        <p class="empty-state-text">Přidejte prvního člena týmu pomocí tlačítka nahoře.</p>
      </div>
    </div>

    <!-- Reorder status -->
    <div v-if="reorderError" id="admin-staff-contacts-reorder-error" class="alert alert-danger">
      {{ reorderError }}
    </div>
    <div v-else-if="reorderSaving" id="admin-staff-contacts-reorder-saving" class="reorder-saving">
      <Loader2 :size="14" class="spin" /> Ukládám pořadí...
    </div>

    <!-- List -->
    <div v-else id="admin-staff-contacts-list" class="staff-list">
      <p class="staff-list-hint">Přetažením za úchyt vlevo změníte pořadí v seznamu.</p>
      <div
        v-for="(c, idx) in contacts"
        :key="c.id"
        :id="`staff-contact-card-${c.id}`"
        class="card staff-card"
        :class="{
          'is-dragging': dragIndex === idx,
          'is-drag-over': dragOverIndex === idx && dragIndex !== idx,
        }"
        draggable="true"
        @dragstart="onDragStart(idx, $event)"
        @dragover="onDragOver(idx, $event)"
        @dragleave="onDragLeave(idx)"
        @drop="onDrop(idx, $event)"
        @dragend="onDragEnd"
      >
        <div :id="`staff-contact-handle-${c.id}`" class="staff-drag-handle" title="Přetáhnout pro změnu pořadí">
          <GripVertical :size="18" />
        </div>
        <div class="staff-avatar avatar avatar-lg" :class="{ 'clickable': c.photo_url }" @click.stop="c.photo_url && openPreview(c.photo_url, c.name)">
          <img v-if="c.photo_url" :src="c.photo_url" :alt="c.name" />
          <span v-else>{{ initials(c.name) }}</span>
        </div>
        <div class="staff-info">
          <div class="staff-name-row">
            <h3 :id="`staff-contact-name-${c.id}`" class="staff-name">{{ c.name }}</h3>
            <span
              v-if="c.login_status === 'active'"
              :id="`staff-contact-login-badge-${c.id}`"
              class="login-badge login-badge-active"
              title="Tato osoba se může přihlásit jako admin"
            >
              <ShieldCheck :size="12" /> Přístup aktivní
            </span>
            <span
              v-else-if="c.login_status === 'revoked'"
              :id="`staff-contact-login-badge-${c.id}`"
              class="login-badge login-badge-revoked"
              title="Přístup byl zrušen"
            >
              <ShieldOff :size="12" /> Přístup pozastaven
            </span>
          </div>
          <p v-if="c.position" class="staff-position">{{ c.position }}</p>
          <div class="staff-meta">
            <span v-if="c.phone" class="staff-meta-item">
              <Phone :size="13" /> {{ c.phone }}
            </span>
            <span v-if="c.email" class="staff-meta-item">
              <Mail :size="13" /> {{ c.email }}
            </span>
          </div>
        </div>
        <div class="staff-actions">
          <button
            :id="`staff-contact-edit-${c.id}`"
            class="btn btn-outline btn-sm"
            @click="openEdit(c)"
          >
            <Pencil :size="14" /> Upravit
          </button>
          <button
            :id="`staff-contact-password-${c.id}`"
            class="btn btn-outline btn-sm"
            :disabled="!c.email || !c.email.trim()"
            :title="!c.email || !c.email.trim() ? 'Pro nastavení hesla je třeba nejprve uložit e-mail' : ''"
            @click="openPasswordModal(c)"
          >
            <KeyRound :size="14" />
            {{ c.login_status === 'none' ? 'Nastavit heslo' : 'Změnit heslo' }}
          </button>
          <button
            v-if="c.login_status === 'active'"
            :id="`staff-contact-revoke-${c.id}`"
            class="btn btn-outline btn-sm btn-danger-outline"
            @click="askRevoke(c)"
          >
            <ShieldOff :size="14" /> Zrušit přístup
          </button>
          <button
            :id="`staff-contact-delete-${c.id}`"
            class="btn btn-outline btn-sm btn-danger-outline"
            @click="askDelete(c)"
          >
            <Trash2 :size="14" /> Smazat
          </button>
        </div>
      </div>
    </div>

    <!-- Edit/Create Modal -->
    <div v-if="modal.show" id="staff-contact-modal-backdrop" class="modal-backdrop" @click.self="closeModal">
      <div id="staff-contact-modal" class="modal-card">
        <div class="modal-header">
          <h2 id="staff-contact-modal-title" class="modal-title">
            {{ modal.mode === 'create' ? 'Nový člen týmu' : 'Upravit kontakt' }}
          </h2>
          <button id="staff-contact-modal-close" class="icon-btn" @click="closeModal" aria-label="Zavřít">
            <X :size="18" />
          </button>
        </div>

        <div class="modal-body">
          <div v-if="modal.error" class="alert alert-danger" id="staff-contact-modal-error">
            {{ modal.error }}
          </div>

          <div class="form-group">
            <label for="staff-contact-form-name">Jméno *</label>
            <input
              id="staff-contact-form-name"
              v-model="modal.form.name"
              type="text"
              class="form-input"
              :class="{ 'is-invalid': modal.fieldErrors.name }"
              maxlength="255"
            />
            <p v-if="modal.fieldErrors.name" class="form-error">{{ modal.fieldErrors.name }}</p>
          </div>

          <div class="form-group">
            <label for="staff-contact-form-position">Pozice</label>
            <input
              id="staff-contact-form-position"
              v-model="modal.form.position"
              type="text"
              class="form-input"
              maxlength="100"
              placeholder="např. Majitel, Manažer kvality"
            />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="staff-contact-form-phone">Telefon</label>
              <input
                id="staff-contact-form-phone"
                v-model="modal.form.phone"
                type="tel"
                class="form-input"
                maxlength="20"
                placeholder="+420 ..."
              />
            </div>
            <div class="form-group">
              <label for="staff-contact-form-email">E-mail</label>
              <input
                id="staff-contact-form-email"
                v-model="modal.form.email"
                type="email"
                class="form-input"
                :class="{ 'is-invalid': modal.fieldErrors.email }"
                maxlength="255"
              />
              <p v-if="modal.fieldErrors.email" class="form-error">{{ modal.fieldErrors.email }}</p>
            </div>
          </div>

          <div class="form-group">
            <label>Fotografie</label>
            <div class="photo-upload-row">
              <div class="photo-preview avatar avatar-lg" :class="{ 'clickable': modal.form.photo_url }" @click="modal.form.photo_url && openPreview(modal.form.photo_url, modal.form.name)">
                <img v-if="modal.form.photo_url" :src="modal.form.photo_url" alt="Náhled" />
                <span v-else>{{ initials(modal.form.name) }}</span>
              </div>
              <label
                id="staff-contact-form-photo-btn"
                class="btn btn-outline btn-sm"
                :class="{ 'is-loading': modal.uploading }"
              >
                <Upload :size="14" />
                {{ modal.form.photo_url ? 'Změnit' : 'Nahrát' }}
                <input
                  id="staff-contact-form-photo-input"
                  type="file"
                  accept="image/*"
                  style="display:none"
                  @change="uploadPhoto"
                />
              </label>
              <button
                v-if="modal.form.photo_url"
                id="staff-contact-form-photo-clear"
                type="button"
                class="btn btn-outline btn-sm"
                @click="modal.form.photo_url = ''"
              >
                Odstranit
              </button>
            </div>
          </div>

        </div>

        <div class="modal-footer">
          <button
            id="staff-contact-modal-cancel"
            class="btn btn-outline"
            @click="closeModal"
            :disabled="modal.saving"
          >
            Zrušit
          </button>
          <button
            id="staff-contact-modal-save"
            class="btn btn-primary"
            @click="saveContact"
            :disabled="modal.saving || modal.uploading"
          >
            <Loader2 v-if="modal.saving" :size="16" class="spin" />
            <Save v-else :size="16" />
            Uložit
          </button>
        </div>
      </div>
    </div>

    <!-- Delete confirmation -->
    <div
      v-if="deleteConfirm.show"
      id="staff-contact-delete-modal-backdrop"
      class="modal-backdrop"
      @click.self="deleteConfirm.show = false"
    >
      <div id="staff-contact-delete-modal" class="modal-card modal-card-sm">
        <div class="modal-header">
          <h2 class="modal-title">Smazat kontakt?</h2>
        </div>
        <div class="modal-body">
          <p>Opravdu chcete smazat kontakt <strong>{{ deleteConfirm.name }}</strong>?</p>
        </div>
        <div class="modal-footer">
          <button
            id="staff-contact-delete-cancel"
            class="btn btn-outline"
            @click="deleteConfirm.show = false"
          >
            Zrušit
          </button>
          <button
            id="staff-contact-delete-confirm"
            class="btn btn-primary btn-danger"
            @click="confirmDelete"
          >
            Smazat
          </button>
        </div>
      </div>
    </div>

    <!-- Password modal -->
    <div
      v-if="passwordModal.show"
      id="staff-contact-password-modal-backdrop"
      class="modal-backdrop"
      @click.self="closePasswordModal"
    >
      <div id="staff-contact-password-modal" class="modal-card modal-card-sm">
        <div class="modal-header">
          <h2 class="modal-title">
            {{ passwordModal.staff?.login_status === 'none' ? 'Nastavit heslo pro' : 'Změnit heslo pro' }}
            {{ passwordModal.staff?.name }}
          </h2>
          <button
            id="staff-contact-password-modal-close"
            class="icon-btn"
            @click="closePasswordModal"
            aria-label="Zavřít"
          >
            <X :size="18" />
          </button>
        </div>
        <div class="modal-body">
          <div v-if="passwordModal.error" class="alert alert-danger" id="staff-contact-password-error">
            {{ passwordModal.error }}
          </div>

          <div class="form-group">
            <label>Přihlašovací e-mail</label>
            <input
              id="staff-contact-password-email"
              :value="passwordModal.staff?.email || ''"
              type="email"
              class="form-input"
              readonly
            />
            <p v-if="passwordModal.fieldErrors.email" class="form-error">
              {{ passwordModal.fieldErrors.email }}
            </p>
          </div>

          <div class="form-group">
            <label for="staff-contact-password-new">Nové heslo *</label>
            <input
              id="staff-contact-password-new"
              v-model="passwordModal.password"
              type="password"
              class="form-input"
              :class="{ 'is-invalid': passwordModal.fieldErrors.password }"
              autocomplete="new-password"
              maxlength="200"
            />
            <p v-if="passwordModal.fieldErrors.password" class="form-error">
              {{ passwordModal.fieldErrors.password }}
            </p>
            <p class="form-help">Minimálně 8 znaků.</p>
          </div>

          <div class="form-group">
            <label for="staff-contact-password-confirm">Potvrzení hesla *</label>
            <input
              id="staff-contact-password-confirm"
              v-model="passwordModal.passwordConfirm"
              type="password"
              class="form-input"
              :class="{ 'is-invalid': passwordModal.fieldErrors.passwordConfirm }"
              autocomplete="new-password"
              maxlength="200"
            />
            <p v-if="passwordModal.fieldErrors.passwordConfirm" class="form-error">
              {{ passwordModal.fieldErrors.passwordConfirm }}
            </p>
          </div>

          <p class="form-help" style="margin-top:8px;">
            Po uložení získá osoba plný admin přístup do portálu.
          </p>
        </div>
        <div class="modal-footer">
          <button
            id="staff-contact-password-cancel"
            class="btn btn-outline"
            @click="closePasswordModal"
            :disabled="passwordModal.saving"
          >
            Zrušit
          </button>
          <button
            id="staff-contact-password-save"
            class="btn btn-primary"
            @click="savePassword"
            :disabled="passwordModal.saving"
          >
            <Loader2 v-if="passwordModal.saving" :size="16" class="spin" />
            <Save v-else :size="16" />
            Uložit heslo
          </button>
        </div>
      </div>
    </div>

    <!-- Revoke confirmation -->
    <div
      v-if="revokeConfirm.show"
      id="staff-contact-revoke-modal-backdrop"
      class="modal-backdrop"
      @click.self="revokeConfirm.show = false"
    >
      <div id="staff-contact-revoke-modal" class="modal-card modal-card-sm">
        <div class="modal-header">
          <h2 class="modal-title">Zrušit přístup?</h2>
        </div>
        <div class="modal-body">
          <p>
            Zrušit administrátorský přístup pro <strong>{{ revokeConfirm.name }}</strong>?
            Účet zůstane v systému, ale nebude se moci přihlásit.
          </p>
        </div>
        <div class="modal-footer">
          <button
            id="staff-contact-revoke-cancel"
            class="btn btn-outline"
            @click="revokeConfirm.show = false"
            :disabled="revokeConfirm.saving"
          >
            Zrušit
          </button>
          <button
            id="staff-contact-revoke-confirm"
            class="btn btn-primary btn-danger"
            @click="confirmRevoke"
            :disabled="revokeConfirm.saving"
          >
            <Loader2 v-if="revokeConfirm.saving" :size="16" class="spin" />
            Zrušit přístup
          </button>
        </div>
      </div>
    </div>

    <FilePreviewModal
      :show="previewModal.show"
      :url="previewModal.url"
      :filename="previewModal.filename"
      @close="closePreview"
    />

    <div v-if="toast" id="staff-contacts-toast" class="toast" :class="'toast-' + toast.type">
      {{ toast.message }}
    </div>
  </div>
</template>

<style scoped>
.staff-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.staff-list-hint {
  font-size: 12px;
  color: var(--color-gray-500);
  margin: 0 0 4px 4px;
}

.reorder-saving {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--color-gray-600);
  margin-bottom: 8px;
}

/* Mobile-first: grid with actions stacked on a second row; ≥640px switch to horizontal row */
.staff-card {
  display: grid;
  grid-template-columns: auto auto minmax(0, 1fr);
  grid-template-areas:
    "handle avatar info"
    "actions actions actions";
  column-gap: 12px;
  row-gap: 12px;
  padding: 14px;
  align-items: center;
  transition: transform 0.12s ease, box-shadow 0.12s ease, border-color 0.12s ease;
  border: 2px solid transparent;
}
.staff-card.is-dragging {
  opacity: 0.4;
}
.staff-card.is-drag-over {
  border-color: var(--color-mid);
  transform: translateY(-1px);
}

.staff-drag-handle {
  grid-area: handle;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-gray-400);
  cursor: grab;
  flex-shrink: 0;
  padding: 4px;
  border-radius: var(--radius-md);
}
.staff-drag-handle:hover {
  color: var(--color-mid);
  background: var(--color-gray-100);
}
.staff-card.is-dragging .staff-drag-handle {
  cursor: grabbing;
}

.staff-avatar {
  grid-area: avatar;
  background: var(--color-mid);
  color: var(--color-white);
  flex-shrink: 0;
  overflow: hidden;
}
.staff-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.staff-info {
  grid-area: info;
  min-width: 0;
}

.staff-name-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin: 0 0 2px;
}

.staff-name {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-primary);
  margin: 0;
}

.login-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 500;
  padding: 2px 8px;
  border-radius: 999px;
  white-space: nowrap;
}
.login-badge-active {
  background: var(--color-success-light);
  color: var(--color-success);
}
.login-badge-revoked {
  background: var(--color-gray-100);
  color: var(--color-gray-600);
}

.staff-position {
  font-size: 13px;
  color: var(--color-gray-600);
  margin: 0 0 6px;
}

.staff-meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.staff-meta-item {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--color-mid);
  word-break: break-word;
  min-width: 0;
}

.staff-actions {
  grid-area: actions;
  display: flex;
  gap: 8px;
  padding-top: 10px;
  border-top: 1px solid var(--color-gray-100);
}
.staff-actions > .btn {
  flex: 1;
  justify-content: center;
}

@media (min-width: 640px) {
  .staff-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
  }
  .staff-info {
    flex: 1;
  }
  .staff-meta {
    flex-direction: row;
    gap: 14px;
    flex-wrap: wrap;
  }
  .staff-actions {
    padding-top: 0;
    border-top: none;
    flex-shrink: 0;
  }
  .staff-actions > .btn {
    flex: 0 0 auto;
  }
}

.btn-danger-outline {
  color: var(--color-danger);
  border-color: var(--color-danger);
}
.btn-danger-outline:hover {
  background: var(--color-danger);
  color: var(--color-white);
}

/* Modal */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: var(--color-primary);
  background: color-mix(in srgb, var(--color-primary) 55%, transparent);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 16px;
}

.modal-card {
  background: var(--color-white);
  border-radius: var(--radius-lg);
  width: 100%;
  max-width: min(560px, calc(100vw - 32px));
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow-lg);
}
.modal-card-sm {
  max-width: min(420px, calc(100vw - 32px));
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid var(--color-gray-200);
}

.modal-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-primary);
  margin: 0;
}

.icon-btn {
  background: transparent;
  border: none;
  cursor: pointer;
  color: var(--color-gray-500);
  padding: 4px;
  border-radius: var(--radius-md);
}
.icon-btn:hover { background: var(--color-gray-100); }

.modal-body {
  padding: 20px;
  overflow-y: auto;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 14px 20px;
  border-top: 1px solid var(--color-gray-200);
}

.form-group {
  margin-bottom: 14px;
}
.form-group label {
  display: block;
  font-size: 12px;
  font-weight: 500;
  color: var(--color-gray-700);
  margin-bottom: 5px;
}
.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.form-input {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--color-gray-300);
  border-radius: var(--radius-md);
  font-size: 14px;
  font-family: inherit;
}
.form-input:focus {
  outline: none;
  border-color: var(--color-mid);
}
.form-input.is-invalid {
  border-color: var(--color-danger);
}
.form-error {
  color: var(--color-danger);
  font-size: 12px;
  margin: 4px 0 0;
}
.form-help {
  color: var(--color-gray-500);
  font-size: 12px;
  margin: 4px 0 0;
}

.photo-upload-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.photo-preview {
  background: var(--color-mid);
  color: var(--color-white);
  overflow: hidden;
  flex-shrink: 0;
}
.photo-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.empty-state {
  text-align: center;
  padding: 40px 20px;
}
.empty-state-icon {
  color: var(--color-gray-400);
}
.empty-state-title {
  font-weight: 600;
  color: var(--color-gray-700);
  margin: 12px 0 4px;
}
.empty-state-text {
  color: var(--color-gray-500);
  font-size: 13px;
  margin: 0;
}

.spin { animation: spin 1.2s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
