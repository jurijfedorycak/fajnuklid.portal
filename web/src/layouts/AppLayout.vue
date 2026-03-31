<script setup>
import { ref } from 'vue'
import Sidebar from '../components/layout/Sidebar.vue'

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
      <header class="mobile-topbar">
        <button class="hamburger" @click="sidebarOpen = true" aria-label="Otevřít menu">
          <span /><span /><span />
        </button>
        <span class="mobile-logo">FAJN ÚKLID</span>
      </header>

      <main class="main-content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
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

.main-wrapper {
  flex: 1;
  margin-left: var(--sidebar-width);
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  overflow: hidden;
}

.main-content {
  flex: 1;
  padding: 32px;
  overflow-y: auto;
}

/* Mobile topbar */
.mobile-topbar {
  display: none;
  align-items: center;
  gap: 16px;
  padding: 12px 20px;
  background: var(--color-primary);
  color: white;
  position: sticky;
  top: 0;
  z-index: 50;
}

.mobile-logo {
  font-weight: 700;
  font-size: 16px;
  letter-spacing: 0.05em;
}

.hamburger {
  display: flex;
  flex-direction: column;
  gap: 4px;
  background: none;
  border: none;
  padding: 4px;
  cursor: pointer;
}
.hamburger span {
  display: block;
  width: 22px;
  height: 2px;
  background: white;
  border-radius: 2px;
}

@media (max-width: 768px) {
  .main-wrapper {
    margin-left: 0;
  }
  .mobile-topbar {
    display: flex;
  }
  .main-content {
    padding: 20px 16px;
  }
}
</style>
