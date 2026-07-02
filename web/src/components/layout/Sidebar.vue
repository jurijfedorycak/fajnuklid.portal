<script setup>
import { computed, ref, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  Settings, LogOut, ChevronLeft,
} from 'lucide-vue-next'
import { useAuth } from '../../stores/auth'
import { useNavItems } from '../../composables/useNavItems'
import { adminService } from '../../api/services/adminService'
import logoDarkSrc from '../../assets/logo-dark.svg'

defineProps({ open: Boolean })
const emit = defineEmits(['close'])

const router = useRouter()
const route = useRoute()
const { user, isAdmin, logout } = useAuth()
const { navItems: baseNavItems } = useNavItems()

const displayName = computed(() => user.value?.display_name || user.value?.email || 'Klient')
const activeIco = computed(() => user.value?.active_ico || '')

const openRequestsCount = ref(0)

// Inject the admin open-requests badge onto its route without duplicating the
// shared nav definitions.
const navItems = computed(() => baseNavItems.value.map(item =>
  item.route === '/admin/zadosti' && openRequestsCount.value
    ? { ...item, badgeCount: openRequestsCount.value }
    : item
))

async function loadOpenRequestsCount() {
  try {
    const [prijato, resiSe] = await Promise.all([
      adminService.getMaintenanceRequests(null, 'prijato'),
      adminService.getMaintenanceRequests(null, 'resi_se'),
    ])
    const prijatoList = Array.isArray(prijato?.data) ? prijato.data : (Array.isArray(prijato) ? prijato : [])
    const resiSeList = Array.isArray(resiSe?.data) ? resiSe.data : (Array.isArray(resiSe) ? resiSe : [])
    openRequestsCount.value = prijatoList.length + resiSeList.length
  } catch (e) {
    openRequestsCount.value = 0
  }
}

onMounted(() => {
  if (isAdmin.value) loadOpenRequestsCount()
})

watch(isAdmin, (val) => {
  if (val) loadOpenRequestsCount()
  else openRequestsCount.value = 0
})

watch(() => route.path, (path, prev) => {
  if (isAdmin.value && prev?.startsWith('/admin/zadosti') && !path.startsWith('/admin/zadosti')) {
    loadOpenRequestsCount()
  }
})

const bottomItems = [
  { name: 'Nastavení',    route: '/nastaveni', icon: Settings },
]

function isActive(r) {
  if (r === '/prehled') return route.path === '/prehled'
  return route.path.startsWith(r)
}

function navigate(r) {
  router.push(r)
  emit('close')
}

async function handleLogout() {
  await logout()
  router.push('/')
}

function slugify(name) {
  return (name || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '')
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}
</script>

<template>
  <aside class="sidebar" :class="{ open }">
    <!-- Mobile close button -->
    <button id="sidebar-close-btn" class="sidebar-close" @click="emit('close')" aria-label="Zavřít menu">
      <ChevronLeft :size="20" />
    </button>

    <!-- Logo (desktop only) -->
    <div id="sidebar-logo" class="sidebar-logo">
      <img :src="logoDarkSrc" alt="Fajn Úklid" class="sidebar-logo-img" />
    </div>

    <!-- Nav -->
    <nav id="sidebar-nav-main" class="sidebar-nav">
      <button
        v-for="item in navItems"
        :key="item.route"
        :id="`sidebar-nav-${slugify(item.name)}`"
        class="nav-item"
        :class="{ active: isActive(item.route) }"
        :title="item.name"
        @click="navigate(item.route)"
      >
        <component :is="item.icon" class="nav-icon" :size="20" />
        <span class="nav-label">{{ item.name }}</span>
        <span
          v-if="item.badgeCount"
          :id="`sidebar-nav-${slugify(item.name)}-badge`"
          class="nav-badge"
        >{{ item.badgeCount }}</span>
      </button>
    </nav>

    <!-- Bottom section: settings, client info, logout -->
    <div id="sidebar-bottom-section" class="sidebar-bottom-section">
      <!-- Settings -->
      <nav id="sidebar-nav-bottom" class="sidebar-nav">
        <button
          v-for="item in bottomItems"
          :key="item.route"
          :id="`sidebar-nav-${slugify(item.name)}`"
          class="nav-item"
          :class="{ active: isActive(item.route) }"
          :title="item.name"
          @click="navigate(item.route)"
        >
          <component :is="item.icon" class="nav-icon" :size="20" />
          <span class="nav-label">{{ item.name }}</span>
        </button>
      </nav>

      <div id="sidebar-client-sep" class="sidebar-client-sep" />

      <!-- Client info -->
      <div id="sidebar-client" class="sidebar-client">
        <div id="sidebar-client-avatar" class="avatar client-avatar">{{ initials(displayName) }}</div>
        <div class="client-info">
          <div id="sidebar-client-name" class="client-name">{{ displayName }}</div>
          <div id="sidebar-client-ico" class="client-ico" v-if="activeIco && !isAdmin">IČO: {{ activeIco }}</div>
        </div>
      </div>

      <!-- Logout -->
      <button id="sidebar-logout-btn" class="logout-btn" @click="handleLogout">
        <LogOut class="logout-icon" :size="18" />
        <span class="logout-label">Odhlásit se</span>
      </button>
    </div>
  </aside>
</template>

