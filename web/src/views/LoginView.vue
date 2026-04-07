<script setup>
import { ref, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import {
  Eye,
  EyeOff,
  LogIn,
  Loader2,
  ClipboardCheck,
  FileText,
  Users,
  MessageSquare,
  Clock,
  Shield
} from 'lucide-vue-next'
import { useAuth } from '../stores/auth'
import logoSrc from '../assets/logo.svg'

const router = useRouter()
const { login, isAdmin } = useAuth()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const error = ref('')
const loading = ref(false)
const errorMessageRef = ref(null)

// Year is static for the duration of the session
const currentYear = new Date().getFullYear()

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
      router.push(isAdmin.value ? '/admin/clients' : '/')
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

const benefits = [
  {
    icon: ClipboardCheck,
    title: 'Přehled docházky',
    description: 'Sledujte, kdo a kdy u vás uklízel. Máte vše pod kontrolou.'
  },
  {
    icon: FileText,
    title: 'Smlouvy a faktury',
    description: 'Všechny dokumenty na jednom místě, kdykoliv k dispozici.'
  },
  {
    icon: Users,
    title: 'Váš úklidový tým',
    description: 'Poznáte své pracovníky a máte na ně přímý kontakt.'
  },
  {
    icon: MessageSquare,
    title: 'Snadná komunikace',
    description: 'Reklamace, požadavky, dotazy – vše vyřídíte online.'
  },
  {
    icon: Clock,
    title: 'Dostupné 24/7',
    description: 'Přístup k informacím kdykoliv, z počítače i mobilu.'
  },
  {
    icon: Shield,
    title: 'Bezpečné a soukromé',
    description: 'Vaše data jsou v bezpečí a vidíte pouze své informace.'
  }
]
</script>

<template>
  <div id="login-page" class="login-page">
    <!-- Skip link for keyboard users -->
    <a id="login-skip-link" href="#login-form" class="skip-link">
      Přeskočit na přihlášení
    </a>

    <div id="login-container" class="login-container">
      <!-- Marketing Panel -->
      <div id="login-marketing-panel" class="login-marketing" aria-hidden="true">
        <div id="login-marketing-content" class="login-marketing-content">
          <h1 id="login-marketing-title" class="login-marketing-title">
            Klientský portál<br />
            <span class="login-marketing-highlight">Fajn Úklid</span>
          </h1>
          <p id="login-marketing-subtitle" class="login-marketing-subtitle">
            Mějte přehled o úklidu vašich prostor. Vše důležité na jednom místě.
          </p>

          <ul id="login-benefits-list" class="login-benefits">
            <li
              v-for="(benefit, index) in benefits"
              :key="index"
              :id="`login-benefit-${index}`"
              class="login-benefit"
            >
              <div class="login-benefit-icon" aria-hidden="true">
                <component :is="benefit.icon" :size="20" />
              </div>
              <div class="login-benefit-text">
                <strong>{{ benefit.title }}</strong>
                <span>{{ benefit.description }}</span>
              </div>
            </li>
          </ul>

          <p id="login-marketing-cta" class="login-marketing-cta">
            Ještě nejste klientem?
            <a
              id="login-contact-link"
              href="https://fajnuklid.cz"
              target="_blank"
              rel="noopener noreferrer"
            >
              Zjistěte více o našich službách
            </a>
          </p>
        </div>
      </div>

      <!-- Login Form Panel -->
      <div id="login-form-panel" class="login-form-panel">
        <div id="login-card" class="login-card">
          <div id="login-logo" class="login-logo">
            <div id="login-logo-wrapper" class="login-logo-wrapper">
              <img
                id="login-logo-img"
                :src="logoSrc"
                alt="Fajn Úklid logo"
                class="login-logo-img"
              />
            </div>
            <p id="login-tagline" class="login-tagline">Váš klientský portál</p>
          </div>

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
                inputmode="email"
                class="form-input"
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
                  :aria-pressed="showPassword"
                >
                  <EyeOff v-if="showPassword" :size="16" aria-hidden="true" />
                  <Eye v-else :size="16" aria-hidden="true" />
                </button>
              </div>
            </div>

            <div
              v-if="error"
              id="login-error-message"
              ref="errorMessageRef"
              class="alert alert-danger auth-alert"
              role="alert"
              aria-live="assertive"
              tabindex="-1"
            >
              {{ error }}
            </div>

            <button
              id="login-submit-btn"
              type="submit"
              class="btn btn-primary btn-full btn-lg"
              :disabled="loading"
            >
              <Loader2 v-if="loading" :size="18" class="spin" aria-hidden="true" />
              <LogIn v-else :size="18" aria-hidden="true" />
              <span>{{ loading ? 'Přihlašuji...' : 'Přihlásit se' }}</span>
            </button>

            <div class="auth-forgot-link">
              <RouterLink id="login-forgot-password-link" to="/zapomenute-heslo">
                Zapomněli jste heslo?
              </RouterLink>
            </div>
          </form>
        </div>

        <footer id="login-footer" class="login-footer">
          © {{ currentYear }} FAJN UKLID s.r.o. - Klientský portál
        </footer>
      </div>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  background: var(--gradient-auth-bg);
}

