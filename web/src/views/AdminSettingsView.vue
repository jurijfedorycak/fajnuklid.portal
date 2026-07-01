<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Loader2, AlertTriangle, Check, Star } from 'lucide-vue-next'
import { adminService } from '../api'

const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const savedMessage = ref('')

const form = reactive({
  googleReviewUrl: '',
})

const errors = reactive({})

function clearError(field) {
  if (errors[field]) delete errors[field]
  savedMessage.value = ''
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const res = await adminService.getSettings()
    if (res.success) {
      form.googleReviewUrl = res.data.googleReviewUrl || ''
    } else {
      loadError.value = res.message || 'Nepodařilo se načíst nastavení'
    }
  } catch (err) {
    loadError.value = err.response?.data?.message || err.message || 'Nepodařilo se načíst nastavení'
  } finally {
    loading.value = false
  }
}

function validate() {
  Object.keys(errors).forEach((k) => delete errors[k])
  const url = form.googleReviewUrl.trim()
  if (url !== '') {
    if (url.length > 500) {
      errors.google_review_url = 'Odkaz může mít nejvýše 500 znaků'
    } else if (!/^https:\/\//i.test(url)) {
      errors.google_review_url = 'Zadejte platnou adresu (https://...)'
    }
  }
  return Object.keys(errors).length === 0
}

async function save() {
  savedMessage.value = ''
  if (!validate()) return
  saving.value = true
  try {
    const res = await adminService.updateSettings({
      google_review_url: form.googleReviewUrl.trim(),
    })
    if (res.success) {
      form.googleReviewUrl = res.data.googleReviewUrl || ''
      savedMessage.value = res.data.message || 'Nastavení bylo uloženo'
    } else if (res.errors) {
      applyServerErrors(res.errors)
    }
  } catch (err) {
    const data = err.response?.data
    if (data?.errors && typeof data.errors === 'object') {
      applyServerErrors(data.errors)
    } else {
      errors.google_review_url = data?.message || err.message || 'Uložení se nezdařilo'
    }
  } finally {
    saving.value = false
  }
}

function applyServerErrors(serverErrors) {
  for (const [field, messages] of Object.entries(serverErrors)) {
    errors[field] = Array.isArray(messages) ? messages[0] : String(messages)
  }
}

onMounted(load)
</script>

<template>
  <div id="admin-settings-view">
    <div id="admin-settings-header" class="page-header">
      <div>
        <h1 id="admin-settings-title" class="page-title">Nastavení portálu</h1>
        <p class="page-subtitle">Obecná nastavení platná pro celou firmu</p>
      </div>
    </div>

    <div v-if="loading" id="admin-settings-loading" class="card" style="padding:40px; text-align:center;">
      <Loader2 :size="32" class="spin" style="color:var(--color-mid);" />
      <p style="margin-top:12px; color:var(--color-gray-600);">Načítám nastavení...</p>
    </div>

    <div v-else-if="loadError" id="admin-settings-error" class="alert alert-danger">
      {{ loadError }}
    </div>

    <section v-else id="admin-settings-review" class="card">
      <div id="admin-settings-review-head" class="settings-card-head">
        <span class="settings-card-icon" aria-hidden="true">
          <Star :size="20" />
        </span>
        <div>
          <h2 id="admin-settings-review-title" class="settings-card-title">Recenze na Googlu</h2>
          <p class="settings-card-desc">
            Odkaz na váš Google Business profil, kam klienta pošleme, když na přehledu vybere 4–5 hvězdiček.
            Dokud je pole prázdné, blok „Zanechat recenzi“ se klientům nezobrazuje.
          </p>
        </div>
      </div>

      <div class="form-group">
        <label id="label-google-review-url" for="input-google-review-url" class="form-label">
          Odkaz na Google recenze
        </label>
        <input
          id="input-google-review-url"
          v-model="form.googleReviewUrl"
          type="url"
          class="form-input"
          :class="{ 'input-error': errors.google_review_url }"
          placeholder="https://g.page/r/..."
          maxlength="500"
          :aria-invalid="!!errors.google_review_url"
          :aria-describedby="errors.google_review_url ? 'error-google-review-url' : 'hint-google-review-url'"
          @input="clearError('google_review_url')"
        />
        <p v-if="errors.google_review_url" id="error-google-review-url" class="field-error" role="alert">
          <AlertTriangle :size="12" /> {{ errors.google_review_url }}
        </p>
        <p v-else id="hint-google-review-url" class="field-hint">
          Odkaz získáte ve správě Google profilu → Požádat o recenze. Doporučený formát „Napsat recenzi“ (https://g.page/r/...).
        </p>
      </div>

      <div class="settings-card-actions">
        <button
          id="admin-settings-save-btn"
          class="btn btn-primary"
          :disabled="saving"
          @click="save"
        >
          <Loader2 v-if="saving" :size="16" class="spin" />
          <Check v-else :size="16" />
          Uložit
        </button>
        <span v-if="savedMessage" id="admin-settings-saved" class="settings-saved text-success">
          <Check :size="14" /> {{ savedMessage }}
        </span>
      </div>
    </section>
  </div>
</template>

<style scoped>
.settings-card-head {
  display: flex;
  align-items: flex-start;
  gap: var(--space-md);
  margin-bottom: var(--space-lg);
}

.settings-card-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-md);
  background: var(--color-light);
  color: var(--color-mid);
}

.settings-card-title {
  font-size: var(--fs-lg);
  font-weight: 600;
  color: var(--color-primary);
  margin: 0 0 4px;
}

.settings-card-desc {
  font-size: var(--fs-sm);
  color: var(--color-gray-500);
  margin: 0;
  max-width: 60ch;
}

.settings-card-actions {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  margin-top: var(--space-lg);
}

.settings-saved {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: var(--fs-sm);
  font-weight: 500;
}
</style>
