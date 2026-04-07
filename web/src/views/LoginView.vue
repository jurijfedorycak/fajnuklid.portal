<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { Eye, EyeOff, LogIn } from 'lucide-vue-next'
import { useAuth } from '../stores/auth'
import AuthLayout from '../components/AuthLayout.vue'

const router = useRouter()
const { login, isAdmin } = useAuth()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const error = ref('')
const loading = ref(false)

async function handleLogin() {
  error.value = ''
  if (!email.value || !password.value) {
    error.value = 'Zadejte prosím email a heslo.'
    return
  }

  loading.value = true

  try {
    const result = await login(email.value, password.value)
    if (result.success) {
      router.push(isAdmin.value ? '/admin/clients' : '/')
    } else {
      error.value = result.message || 'Neplatné přihlašovací údaje'
    }
  } catch (err) {
    error.value = err.message || 'Přihlášení se nezdařilo'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthLayout tagline="Váš klientský portál">
    <form
      id="login-form"
      @submit.prevent="handleLogin"
      class="auth-form"
      :aria-busy="loading"
    >
      <div class="form-group">
        <label class="form-label" for="login-email-input">
          E-mail
          <span class="required-indicator" aria-hidden="true">*</span>
        </label>
        <input
          id="login-email-input"
          v-model="email"
          type="email"
          class="form-input"
          placeholder="vas@email.cz"
          autocomplete="email"
          aria-required="true"
          :aria-describedby="error ? 'login-error-message' : undefined"
        />
      </div>

      <div class="form-group">
        <label class="form-label" for="login-password-input">
          Heslo
          <span class="required-indicator" aria-hidden="true">*</span>
        </label>
        <div class="input-with-icon">
          <input
            id="login-password-input"
            v-model="password"
            :type="showPassword ? 'text' : 'password'"
            class="form-input"
            placeholder="********"
            autocomplete="current-password"
            aria-required="true"
            :aria-describedby="error ? 'login-error-message' : undefined"
          />
          <button
            id="login-password-toggle"
            type="button"
            class="input-eye"
            @click="showPassword = !showPassword"
            :aria-label="showPassword ? 'Skrýt heslo' : 'Zobrazit heslo'"
          >
            <EyeOff v-if="showPassword" :size="16" />
            <Eye v-else :size="16" />
          </button>
        </div>
      </div>

      <div
        v-if="error"
        id="login-error-message"
        class="alert alert-danger auth-alert"
        role="alert"
      >
        {{ error }}
      </div>

      <button
        id="login-submit-btn"
        type="submit"
        class="btn btn-primary btn-full btn-lg"
        :disabled="loading"
      >
        <LogIn v-if="!loading" :size="18" />
        <span>{{ loading ? 'Přihlašuji...' : 'Přihlásit se' }}</span>
      </button>

      <div class="auth-forgot-link">
        <RouterLink id="login-forgot-password-link" to="/zapomenute-heslo">
          Zapomněli jste heslo?
        </RouterLink>
      </div>
    </form>
  </AuthLayout>
</template>
