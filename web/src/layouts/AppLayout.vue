<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import Sidebar from '../components/layout/Sidebar.vue'
import MobileBottomNav from '../components/layout/MobileBottomNav.vue'
import logoDarkSrc from '../assets/logo-dark.svg'

const sidebarOpen = ref(false)
const route = useRoute()
// Pages with `chrome: 'floating'` trade the solid logo topbar for a lone
// hamburger floating over a tinted canvas (dashboard hero design).
const floatingChrome = computed(() => route.meta.chrome === 'floating')

function onKeydown(e) {
  if (e.key === 'Escape') sidebarOpen.value = false
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="app-layout">
    <Sidebar :open="sidebarOpen" @close="sidebarOpen = false" />

    <div class="main-wrapper">
      <!-- Mobile topbar — branding only; navigation lives in the floating bottom bar.
           Floating chrome swaps the logo for a hamburger pinned over the content. -->
      <header id="mobile-topbar" class="mobile-topbar" :class="{ 'mobile-topbar--floating': floatingChrome }">
        <img v-if="!floatingChrome" id="mobile-logo" :src="logoDarkSrc" alt="Fajn Úklid" class="mobile-logo" />
        <button
          v-else
          id="mobile-floating-hamburger"
          type="button"
          class="floating-hamburger"
          aria-label="Otevřít menu"
          :aria-expanded="sidebarOpen"
          aria-controls="app-sidebar"
          @click="sidebarOpen = true"
        >
          <span /><span /><span />
        </button>
      </header>

      <main class="main-content" :class="{ 'main-content--tinted': floatingChrome }">
        <RouterView />
      </main>
    </div>

    <MobileBottomNav @open="sidebarOpen = true" />
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

.main-wrapper {
  flex: 1;
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
  min-width: 0;
  /* Anchor for the floating topbar variant */
  position: relative;
}

.main-content {
  flex: 1;
  min-height: 0;
  /* The app shell is position:fixed so body padding can't see the home indicator
     — keep the inset on the scroll container itself instead. The extra bottom
     clearance keeps content from hiding behind the floating bottom nav. */
  padding: var(--space-lg) var(--space-lg) calc(66px + env(safe-area-inset-bottom, 0px));
  overflow-y: auto;
}
@media (min-width: 480px) {
  .main-content {
    padding: var(--space-xl) var(--space-xl) calc(66px + env(safe-area-inset-bottom, 0px));
  }
}

/* Mobile topbar — visible by default, hidden on desktop */
.mobile-topbar {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: calc(12px + env(safe-area-inset-top, 0px)) calc(16px + env(safe-area-inset-right, 0px)) 12px calc(16px + env(safe-area-inset-left, 0px));
  background: var(--page-bg);
  color: var(--color-primary);
  position: sticky;
  top: 0;
  z-index: 50;
}

.mobile-logo {
  height: 24px;
  width: auto;
}

/* Floating chrome: the bar leaves the flow and hovers over the scrolling
   content (main-content is the scroll container, so absolute = pinned).
   Only the hamburger itself accepts clicks. */
.mobile-topbar--floating {
  position: absolute;
  inset: 0 0 auto 0;
  background: transparent;
  pointer-events: none;
}

.floating-hamburger {
  pointer-events: auto;
  margin-left: auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  min-width: 44px;
  min-height: 44px;
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  cursor: pointer;
}
.floating-hamburger:focus-visible {
  outline: 2px solid var(--color-mid);
  outline-offset: 2px;
}
.floating-hamburger span {
  display: block;
  width: 18px;
  height: 2px;
  background: var(--color-primary);
  border-radius: 2px;
}

.main-content--tinted {
  background: var(--page-bg-tinted);
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
