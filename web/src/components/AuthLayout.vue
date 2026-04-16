<script setup>
import logoSrc from '../assets/logo.svg'

defineProps({
  tagline: {
    type: String,
    default: ''
  },
  showFooter: {
    type: Boolean,
    default: true
  }
})
</script>

<template>
  <div id="auth-page" class="auth-page">
    <div id="auth-card" class="auth-card">
      <!-- Logo -->
      <div id="auth-logo" class="auth-logo">
        <div id="auth-logo-wrapper" class="auth-logo-wrapper">
          <img id="auth-logo-img" :src="logoSrc" alt="Fajn Úklid logo" class="auth-logo-img" />
        </div>
        <p v-if="tagline" id="auth-tagline" class="auth-tagline">{{ tagline }}</p>
      </div>

      <!-- Content slot -->
      <slot></slot>
    </div>

    <footer v-if="showFooter" id="auth-footer" class="auth-footer">
      © {{ new Date().getFullYear() }} FAJN UKLID s.r.o. - Klientský portál
    </footer>
  </div>
</template>

<style scoped>
.auth-page {
  min-height: 100vh;
  background: var(--gradient-auth-bg);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.auth-card {
  background: var(--color-white);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-auth-card);
  padding: 32px 24px;
  width: 100%;
  max-width: 420px;
}
@media (min-width: 480px) {
  .auth-card {
    padding: 40px 36px;
  }
}

.auth-logo {
  text-align: center;
  margin-bottom: 32px;
}

.auth-logo-wrapper {
  background: var(--color-primary);
  border-radius: 14px;
  padding: 16px 24px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 12px;
}

.auth-logo-img {
  height: 40px;
  width: auto;
}

.auth-tagline {
  font-size: 14px;
  color: var(--color-gray-600);
}

.auth-footer {
  margin-top: 24px;
  font-size: 12px;
  color: var(--color-text-on-gradient);
  text-align: center;
}

/* auth-card handled mobile-first above */
</style>
