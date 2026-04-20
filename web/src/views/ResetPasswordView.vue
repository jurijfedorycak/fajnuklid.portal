<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { KeyRound, CheckCircle } from 'lucide-vue-next'
import AuthLayout from '../components/AuthLayout.vue'

const router = useRouter()
const newPassword = ref('')
const confirmPassword = ref('')
const error = ref('')
const done = ref(false)
const loading = ref(false)

function submit() {
  error.value = ''
  if (!newPassword.value || !confirmPassword.value) {
    error.value = 'Vyplňte obě pole.'
    return
  }
  if (newPassword.value.length < 8) {
    error.value = 'Heslo musí mít alespoň 8 znaků.'
    return
  }
  if (newPassword.value !== confirmPassword.value) {
    error.value = 'Hesla se neshodují.'
    return
  }
  loading.value = true
  setTimeout(() => {
    loading.value = false
    done.value = true
    setTimeout(() => router.push('/'), 2000)
  }, 900)
}
</script>

<template>
  <AuthLayout>
    <Transition name="fade" mode="out-in">
      <div v-if="done" id="reset-success-state" class="auth-success-state">
        <CheckCircle :size="48" class="auth-success-icon" />
        <h2 id="reset-success-heading">Heslo změněno</h2>
        <p id="reset-success-message">
          Vaše heslo bylo úspěšně nastaveno. Budete přesměrováni na
          přihlášení...
        </p>
      </div>

      <div v-else id="reset-form-container">
        <h2 id="reset-heading" class="auth-form-heading">Nastavit nové heslo</h2>
        <p id="reset-description" class="auth-form-desc">
          Zvolte si nové heslo pro přístup do portálu.
        </p>

        <form
          id="reset-form"
          @submit.prevent="submit"
          class="auth-form"
          :aria-busy="loading"
        >
          <div class="form-group">
            <label class="form-label" for="reset-new-password-input">
              Nové heslo
              <span class="required-indicator" aria-hidden="true">*</span>
            </label>
            <input
              id="reset-new-password-input"
              v-model="newPassword"
              type="password"
              class="form-input"
              placeholder="Minimálně 8 znaků"
              autocomplete="new-password"
              aria-required="true"
              aria-describedby="reset-password-requirements"
            />
            <p id="reset-password-requirements" class="password-requirements">
              Heslo musí obsahovat minimálně 8 znaků.
            </p>
          </div>

          <div class="form-group">
            <label class="form-label" for="reset-confirm-password-input">
              Potvrdit nové heslo
              <span class="required-indicator" aria-hidden="true">*</span>
            </label>
            <input
              id="reset-confirm-password-input"
              v-model="confirmPassword"
              type="password"
              class="form-input"
              placeholder="Zopakujte heslo"
              autocomplete="new-password"
              aria-required="true"
            />
          </div>

          <div
            v-if="error"
            id="reset-error-message"
            class="alert alert-danger auth-alert"
            role="alert"
          >
            {{ error }}
          </div>

          <button
            id="reset-submit-btn"
            type="submit"
            class="btn btn-primary btn-full btn-lg"
            :disabled="loading"
          >
            <KeyRound v-if="!loading" :size="18" />
            {{ loading ? 'Ukládám...' : 'Nastavit nové heslo' }}
          </button>
        </form>
      </div>
    </Transition>
  </AuthLayout>
</template>