.login-container {
  display: flex;
  min-height: 100vh;
}

/* Marketing Panel */
.login-marketing {
  flex: 1;
  display: none;
  background: var(--color-primary);
  padding: 3rem;
  color: var(--color-white);
}

.login-marketing-content {
  max-width: 30rem;
  margin: auto;
}

.login-marketing-title {
  font-size: 2rem;
  font-weight: 700;
  line-height: 1.2;
  margin-bottom: 1rem;
}

.login-marketing-highlight {
  color: var(--color-light);
}

.login-marketing-subtitle {
  font-size: 1rem;
  opacity: 0.85;
  margin-bottom: 2.5rem;
  line-height: 1.6;
}

.login-benefits {
  list-style: none;
  padding: 0;
  margin: 0 0 2.5rem 0;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.login-benefit {
  display: flex;
  align-items: flex-start;
  gap: 0.875rem;
}

.login-benefit-icon {
  width: 2.5rem;
  height: 2.5rem;
  background: rgba(255, 255, 255, 0.15);
  border-radius: var(--radius-md);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.login-benefit-text {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}

.login-benefit-text strong {
  font-size: 0.875rem;
  font-weight: 600;
}

.login-benefit-text span {
  font-size: 0.8125rem;
  opacity: 0.75;
  line-height: 1.4;
}

.login-marketing-cta {
  font-size: 0.8125rem;
  opacity: 0.7;
  padding-top: 1.5rem;
  border-top: 1px solid rgba(255, 255, 255, 0.15);
}

.login-marketing-cta a {
  color: var(--color-light);
  text-decoration: underline;
}

.login-marketing-cta a:hover {
  color: var(--color-white);
}

/* Form Panel */
.login-form-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  min-height: 100vh;
}

.login-card {
  background: var(--color-white);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-auth-card);
  padding: 2.5rem 2.25rem;
  width: 100%;
  max-width: 26.25rem;
}

.login-logo {
  text-align: center;
  margin-bottom: 2rem;
}

.login-logo-wrapper {
  background: var(--color-primary);
  border-radius: 0.875rem;
  padding: 1rem 1.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 0.75rem;
}

.login-logo-img {
  height: 2.5rem;
  width: auto;
}

.login-tagline {
  font-size: 0.875rem;
  color: var(--color-gray-600);
}

.login-footer {
  margin-top: 1.5rem;
  font-size: 0.75rem;
  color: var(--color-text-on-gradient);
  text-align: center;
}

/* Desktop: Show marketing panel */
@media (min-width: 64rem) {
  .login-marketing {
    display: flex;
  }

  .login-form-panel {
    flex: 0 0 32.5rem;
    background: var(--gradient-auth-bg);
  }
}

/* Tablet adjustments */
@media (max-width: 63.9375rem) and (min-width: 37.5rem) {
  .login-card {
    padding: 3rem 2.5rem;
  }
}

/* Mobile adjustments */
@media (max-width: 30rem) {
  .login-card {
    padding: 2rem 1.5rem;
    box-shadow: var(--shadow-lg);
  }
}
</style>
