<script setup>
import { ref } from 'vue'
import { Mail, ArrowLeft, CheckCircle } from 'lucide-vue-next'
import AuthLayout from '../components/AuthLayout.vue'

const email = ref('')
const sent = ref(false)
const loading = ref(false)

function submit() {
  if (!email.value) return
  loading.value = true
  setTimeout(() => {
    loading.value = false
    sent.value = true
  }, 900)
}
</script>

<template>
  <AuthLayout>
    <!-- Success state -->
    <Transition name="fade" mode="out-in">
      <div v-if="sent" id="forgot-success-state" class="auth-success-state">
        <CheckCircle :size="48" class="auth-success-icon" />
        <h2 id="forgot-success-heading">Odkaz odeslán</h2>
        <p id="forgot-success-message">
          Na adresu <strong>{{ email }}</strong> jsme odeslali odkaz pro reset
          hesla. Zkontrolujte prosím svou schránku.
        </p>
        <RouterLink
          id="forgot-back-to-login-btn"
          to="/"
          class="btn btn-primary btn-full mt-20"
        >
          Zpět na přihlášení
        </RouterLink>
      </div>

      <!-- Form -->
      <div v-else id="forgot-form-container">
        <h2 id="forgot-heading" class="auth-form-heading">Zapomenuté heslo</h2>
        <p id="forgot-description" class="auth-form-desc">
          Zadejte svůj e-mail a my vám pošleme odkaz pro reset hesla.
        </p>

        <form
          id="forgot-form"
          @submit.prevent="submit"
          class="auth-form"
          :aria-busy="loading"
        >
          <div class="form-group">
            <label class="form-label" for="forgot-email-input">
              E-mail
              <span class="required-indicator" aria-hidden="true">*</span>
            </label>
            <input
              id="forgot-email-input"
              v-model="email"
              type="email"
              class="form-input"
              placeholder="váš@email.cz"
              required
              aria-required="true"
            />
          </div>

          <button
            id="forgot-submit-btn"
            type="submit"
            class="btn btn-primary btn-full btn-lg"
            :disabled="loading"
          >
            <Mail v-if="!loading" :size="18" />
            {{ loading ? 'Odesílám...' : 'Odeslat odkaz pro reset' }}
          </button>
        </form>

        <div class="text-center mt-20">
          <RouterLink id="forgot-back-link" to="/" class="auth-back-link">
            <ArrowLeft :size="14" />
            Zpět na přihlášení
          </RouterLink>
        </div>
      </div>
    </Transition>
  </AuthLayout>
</template>
