<script setup>
import { ref } from 'vue'
import { Mail, ArrowLeft, CheckCircle } from 'lucide-vue-next'

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
  <div class="login-page">
    <div class="login-card">
      <div class="login-logo">
        <div class="logo-circle">FÚ</div>
        <h1 class="login-brand">FAJN ÚKLID</h1>
      </div>

      <!-- Success state -->
      <div v-if="sent" class="success-state">
        <CheckCircle :size="48" color="#198754" />
        <h2>Odkaz odeslán</h2>
        <p>Na adresu <strong>{{ email }}</strong> jsme odeslali odkaz pro reset hesla. Zkontrolujte prosím svou schránku.</p>
        <RouterLink to="/login" class="btn btn-primary btn-full" style="margin-top:20px; justify-content:center;">
          Zpět na přihlášení
        </RouterLink>
      </div>

      <!-- Form -->
      <div v-else>
        <h2 class="form-heading">Zapomenuté heslo</h2>
        <p class="form-desc">Zadejte svůj e-mail a my vám pošleme odkaz pro reset hesla.</p>

        <form @submit.prevent="submit" class="login-form">
          <div class="form-group">
            <label class="form-label">E-mail</label>
            <input
              v-model="email"
              type="email"
              class="form-input"
              placeholder="váš@email.cz"
              required
            />
          </div>

          <button type="submit" class="btn btn-primary btn-full btn-lg" :disabled="loading">
            <Mail v-if="!loading" :size="18" />
            {{ loading ? 'Odesílám...' : 'Odeslat odkaz pro reset' }}
          </button>
        </form>

        <div style="text-align:center; margin-top:20px;">
          <RouterLink to="/login" class="back-link">
            <ArrowLeft :size="14" style="vertical-align:middle;" />
            Zpět na přihlášení
          </RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  background: linear-gradient(135deg, #162438 0%, #1e3554 50%, #d1dff0 100%);
  display: flex;
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
  margin-bottom: 24px;
}

.logo-circle {
  width: 52px;
  height: 52px;
  background: var(--color-primary);
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 18px;
  font-weight: 700;
  margin-bottom: 10px;
}

.login-brand {
  font-size: 20px;
  font-weight: 700;
  color: var(--color-primary);
  letter-spacing: 0.06em;
}

.form-heading {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 8px;
}

.form-desc {
  font-size: 13px;
  color: var(--color-gray-600);
  margin-bottom: 20px;
  line-height: 1.5;
}

.login-form {
  display: flex;
  flex-direction: column;
}

.success-state {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.success-state h2 {
  font-size: 20px;
  font-weight: 600;
  color: var(--color-success);
}

.success-state p {
  font-size: 14px;
  color: var(--color-gray-600);
  line-height: 1.6;
}

.back-link {
  font-size: 13px;
  color: var(--color-mid);
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.back-link:hover {
  color: var(--color-primary);
}
</style>
