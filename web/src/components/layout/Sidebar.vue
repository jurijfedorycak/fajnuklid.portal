<script setup>
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  LayoutDashboard, FileText, Users, FileSignature,
  Clock, Phone, Settings, LogOut, ShieldCheck,
} from 'lucide-vue-next'
import { currentUser } from '../../data/mockData.js'

defineProps({ open: Boolean })
const emit = defineEmits(['close'])

const router = useRouter()
const route = useRoute()

const isAdmin = computed(() => sessionStorage.getItem('mock_admin') === 'true')

const navItems = [
  { name: 'Přehled',      route: '/',          icon: LayoutDashboard },
  { name: 'Faktury',      route: '/faktury',   icon: FileText },
  { name: 'Personál',     route: '/personal',  icon: Users },
  { name: 'Smlouva',      route: '/smlouva',   icon: FileSignature },
  { name: 'Docházka',     route: '/dochazka',  icon: Clock, soon: true },
  { name: 'Kontakt',      route: '/kontakt',   icon: Phone },
]

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

function toggleAdmin() {
  const current = sessionStorage.getItem('mock_admin') === 'true'
  sessionStorage.setItem('mock_admin', String(!current))
  if (!current) {
    router.push('/admin')
  } else {
    router.push('/')
  }
  emit('close')
}

function logout() {
  sessionStorage.removeItem('mock_auth')
  sessionStorage.removeItem('mock_admin')
  router.push('/login')
}

function initials(name) {
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}
</script>

<template>
  <aside class="sidebar" :class="{ open }">
    <!-- Logo -->
    <div class="sidebar-logo">
      <span class="logo-mark">FÚ</span>
      <span class="logo-text">FAJN ÚKLID</span>
    </div>

    <!-- Client info -->
    <div class="sidebar-client">
      <div class="avatar avatar-sm client-avatar">{{ initials(currentUser.displayName) }}</div>
      <div class="client-info">
        <div class="client-name">{{ currentUser.displayName }}</div>
        <div class="client-ico">IČO: {{ currentUser.activeIco }}</div>
      </div>
    </div>

    <div class="sidebar-sep" />

    <!-- Nav -->
    <nav class="sidebar-nav">
      <button
        v-for="item in navItems"
        :key="item.route"
        class="nav-item"
        :class="{ active: isActive(item.route) }"
        @click="navigate(item.route)"
      >
        <component :is="item.icon" class="nav-icon" :size="18" />
        <span class="nav-label">{{ item.name }}</span>
        <span v-if="item.soon" class="nav-soon">Brzy</span>
      </button>
    </nav>

    <div class="sidebar-sep" />

    <!-- Bottom items -->
    <nav class="sidebar-nav sidebar-bottom">
      <button
        v-for="item in bottomItems"
        :key="item.route"
        class="nav-item"
        :class="{ active: isActive(item.route) }"
        @click="navigate(item.route)"
      >
        <component :is="item.icon" class="nav-icon" :size="18" />
        <span class="nav-label">{{ item.name }}</span>
      </button>

      <!-- Admin toggle (mockup switcher) -->
      <button class="nav-item admin-toggle" @click="toggleAdmin">
        <ShieldCheck class="nav-icon" :size="18" />
        <span class="nav-label">{{ isAdmin ? 'Klientský pohled' : 'Admin pohled' }}</span>
      </button>

      <button class="nav-item logout-item" @click="logout">
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
  gap: 10px;
  padding: 20px 16px 16px;
  border-bottom: 1px solid rgba(255,255,255,0.08);
}

.logo-mark {
  width: 36px;
  height: 36px;
  background: var(--color-mid);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
  flex-shrink: 0;
}

.logo-text {
  font-size: 15px;
  font-weight: 700;
  letter-spacing: 0.08em;
  color: white;
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

.nav-soon {
  font-size: 10px;
  font-weight: 600;
  background: rgba(255,255,255,0.15);
  padding: 2px 7px;
  border-radius: 10px;
  letter-spacing: 0.03em;
}

.admin-toggle {
  color: rgba(209,223,240,0.8);
}

.admin-toggle:hover {
  background: rgba(209,223,240,0.1);
  color: var(--color-light);
}

.logout-item:hover {
  background: rgba(220, 53, 69, 0.15);
  color: #f8d7da;
}
</style>
