<script setup>
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  LayoutDashboard, FileText, Users, FileSignature,
  Clock, Phone, Settings, LogOut, UserCog, Palette, ArrowRight,
} from 'lucide-vue-next'
import { useAuth } from '../../stores/auth'
import logoDarkSrc from '../../assets/logo-dark.svg'

defineProps({ open: Boolean })
const emit = defineEmits(['close'])

const router = useRouter()
const route = useRoute()
const { user, isAdmin, logout } = useAuth()

const displayName = computed(() => user.value?.display_name || user.value?.email || 'Klient')
const activeIco = computed(() => user.value?.active_ico || '')

const clientNavItems = [
  { name: 'Přehled',      route: '/',          icon: LayoutDashboard },
  { name: 'Faktury',      route: '/faktury',   icon: FileText },
  { name: 'Personál',     route: '/personal',  icon: Users },
  { name: 'Smlouva',      route: '/smlouva',   icon: FileSignature },
  { name: 'Docházka',     route: '/dochazka',  icon: Clock },
  { name: 'Kontakt',      route: '/kontakt',   icon: Phone },
]

const adminNavItems = [
  { name: 'Klienti',      route: '/admin/clients',        icon: Users },
  { name: 'Zaměstnanci',  route: '/admin/employees',      icon: UserCog },
  { name: 'Tým Fajn',     route: '/admin/staff-contacts', icon: Phone },
  { name: 'Design',       route: '/admin/design-tokens',  icon: Palette },
]

const navItems = computed(() => isAdmin.value ? adminNavItems : clientNavItems)

const bottomItems = [
  { name: 'Nastavení',    route: '/nastaveni', icon: Settings },
]

function isActive(r) {
  if (r === '/') return route.path === '/'
  return route.path.startsWith(r)
}

function navigate(r) {
  router.push(r)
  emit('close')
}

async function handleLogout() {
  await logout()
  router.push('/login')
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
    <!-- Logo -->
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
        @click="navigate(item.route)"
      >
        <component :is="item.icon" class="nav-icon" :size="18" />
        <span class="nav-label">{{ item.name }}</span>
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
          @click="navigate(item.route)"
        >
          <component :is="item.icon" class="nav-icon" :size="18" />
          <span class="nav-label">{{ item.name }}</span>
        </button>
      </nav>

      <!-- Client info -->
      <div id="sidebar-client" class="sidebar-client">
        <div id="sidebar-client-avatar" class="avatar avatar-sm client-avatar">{{ initials(displayName) }}</div>
        <div class="client-info">
          <div id="sidebar-client-name" class="client-name">{{ displayName }}</div>
          <div id="sidebar-client-ico" class="client-ico" v-if="activeIco && !isAdmin">IČO: {{ activeIco }}</div>
        </div>
      </div>

      <!-- Logout -->
      <button id="sidebar-logout-btn" class="logout-btn" @click="handleLogout">
        <span class="logout-label">Odhlásit se</span>
        <ArrowRight class="logout-icon" :size="16" />
      </button>
    </div>
  </aside>
</template>

<style scoped>
.sidebar {
  position: fixed;
  top: 0;
  left: 0;
  width: var(--sidebar-width);
  height: 100vh;
  background: var(--sidebar-bg);
  color: var(--sidebar-text);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  z-index: 100;
  transition: transform var(--transition);
}

/* Mobile: hidden by default, slides in */
@media (max-width: 768px) {
  .sidebar {
    transform: translateX(-100%);
    box-shadow: var(--shadow-lg);
  }
  .sidebar.open {
    transform: translateX(0);
  }
}

/* Logo — vertical footprint matches .page-header in main content
   (main-content padding-top 32px + page-header content ~45px + margin-bottom 24px) */
.sidebar-logo {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 16px 46px;
  box-sizing: border-box;
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
  padding: 0 8px;
}

.sidebar-bottom-section .sidebar-nav {
  padding: 6px 8px;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: var(--radius-md);
  color: var(--sidebar-text);
  background: transparent;
  border: none;
  cursor: pointer;
  text-align: left;
  font-size: 14px;
  font-weight: 400;
  transition: var(--transition);
  position: relative;
  width: 100%;
}

.nav-item:hover {
  background: var(--color-gray-100);
  color: var(--sidebar-text);
}

.nav-item.active {
  background: var(--sidebar-active-bg);
  color: var(--sidebar-active-text);
  font-weight: 500;
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
}

/* Bottom section */
.sidebar-bottom-section {
  margin-top: auto;
  padding-bottom: 12px;
}

/* Client info */
.sidebar-client {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
}

.client-avatar {
  background: var(--color-mid);
  color: var(--color-white);
  flex-shrink: 0;
}

.client-info {
  overflow: hidden;
}

.client-name {
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--sidebar-text);
}

.client-ico {
  font-size: 11px;
  color: var(--sidebar-text-muted);
  margin-top: 1px;
}

/* Logout button */
.logout-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: calc(100% - 16px);
  margin: 4px 8px 8px;
  padding: 10px 12px;
  border-radius: var(--radius-md);
  background: transparent;
  border: 1px solid var(--sidebar-border);
  color: var(--sidebar-text-muted);
  font-size: 13px;
  font-weight: 400;
  cursor: pointer;
  transition: var(--transition);
}

.logout-btn:hover {
  background: var(--color-danger-light);
  border-color: var(--color-danger-light);
  color: var(--color-danger);
}

.logout-label {
  flex: 1;
  text-align: left;
}

.logout-icon {
  flex-shrink: 0;
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
</style>
