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
/* Mobile-first: no sidebar margin, topbar visible, smaller padding.
   `position: fixed` anchors the whole app shell to the viewport so the body has zero
   flow content, which means body.scrollHeight === body.clientHeight at all times —
   nothing above main-content can ever scroll, even programmatically via scrollIntoView. */
.app-layout {
  position: fixed;
  inset: 0;
  display: flex;
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
  height: 100%;
  overflow: hidden;
  min-width: 0;
}

.main-content {
  flex: 1;
  min-height: 0;
  /* The app shell is position:fixed so body padding can't see the home indicator
     — keep the inset on the scroll container itself instead. */
  padding: var(--space-lg) var(--space-lg) calc(var(--space-lg) + env(safe-area-inset-bottom, 0));
  overflow-y: auto;
}
@media (min-width: 480px) {
  .main-content {
    padding: var(--space-xl) var(--space-xl) calc(var(--space-xl) + env(safe-area-inset-bottom, 0));
  }
}

/* Mobile topbar — visible by default, hidden on desktop */
.mobile-topbar {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: calc(12px + env(safe-area-inset-top, 0)) calc(16px + env(safe-area-inset-right, 0)) 12px calc(16px + env(safe-area-inset-left, 0));
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
