<script setup>
import { ref } from 'vue'
import Sidebar from '../components/layout/Sidebar.vue'
import logoSrc from '../assets/logo.svg'

const sidebarOpen = ref(false)
</script>

<template>
  <div class="app-layout">
    <!-- Mobile overlay -->
    <div
      v-if="sidebarOpen"
      class="sidebar-overlay"
      @click="sidebarOpen = false"
    />

    <Sidebar :open="sidebarOpen" @close="sidebarOpen = false" />

    <div class="main-wrapper">
      <!-- Mobile topbar -->
      <header id="mobile-topbar" class="mobile-topbar">
        <button id="mobile-hamburger-btn" class="hamburger" @click="sidebarOpen = true" aria-label="Otevřít menu">
          <span /><span /><span />
        </button>
        <img id="mobile-logo" :src="logoSrc" alt="Fajn Úklid" class="mobile-logo" />
      </header>

      <main class="main-content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
/* Mobile-first: no sidebar margin, topbar visible, smaller padding */
.app-layout {
  display: flex;
  height: 100%;
  min-height: 100vh;
}

.sidebar-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.4);
  z-index: 99;
}

/* Hide overlay on desktop — sidebar is persistent, no drawer behavior needed */
@media (min-width: 768px) {
  .sidebar-overlay {
    display: none;
  }
}

.main-wrapper {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  overflow: hidden;
}

.main-content {
  flex: 1;
  padding: var(--space-lg) var(--space-lg);
  overflow-y: auto;
}
@media (min-width: 480px) {
  .main-content {
    padding: var(--space-xl) var(--space-xl);
  }
}

/* Mobile topbar — visible by default, hidden on desktop */
.mobile-topbar {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px 16px;
  background: var(--color-primary);
  color: white;
  position: sticky;
  top: 0;
  z-index: 50;
}

.mobile-logo {
  height: 24px;
  width: auto;
}

.hamburger {
  display: flex;
  flex-direction: column;
  gap: 4px;
  background: none;
  border: none;
  padding: 10px;
  min-width: 44px;
  min-height: 44px;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  border-radius: var(--radius-sm);
}
.hamburger:focus-visible {
  outline: 2px solid var(--color-light);
  outline-offset: 2px;
}
.hamburger span {
  display: block;
  width: 22px;
  height: 2px;
  background: white;
  border-radius: 2px;
}

/* Desktop: persistent sidebar, hide mobile topbar, spacious padding */
@media (min-width: 768px) {
  .main-wrapper {
    margin-left: var(--sidebar-width);
  }
  .mobile-topbar {
    display: none;
  }
  .main-content {
    padding: var(--space-2xl);
  }
}
</style>
