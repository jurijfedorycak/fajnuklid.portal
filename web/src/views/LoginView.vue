<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { Eye, EyeOff, LogIn } from 'lucide-vue-next'
import { useAuth } from '../stores/auth'

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
  <div class="login-page">
    <div class="login-card">
      <!-- Logo -->
      <div class="login-logo">
        <div class="logo-circle">FU</div>
        <h1 class="login-brand">FAJN UKLID</h1>
        <p class="login-tagline">Vas klientsky portal</p>
      </div>

      <form @submit.prevent="handleLogin" class="login-form">
        <div class="form-group">
          <label class="form-label">E-mail</label>
          <input
            v-model="email"
            type="email"
            class="form-input"
            placeholder="vas@email.cz"
            autocomplete="email"
          />
        </div>

        <div class="form-group">
          <label class="form-label">Heslo</label>
          <div class="input-with-icon">
            <input
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              class="form-input"
              placeholder="********"
              autocomplete="current-password"
            />
            <button
              type="button"
              class="input-eye"
              @click="showPassword = !showPassword"
              tabindex="-1"
            >
              <EyeOff v-if="showPassword" :size="16" />
              <Eye v-else :size="16" />
            </button>
          </div>
        </div>

        <div v-if="error" class="alert alert-danger" style="margin-bottom:12px; font-size:13px;">
          {{ error }}
        </div>

        <button type="submit" class="btn btn-primary btn-full btn-lg" :disabled="loading">
          <LogIn v-if="!loading" :size="18" />
          <span>{{ loading ? 'Prihlasuji...' : 'Prihlasit se' }}</span>
        </button>

        <div class="login-forgot">
          <RouterLink to="/zapomenute-heslo">Zapomneli jste heslo?</RouterLink>
        </div>
      </form>
    </div>

    <footer class="login-footer">
      (c) {{ new Date().getFullYear() }} FAJN UKLID s.r.o. - Klientsky portal
    </footer>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  background: linear-gradient(135deg, #162438 0%, #1e3554 50%, #d1dff0 100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.login-card {
  background: white;
  border-radius: 16px;
  box-shadow: 0 8px 32px rgba(22,36,56,0.2);
  padding: 40px 36px;
  width: 100%;
  max-width: 420px;
}

.login-logo {
  text-align: center;
  margin-bottom: 32px;
}

.logo-circle {
  width: 60px;
  height: 60px;
  background: var(--color-primary);
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 20px;
  font-weight: 700;
  margin-bottom: 12px;
}

.login-brand {
  font-size: 22px;
  font-weight: 700;
  color: var(--color-primary);
  letter-spacing: 0.06em;
  margin-bottom: 4px;
}

.login-tagline {
  font-size: 14px;
  color: var(--color-gray-600);
}

.login-form {
  display: flex;
  flex-direction: column;
}

.input-with-icon {
  position: relative;
}

.input-with-icon .form-input {
  width: 100%;
  padding-right: 40px;
}

.input-eye {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: var(--color-gray-500);
  padding: 4px;
  cursor: pointer;
  display: flex;
  align-items: center;
}

.input-eye:hover {
  color: var(--color-primary);
}

.login-forgot {
  text-align: center;
  margin-top: 16px;
  font-size: 13px;
}

.login-footer {
  margin-top: 24px;
  font-size: 12px;
  color: rgba(255,255,255,0.5);
  text-align: center;
}
</style>
