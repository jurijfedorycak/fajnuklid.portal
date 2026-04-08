import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuth } from '../stores/auth'

const routes = [
  {
    path: '/login',
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
    path: '/',
    component: () => import('../layouts/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Dashboard',
        component: () => import('../views/DashboardView.vue'),
      },
      {
        path: 'faktury',
        name: 'Invoices',
        component: () => import('../views/InvoicesView.vue'),
      },
      {
        path: 'personal',
        name: 'Personnel',
        component: () => import('../views/PersonnelView.vue'),
      },
      {
        path: 'smlouva',
        name: 'Contract',
        component: () => import('../views/ContractView.vue'),
      },
      {
        path: 'dochazka',
        name: 'Attendance',
        component: () => import('../views/AttendanceView.vue'),
      },
      {
        path: 'zadosti',
        name: 'Requests',
        component: () => import('../views/RequestsView.vue'),
      },
      {
        path: 'zadosti/nova',
        name: 'NewRequest',
        component: () => import('../views/NewRequestView.vue'),
      },
      {
        path: 'zadosti/:id',
        name: 'RequestDetail',
        component: () => import('../views/RequestDetailView.vue'),
      },
      {
        path: 'kontakt',
        name: 'Contact',
        component: () => import('../views/ContactView.vue'),
      },
      {
        path: 'nastaveni',
        name: 'Settings',
        component: () => import('../views/SettingsView.vue'),
      },
      {
        path: 'admin/clients',
        name: 'Admin',
        component: () => import('../views/AdminView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: 'admin/clients/:id',
        name: 'AdminClientEdit',
        component: () => import('../views/AdminClientEditView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: 'admin/employees',
        name: 'AdminEmployees',
        component: () => import('../views/AdminEmployeesView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: 'admin/staff-contacts',
        name: 'AdminStaffContacts',
        component: () => import('../views/AdminStaffContactsView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: 'admin/design-tokens',
        name: 'AdminDesignTokens',
        component: () => import('../views/AdminDesignTokensView.vue'),
        meta: { requiresAdmin: true },
      },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

router.beforeEach((to) => {
  const { isAuthenticated, isAdmin } = useAuth()

  // Check if route requires authentication
  if (!to.meta.public && !isAuthenticated.value) {
    return { name: 'Login' }
  }

  // Check if route requires admin
  if (to.meta.requiresAdmin && !isAdmin.value) {
    return { name: 'Dashboard' }
  }

  // Block admin from client-only routes
  const clientOnlyRoutes = ['Dashboard', 'Invoices', 'Personnel', 'Contract', 'Attendance', 'Contact', 'Requests', 'NewRequest', 'RequestDetail']
  if (isAdmin.value && clientOnlyRoutes.includes(to.name)) {
    return { name: 'Admin' }
  }

  // Redirect old admin routes to new English routes
  if (to.path === '/admin') {
    return { path: '/admin/clients' }
  }
  if (to.path === '/admin/zamestnanci') {
    return { path: '/admin/employees' }
  }
})

export default router
