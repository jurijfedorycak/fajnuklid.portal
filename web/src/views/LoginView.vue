<script setup>
import { ref, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { Eye, EyeOff, Loader2 } from 'lucide-vue-next'
import { useAuth } from '../stores/auth'
import { handleExternalClick } from '../utils/openExternal'
import logoDarkSrc from '../assets/logo-dark.svg'

const router = useRouter()
const { login, isAdmin } = useAuth()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const error = ref('')
const loading = ref(false)
const errorMessageRef = ref(null)

async function handleLogin() {
  error.value = ''
  if (!email.value || !password.value) {
    error.value = 'Zadejte prosím email a heslo.'
    await nextTick()
    errorMessageRef.value?.focus()
    return
  }

  loading.value = true

  try {
    const result = await login(email.value, password.value)
    if (result.success) {
      router.push(isAdmin.value ? '/admin/clients' : '/prehled')
    } else {
      error.value = result.message || 'Neplatné přihlašovací údaje'
      await nextTick()
      errorMessageRef.value?.focus()
    }
  } catch (err) {
    error.value = err.message || 'Přihlášení se nezdařilo'
    await nextTick()
    errorMessageRef.value?.focus()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div id="login-page" class="login-page">
    <a id="login-skip-link" href="#login-email-input" class="skip-link">
      Přeskočit na přihlášení
    </a>

    <main id="login-card" class="login-card">
      <img
        id="login-logo"
        :src="logoDarkSrc"
        alt="Fajn Úklid logo"
        class="login-logo"
      />
      <h1 id="login-title" class="login-title">Přihlásit se</h1>

      <form
        id="login-form"
        class="login-form"
        @submit.prevent="handleLogin"
        :aria-busy="loading"
      >
        <div id="login-fields" class="login-fields">
          <input
            id="login-email-input"
            v-model="email"
            type="email"
            inputmode="email"
            class="login-input"
            placeholder="Email"
            aria-label="Email"
            autocomplete="email"
            aria-required="true"
            :aria-describedby="error ? 'login-error-message' : undefined"
          />

          <div id="login-password-wrap" class="login-password-wrap">
            <input
              id="login-password-input"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              class="login-input login-input-password"
              placeholder="Heslo"
              aria-label="Heslo"
              autocomplete="current-password"
              aria-required="true"
              :aria-describedby="error ? 'login-error-message' : undefined"
            />
            <button
              id="login-password-toggle"
              type="button"
              class="login-eye"
              @click="showPassword = !showPassword"
              :aria-label="showPassword ? 'Skrýt heslo' : 'Zobrazit heslo'"
              :aria-pressed="showPassword"
            >
              <EyeOff v-if="showPassword" :size="18" aria-hidden="true" />
              <Eye v-else :size="18" aria-hidden="true" />
            </button>
          </div>
        </div>

        <RouterLink
          id="login-forgot-password-link"
          class="login-forgot"
          to="/zapomenute-heslo"
        >
          Zapomněli jste heslo?
        </RouterLink>

        <div
          v-if="error"
          id="login-error-message"
          ref="errorMessageRef"
          class="alert alert-danger login-alert"
          role="alert"
          aria-live="assertive"
          tabindex="-1"
        >
          {{ error }}
        </div>

        <div id="login-actions" class="login-actions">
          <button
            id="login-submit-btn"
            type="submit"
            class="login-submit"
            :disabled="loading"
          >
            <Loader2 v-if="loading" :size="18" class="spin" aria-hidden="true" />
            <span>{{ loading ? 'Přihlašuji…' : 'Přihlásit se' }}</span>
          </button>

          <p id="login-signup" class="login-signup">
            Nemáte účet?
            <a
              id="login-contact-link"
              href="https://fajnuklid.cz"
              target="_blank"
              rel="noopener noreferrer"
              @click="handleExternalClick($event, 'https://fajnuklid.cz')"
            >
              Kontaktujte nás
            </a>
          </p>
        </div>
      </form>
    </main>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  min-height: 100dvh;
  background-color: var(--color-white);
  display: flex;
  justify-content: center;
  align-items: stretch;
  padding: 12px;
  padding-bottom: calc(12px + env(safe-area-inset-bottom, 0));
}

.login-card {
  background: var(--color-white);
  border-radius: 24px;
  width: 100%;
  display: flex;
  flex-direction: column;
  /* Logo sits high on the screen on phones */
  padding: 10vh 24px 28px;
  padding-top: 10dvh;
}

.login-logo {
  align-self: flex-start;
  height: 34px;
  width: auto;
  margin-bottom: 88px;
}

.login-title {
  font-size: 30px;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--color-gray-900);
  margin-bottom: 36px;
}

.login-form {
  display: flex;
  flex-direction: column;
  flex: 1;
}

.login-fields {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.login-input {
  width: 100%;
  padding: 16px 18px;
  background: var(--auth-input-bg);
  border: none;
  border-radius: 14px;
  font-size: 16px;
  color: var(--color-gray-900);
  outline: none;
  transition: var(--transition);
}

/* Placeholders act as the field labels here — gray-600 keeps WCAG AA contrast on the tinted input */
.login-input::placeholder {
  color: var(--color-gray-600);
  opacity: 1;
}

.login-input:focus-visible {
  box-shadow: 0 0 0 2px var(--color-gray-900);
}

.login-password-wrap {
  position: relative;
}

.login-input-password {
  padding-right: 52px;
}

.login-eye {
  position: absolute;
  right: 4px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: var(--color-gray-500);
  min-width: 44px;
  min-height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.login-eye:hover {
  color: var(--color-gray-900);
}

.login-eye:focus-visible {
  outline: 2px solid var(--color-gray-900);
  outline-offset: -4px;
}

.login-forgot {
  display: block;
  text-align: center;
  margin-top: 22px;
  font-size: 14px;
  color: var(--color-gray-800);
}

.login-forgot:hover {
  color: var(--color-gray-900);
  text-decoration: underline;
}

.login-alert {
  margin-top: 16px;
  font-size: 13px;
}

/* Auto margin anchors the CTA block to the card bottom; padding keeps a minimum gap */
.login-actions {
  margin-top: auto;
  padding-top: 40px;
}

.login-submit {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 17px 18px;
  background: var(--auth-btn-bg);
  color: var(--color-white);
  border: none;
  border-radius: 14px;
  font-size: 15px;
  font-weight: 700;
  transition: var(--transition);
  cursor: pointer;
}

.login-submit:hover:not(:disabled) {
  background: var(--auth-btn-bg-hover);
}

.login-submit:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.login-submit:focus-visible {
  outline: 2px solid var(--color-gray-900);
  outline-offset: 2px;
}

.login-signup {
  text-align: center;
  margin-top: 16px;
  font-size: 13px;
  color: var(--color-gray-500);
}

.login-signup a {
  color: var(--color-gray-900);
  font-weight: 700;
}

.login-signup a:hover {
  text-decoration: underline;
}

/* PC: same card, centered instead of filling the screen */
@media (min-width: 640px) {
  .login-page {
    align-items: center;
    padding: 32px;
  }

  .login-card {
    max-width: 400px;
    min-height: 620px;
    padding: 48px 36px 32px;
  }
}
</style>
