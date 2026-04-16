<script setup>
import { ref, computed, onMounted } from 'vue'
import { User, Lock, Building2, CheckCircle, Eye, EyeOff, Loader2 } from 'lucide-vue-next'
import { settingsService } from '../api'
import { useAuth } from '../stores/auth'

const { user } = useAuth()

// State
const loading = ref(true)
const error = ref(null)
const settingsData = ref({
  icos: [],
})

// Fetch settings
onMounted(async () => {
  try {
    const response = await settingsService.getSettings()
    if (response.success) {
      settingsData.value = response.data
    } else {
      error.value = response.message || 'Nepodařilo se načíst nastavení'
    }
  } catch (err) {
    error.value = err.message || 'Nepodařilo se načíst nastavení'
  } finally {
    loading.value = false
  }
})

// Computed for user data — sourced from the settings API response
const currentUser = computed(() => {
  const cu = settingsData.value.current_user || {}
  return {
    email: cu.email || user.value?.email || '',
    displayName: cu.display_name || '',
    clientId: cu.client_id || '',
    isAdmin: cu.is_admin || false,
    icos: cu.icos || [],
  }
})

const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const showCurrent = ref(false)
const showNew = ref(false)
const passwordError = ref('')
const passwordSuccess = ref(false)
const saving = ref(false)

async function changePassword() {
  passwordError.value = ''
  passwordSuccess.value = false
  if (!currentPassword.value || !newPassword.value || !confirmPassword.value) {
    passwordError.value = 'Vyplňte prosím všechna pole.'
    return
  }
  if (newPassword.value.length < 8) {
    passwordError.value = 'Nové heslo musí mít alespoň 8 znaků.'
    return
  }
  if (newPassword.value !== confirmPassword.value) {
    passwordError.value = 'Nové heslo a potvrzení se neshodují.'
    return
  }

  saving.value = true
  try {
    const response = await settingsService.changePassword(currentPassword.value, newPassword.value, confirmPassword.value)
    if (response.success) {
      passwordSuccess.value = true
      currentPassword.value = ''
      newPassword.value = ''
      confirmPassword.value = ''
      setTimeout(() => { passwordSuccess.value = false }, 4000)
    } else {
      passwordError.value = response.message || 'Změna hesla se nezdařila'
    }
  } catch (err) {
    passwordError.value = err.message || 'Změna hesla se nezdařila'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Nastavení účtu</h1>
        <p class="page-subtitle">Správa vašeho přihlašovacího účtu</p>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám nastavení...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <div v-else class="settings-layout">
      <!-- Profile section -->
      <div class="card settings-section">
        <div class="section-header">
          <User :size="20" class="section-icon" />
          <h2 class="section-title">Profil</h2>
        </div>

        <div class="form-group">
          <label class="form-label">E-mail</label>
          <input
            type="email"
            class="form-input"
            :value="currentUser.email"
            disabled
          />
          <p class="field-note">E-mail slouží jako přihlašovací jméno. Pro změnu přihlašovacího e-mailu kontaktujte nás.</p>
        </div>

        <div class="form-group">
          <label class="form-label">Název firmy</label>
          <input
            type="text"
            class="form-input"
            :value="currentUser.displayName"
            disabled
          />
          <p class="field-note">Název firmy je nastaven správcem portálu.</p>
        </div>

        <div v-if="currentUser.clientId" class="form-group">
          <label class="form-label">ID klienta</label>
          <input
            id="settings-client-id-input"
            type="text"
            class="form-input"
            :value="currentUser.clientId"
            disabled
          />
        </div>
      </div>

      <!-- Password section -->
      <div class="card settings-section">
        <div class="section-header">
          <Lock :size="20" class="section-icon" />
          <h2 class="section-title">Změna hesla</h2>
        </div>

        <div v-if="passwordSuccess" class="alert alert-success" style="margin-bottom:16px;">
          <CheckCircle :size="18" />
          Heslo bylo úspěšně změněno.
        </div>

        <form @submit.prevent="changePassword">
          <div class="form-group">
            <label class="form-label">Stávající heslo</label>
            <div class="input-with-icon">
              <input
                v-model="currentPassword"
                :type="showCurrent ? 'text' : 'password'"
                class="form-input"
                placeholder="Vaše aktuální heslo"
              />
              <button type="button" class="input-eye" @click="showCurrent = !showCurrent" tabindex="-1">
                <EyeOff v-if="showCurrent" :size="15" />
                <Eye v-else :size="15" />
              </button>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Nové heslo</label>
            <div class="input-with-icon">
              <input
                v-model="newPassword"
                :type="showNew ? 'text' : 'password'"
                class="form-input"
                placeholder="Minimálně 8 znaků"
              />
              <button type="button" class="input-eye" @click="showNew = !showNew" tabindex="-1">
                <EyeOff v-if="showNew" :size="15" />
                <Eye v-else :size="15" />
              </button>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Potvrdit nové heslo</label>
            <input
              v-model="confirmPassword"
              type="password"
              class="form-input"
              placeholder="Zopakujte nové heslo"
            />
          </div>

          <div v-if="passwordError" class="alert alert-danger" style="margin-bottom:12px; font-size:13px;">
            {{ passwordError }}
          </div>

          <button type="submit" class="btn btn-primary" :disabled="saving">
            <Lock v-if="!saving" :size="16" />
            {{ saving ? 'Ukládám...' : 'Uložit nové heslo' }}
          </button>
        </form>
      </div>

      <!-- Connected IČOs -->
      <div class="card settings-section" v-if="currentUser.icos.length > 0">
        <div class="section-header">
          <Building2 :size="20" class="section-icon" />
          <h2 class="section-title">Propojené firmy</h2>
        </div>

        <div class="ico-table-wrap table-wrap table-wrap--sticky-first">
          <table class="data-table">
            <thead>
              <tr>
                <th>IČO</th>
                <th>Název firmy</th>
                <th>Adresa</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="ico in currentUser.icos" :key="ico.ico">
                <td class="fw-600" style="color:var(--color-primary)">{{ ico.ico }}</td>
                <td>{{ ico.name }}</td>
                <td class="text-muted">{{ ico.address }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="field-note" style="margin-top:12px;">
          Pro změny v propojených firmách kontaktujte správce portálu.
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.settings-layout {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 640px;
}

.settings-section {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.section-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--color-gray-200);
}

.section-icon {
  color: var(--color-mid);
}

.section-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-primary);
}

.field-note {
  font-size: 12px;
  color: var(--color-gray-500);
  margin-top: 3px;
}

.input-with-icon {
  position: relative;
}

.input-with-icon .form-input {
  width: 100%;
  padding-right: 48px;
}

/* Local override must preserve the 44x44 touch target from global .input-eye */
.input-eye {
  position: absolute;
  right: 4px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: var(--color-gray-500);
  padding: 8px;
  min-width: 44px;
  min-height: 44px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
}

.input-eye:hover {
  color: var(--color-primary);
}
</style>