<style scoped>
/* Mobile-first: full-screen menu hidden off-canvas, slides in when .open */
.sidebar {
  position: fixed;
  top: 0;
  left: 0;
  bottom: 0;
  width: 100%;
  max-width: 100%;
  background: var(--sidebar-bg);
  color: var(--sidebar-text);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  z-index: 100;
  padding:
    calc(14px + env(safe-area-inset-top, 0px))
    calc(12px + env(safe-area-inset-right, 0px))
    calc(14px + env(safe-area-inset-bottom, 0px))
    calc(12px + env(safe-area-inset-left, 0px));
  transform: translateX(-100%);
  /* Delayed visibility keeps the closed drawer out of tab order without cutting the slide-out animation */
  visibility: hidden;
  transition: transform var(--transition), visibility 0s var(--transition);
}

.sidebar.open {
  transform: translateX(0);
  visibility: visible;
  transition: transform var(--transition);
}

/* Desktop: persistent narrow sidebar */
@media (min-width: 768px) {
  .sidebar {
    width: var(--sidebar-width);
    max-width: var(--sidebar-width);
    padding: 0;
    transform: none;
    visibility: visible;
  }
}

/* Mobile close button */
.sidebar-close {
  position: relative;
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-gray-100);
  border: none;
  border-radius: var(--radius-md);
  color: var(--color-gray-700);
  margin: 4px 8px 30px;
  cursor: pointer;
  transition: var(--transition);
}

/* Extends the tap target to 44x44 while keeping the 38px visual box */
.sidebar-close::after {
  content: '';
  position: absolute;
  inset: -3px;
}

.sidebar-close:hover {
  background: var(--color-gray-200);
}

@media (min-width: 768px) {
  .sidebar-close {
    display: none;
  }
}

/* Logo — desktop only; vertical footprint matches .page-header in main content
   (main-content padding-top 32px + page-header content ~45px + margin-bottom 24px) */
.sidebar-logo {
  display: none;
}

@media (min-width: 768px) {
  .sidebar-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px 46px;
  }
}

.sidebar-logo-img {
  height: 32px;
  width: auto;
  max-width: 100%;
}

/* Nav — no top padding so first item aligns with page content top */
.sidebar-nav {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 0 8px;
}

.sidebar-bottom-section .sidebar-nav {
  padding: 6px 8px;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 13px 12px;
  border-radius: var(--radius-lg);
  color: var(--sidebar-text);
  background: transparent;
  border: none;
  cursor: pointer;
  text-align: left;
  font-size: 15px;
  font-weight: 400;
  transition: var(--transition);
  position: relative;
  width: 100%;
}

.nav-item:hover {
  background: var(--color-gray-100);
  color: var(--sidebar-text);
}

/* Weight stays 400 in the active state — bolder text widens the longest labels
   past the 240px desktop sidebar and causes a wrap/ellipsis flicker */
.nav-item.active {
  background: var(--sidebar-active-bg);
  color: var(--sidebar-active-text);
}

.nav-icon {
  flex-shrink: 0;
  color: var(--sidebar-text-muted);
}

.nav-item:hover .nav-icon {
  color: var(--sidebar-text);
}

.nav-item.active .nav-icon {
  color: var(--sidebar-active-text);
}

.nav-label {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.nav-badge {
  flex-shrink: 0;
  background: var(--color-danger);
  color: var(--color-white);
  font-size: 11px;
  font-weight: 600;
  line-height: 1;
  padding: 3px 7px;
  border-radius: 999px;
  min-width: 18px;
  text-align: center;
}

/* Bottom section */
.sidebar-bottom-section {
  margin-top: auto;
  padding-bottom: 0;
}

@media (min-width: 768px) {
  .sidebar-bottom-section {
    padding-bottom: 12px;
  }
}

/* Divider above client info */
.sidebar-client-sep {
  height: 1px;
  background: var(--color-gray-200);
  margin: 14px 8px;
}

/* Client info */
.sidebar-client {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 16px 10px;
}

.client-avatar {
  width: 40px;
  height: 40px;
  font-size: 15px;
  background: var(--color-light);
  color: var(--color-accent);
  flex-shrink: 0;
}

.client-info {
  overflow: hidden;
}

.client-name {
  font-size: 15px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--sidebar-text);
}

.client-ico {
  font-size: 12px;
  color: var(--sidebar-text-muted);
  margin-top: 1px;
}

/* Logout button — borderless red text with leading icon */
.logout-btn {
  display: flex;
  align-items: center;
  gap: 12px;
  width: calc(100% - 16px);
  margin: 2px 8px 6px;
  padding: 12px;
  border-radius: var(--radius-lg);
  background: transparent;
  border: none;
  color: var(--color-danger);
  font-size: 15px;
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition);
}

.logout-btn:hover {
  background: var(--color-danger-light);
  color: var(--color-danger);
}

.logout-label {
  flex: 1;
  text-align: left;
}

.logout-icon {
  flex-shrink: 0;
  transform: scaleX(-1);
}

/* Accessibility: Focus styles */
.nav-item:focus-visible {
  outline: 2px solid var(--color-mid);
  outline-offset: 2px;
}

.logout-btn:focus-visible {
  outline: 2px solid var(--color-mid);
  outline-offset: 2px;
}

.sidebar-close:focus-visible {
  outline: 2px solid var(--color-mid);
  outline-offset: 2px;
}
</style>
