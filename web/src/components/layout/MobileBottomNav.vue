<script setup>
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Menu } from 'lucide-vue-next'
import { useNavItems } from '../../composables/useNavItems'

const emit = defineEmits(['open'])

const router = useRouter()
const route = useRoute()
const { navItems } = useNavItems()

// The floating bar surfaces the first three primary items; the rest live behind
// the hamburger, which opens the full sidebar drawer.
const primaryItems = computed(() => navItems.value.slice(0, 3))

function isActive(r) {
  if (r === '/prehled') return route.path === '/prehled'
  return route.path.startsWith(r)
}

function navigate(r) {
  router.push(r)
}

function slugify(name) {
  return (name || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '')
}
</script>

<template>
  <nav id="mobile-bottom-nav" class="bottom-nav" aria-label="Hlavní navigace">
    <div id="mobile-bottom-nav-bar" class="bottom-nav-bar">
      <button
        v-for="item in primaryItems"
        :key="item.route"
        :id="`bottom-nav-${slugify(item.name)}`"
        class="bottom-nav-item"
        :class="{ active: isActive(item.route) }"
        :aria-label="item.name"
        :aria-current="isActive(item.route) ? 'page' : undefined"
        @click="navigate(item.route)"
      >
        <component :is="item.icon" class="bottom-nav-icon" :size="20" />
      </button>

      <button
        id="bottom-nav-menu"
        class="bottom-nav-item"
        aria-label="Otevřít menu"
        @click="emit('open')"
      >
        <Menu class="bottom-nav-icon" :size="20" />
      </button>
    </div>
  </nav>
</template>

<style scoped>
/* Mobile-first floating bar; hidden on desktop where the persistent sidebar takes over. */
.bottom-nav {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 60;
  display: flex;
  justify-content: center;
  padding:
    0
    calc(12px + env(safe-area-inset-right, 0px))
    calc(12px + env(safe-area-inset-bottom, 0px))
    calc(12px + env(safe-area-inset-left, 0px));
  pointer-events: none;
}

.bottom-nav-bar {
  pointer-events: auto;
  display: flex;
  align-items: stretch;
  gap: 2px;
  width: auto;
  max-width: 100%;
  background: var(--color-primary);
  border-radius: var(--radius-pill);
  box-shadow: var(--shadow-md);
  padding: 5px;
}

.bottom-nav-item {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 46px;
  height: 42px;
  border: none;
  background: transparent;
  /* Concentric with the pill: outer radius (--radius-pill) minus the bar's 5px padding */
  border-radius: calc(var(--radius-pill) - 5px);
  color: var(--color-light);
  cursor: pointer;
  transition: var(--transition);
}

.bottom-nav-item:hover {
  color: var(--color-white);
}

.bottom-nav-item.active {
  background: var(--overlay-on-dark);
  color: var(--color-white);
}

.bottom-nav-icon {
  flex-shrink: 0;
}

.bottom-nav-item:focus-visible {
  outline: 2px solid var(--color-white);
  outline-offset: 2px;
}

@media (min-width: 768px) {
  .bottom-nav {
    display: none;
  }
}
</style>
