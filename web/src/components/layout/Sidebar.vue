<script setup>
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  LayoutDashboard, FileText, Users, FileSignature,
  Clock, Phone, Settings, LogOut, UserCog, Palette,
} from 'lucide-vue-next'
import { useAuth } from '../../stores/auth'
import logoSrc from '../../assets/logo.svg'

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
  { name: 'Klienti',      route: '/admin/clients',       icon: Users },
  { name: 'Zaměstnanci',  route: '/admin/employees',     icon: UserCog },
  { name: 'Design',       route: '/admin/design-tokens', icon: Palette },
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

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}
</script>

<template>
  <aside class="sidebar" :class="{ open }">
    <!-- Logo -->
    <div id="sidebar-logo" class="sidebar-logo">
      <img :src="logoSrc" alt="Fajn Úklid" class="sidebar-logo-img" />
    </div>

    <!-- Client info -->
    <div id="sidebar-client" class="sidebar-client">
      <div id="sidebar-client-avatar" class="avatar avatar-sm client-avatar">{{ initials(displayName) }}</div>
      <div class="client-info">
        <div id="sidebar-client-name" class="client-name">{{ displayName }}</div>
        <div id="sidebar-client-ico" class="client-ico" v-if="activeIco && !isAdmin">IČO: {{ activeIco }}</div>
      </div>
    </div>

    <div class="sidebar-sep" />

    <!-- Nav -->
    <nav class="sidebar-nav">
      <button
        v-for="item in navItems"
        :key="item.route"
        :id="`sidebar-nav-${item.name.toLowerCase()}`"
        class="nav-item"
        :class="{ active: isActive(item.route) }"
        @click="navigate(item.route)"
      >
        <component :is="item.icon" class="nav-icon" :size="18" />
        <span class="nav-label">{{ item.name }}</span>
      </button>
    </nav>

    <div class="sidebar-sep" />

    <!-- Bottom items -->
    <nav class="sidebar-nav sidebar-bottom">
      <button
        v-for="item in bottomItems"
        :key="item.route"
        :id="`sidebar-nav-${item.name.toLowerCase()}`"
        class="nav-item"
        :class="{ active: isActive(item.route) }"
        @click="navigate(item.route)"
      >
        <component :is="item.icon" class="nav-icon" :size="18" />
        <span class="nav-label">{{ item.name }}</span>
      </button>

      <button id="sidebar-logout-btn" class="nav-item logout-item" @click="handleLogout">
        <LogOut class="nav-icon" :size="18" />
        <span class="nav-label">Odhlásit se</span>
      </button>
    </nav>
  </aside>
</template>

<style scoped>
.sidebar {
  position: fixed;
  top: 0;
  left: 0;
  width: var(--sidebar-width);
  height: 100vh;
  background: var(--color-primary);
  color: white;
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
  }
  .sidebar.open {
    transform: translateX(0);
  }
}

/* Logo */
.sidebar-logo {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  padding: 20px 16px 16px;
  border-bottom: 1px solid rgba(255,255,255,0.08);
}

.sidebar-logo-img {
  height: 32px;
  width: auto;
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
  color: white;
}

.client-ico {
  font-size: 11px;
  color: rgba(255,255,255,0.55);
  margin-top: 1px;
}

/* Nav */
.sidebar-nav {
  display: flex;
  flex-direction: column;
  padding: 6px 8px;
}

.sidebar-bottom {
  margin-top: auto;
  padding-bottom: 12px;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border-radius: 8px;
  color: rgba(255,255,255,0.7);
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
  background: rgba(255,255,255,0.08);
  color: white;
}

.nav-item.active {
  background: rgba(255,255,255,0.12);
  color: white;
  font-weight: 500;
  box-shadow: inset 3px 0 0 var(--color-light);
}

.nav-icon {
  flex-shrink: 0;
}

.nav-label {
  flex: 1;
}

.logout-item:hover {
  background: rgba(220, 53, 69, 0.15);
  color: var(--color-danger-light);
}
</style>
