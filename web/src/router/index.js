import { createRouter, createWebHistory } from 'vue-router'
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
  history: createWebHistory(),
  routes,
})

router.beforeEach((to) => {
  const { isAuthenticated, isAdmin } = useAuth()

  if (!to.meta.public && !isAuthenticated.value) {
    return { name: 'Login' }
  }

  if (to.name === 'Login' && isAuthenticated.value) {
    return { name: isAdmin.value ? 'Admin' : 'Dashboard' }
  }

  if (to.meta.requiresAdmin && !isAdmin.value) {
    return { name: 'Dashboard' }
  }

  const clientOnlyRoutes = ['Dashboard', 'Invoices', 'Personnel', 'Contract', 'Attendance', 'Contact', 'Requests', 'NewRequest', 'RequestCreated', 'RequestDetail']
  if (isAdmin.value && clientOnlyRoutes.includes(to.name)) {
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
