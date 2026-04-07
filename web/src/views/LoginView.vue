<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  Eye,
  EyeOff,
  LogIn,
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
    <div id="login-container" class="login-container">
      <!-- Marketing Panel -->
      <div id="login-marketing-panel" class="login-marketing">
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
              <div class="login-benefit-icon">
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
              rel="noopener"
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
        </div>

        <footer id="login-footer" class="login-footer">
          © {{ new Date().getFullYear() }} FAJN UKLID s.r.o. - Klientský portál
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
  padding: 48px;
  color: var(--color-white);
}

.login-marketing-content {
  max-width: 480px;
  margin: auto;
}

.login-marketing-title {
  font-size: 32px;
  font-weight: 700;
  line-height: 1.2;
  margin-bottom: 16px;
}

.login-marketing-highlight {
  color: var(--color-light);
}

.login-marketing-subtitle {
  font-size: 16px;
  opacity: 0.85;
  margin-bottom: 40px;
  line-height: 1.6;
}

.login-benefits {
  list-style: none;
  padding: 0;
  margin: 0 0 40px 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.login-benefit {
  display: flex;
  align-items: flex-start;
  gap: 14px;
}

.login-benefit-icon {
  width: 40px;
  height: 40px;
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
  gap: 2px;
}

.login-benefit-text strong {
  font-size: 14px;
  font-weight: 600;
}

.login-benefit-text span {
  font-size: 13px;
  opacity: 0.75;
  line-height: 1.4;
}

.login-marketing-cta {
  font-size: 13px;
  opacity: 0.7;
  padding-top: 24px;
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
  padding: 24px;
  min-height: 100vh;
}

.login-card {
  background: var(--color-white);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-auth-card);
  padding: 40px 36px;
  width: 100%;
  max-width: 420px;
}

.login-logo {
  text-align: center;
  margin-bottom: 32px;
}

.login-logo-wrapper {
  background: var(--color-primary);
  border-radius: 14px;
  padding: 16px 24px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 12px;
}

.login-logo-img {
  height: 40px;
  width: auto;
}

.login-tagline {
  font-size: 14px;
  color: var(--color-gray-600);
}

.login-footer {
  margin-top: 24px;
  font-size: 12px;
  color: var(--color-text-on-gradient);
  text-align: center;
}

/* Desktop: Show marketing panel */
@media (min-width: 1024px) {
  .login-marketing {
    display: flex;
  }

  .login-form-panel {
    flex: 0 0 520px;
    background: var(--gradient-auth-bg);
  }
}

/* Tablet adjustments */
@media (max-width: 1023px) and (min-width: 600px) {
  .login-card {
    padding: 48px 40px;
  }
}

/* Mobile adjustments */
@media (max-width: 480px) {
  .login-card {
    padding: 32px 24px;
  }
}
</style>
