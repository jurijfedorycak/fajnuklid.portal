import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuth } from '../stores/auth'

const routes = [
  {
    path: '/',
    name: 'Login',
    component: () => import('../views/LoginView.vue'),
    meta: { public: true },
  },
  {
    path: '/zapomenute-heslo',
    name: 'ForgotPassword',
    component: () => import('../views/ForgotPasswordView.vue'),
    meta: { public: true },
  },
  {
    path: '/reset-hesla',
    name: 'ResetPassword',
    component: () => import('../views/ResetPasswordView.vue'),
    meta: { public: true },
  },
  {
    path: '/_app',
    component: () => import('../layouts/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '/prehled',
        name: 'Dashboard',
        component: () => import('../views/DashboardView.vue'),
      },
      {
        path: '/faktury',
        name: 'Invoices',
        component: () => import('../views/InvoicesView.vue'),
      },
      {
        path: '/personal',
        name: 'Personnel',
        component: () => import('../views/PersonnelView.vue'),
      },
      {
        path: '/smlouva',
        name: 'Contract',
        component: () => import('../views/ContractView.vue'),
      },
      {
        path: '/dochazka',
        name: 'Attendance',
        component: () => import('../views/AttendanceView.vue'),
      },
      {
        path: '/zadosti',
        name: 'Requests',
        component: () => import('../views/RequestsView.vue'),
      },
      {
        path: '/zadosti/nova',
        name: 'NewRequest',
        component: () => import('../views/NewRequestView.vue'),
      },
      {
        path: '/zadosti/vytvoreno/:id',
        name: 'RequestCreated',
        component: () => import('../views/RequestCreatedView.vue'),
      },
      {
        path: '/zadosti/:id',
        name: 'RequestDetail',
        component: () => import('../views/RequestDetailView.vue'),
      },
      {
        path: '/kontakt',
        name: 'Contact',
        component: () => import('../views/ContactView.vue'),
      },
      {
        path: '/nastaveni',
        name: 'Settings',
        component: () => import('../views/SettingsView.vue'),
      },
      {
        path: '/admin/clients',
        name: 'Admin',
        component: () => import('../views/AdminView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: '/admin/clients/:id',
        name: 'AdminClientEdit',
        component: () => import('../views/AdminClientEditView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: '/admin/employees',
        name: 'AdminEmployees',
        component: () => import('../views/AdminEmployeesView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: '/admin/staff-contacts',
        name: 'AdminStaffContacts',
        component: () => import('../views/AdminStaffContactsView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: '/admin/zadosti',
        name: 'AdminRequests',
        component: () => import('../views/AdminRequestsView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: '/admin/zadosti/:id',
        name: 'AdminRequestDetail',
        component: () => import('../views/AdminRequestDetailView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: '/admin/design-tokens',
        name: 'AdminDesignTokens',
        component: () => import('../views/AdminDesignTokensView.vue'),
        meta: { requiresAdmin: true },
      },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  // Hash history keeps the route inside location.hash so refreshes/deep-links
  // resolve to index.html on both the deployed web (where there's no server-
  // side rewrite) and the Capacitor WebView (which has no server at all).
  history: createWebHashHistory(),
  routes,
})

router.beforeEach((to) => {
  const { isAuthenticated, isAdmin, attendanceEnabled } = useAuth()

  if (!to.meta.public && !isAuthenticated.value) {
    return { name: 'Login' }
  }

  if (to.name === 'Login' && isAuthenticated.value) {
    return { name: isAdmin.value ? 'Admin' : 'Dashboard' }
  }

  if (to.meta.requiresAdmin && !isAdmin.value) {
    return { name: 'Dashboard' }
  }

  // Clients without an activated QR system can't reach the attendance page —
  // the tab is hidden for them, so a deep link / stale bookmark falls back to
  // the dashboard. Admins are exempt (handled by the preview path below).
  if (!isAdmin.value && to.name === 'Attendance' && !attendanceEnabled.value) {
    return { name: 'Dashboard' }
  }

  const clientOnlyRoutes = ['Dashboard', 'Invoices', 'Personnel', 'Contract', 'Attendance', 'Contact', 'Requests', 'NewRequest', 'RequestCreated', 'RequestDetail']
  if (isAdmin.value && clientOnlyRoutes.includes(to.name)) {
    // Admins may stay on /dochazka when previewing a specific client's view —
    // the page renders exactly what that client sees so admins can verify
    // FreshQR mode + rounding settings before saving.
    if (to.name === 'Attendance' && to.query.previewClientId) {
      return
    }
    return { name: 'Admin' }
  }

  if (to.path === '/admin') {
    return { path: '/admin/clients' }
  }
  if (to.path === '/admin/zamestnanci') {
    return { path: '/admin/employees' }
  }
})

export default router
