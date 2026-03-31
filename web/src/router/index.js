import { createRouter, createWebHashHistory } from 'vue-router'

// Mock auth state (in real app this would be a store)
const isAuthenticated = () => sessionStorage.getItem('mock_auth') === 'true'
const isAdmin = () => sessionStorage.getItem('mock_admin') === 'true'

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
        path: 'admin',
        name: 'Admin',
        component: () => import('../views/AdminView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: 'admin/klient/:id',
        name: 'AdminClientEdit',
        component: () => import('../views/AdminClientEditView.vue'),
        meta: { requiresAdmin: true },
      },
      {
        path: 'admin/zamestnanci',
        name: 'AdminEmployees',
        component: () => import('../views/AdminEmployeesView.vue'),
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
  if (!to.meta.public && !isAuthenticated()) {
    return { name: 'Login' }
  }
  if (to.meta.requiresAdmin && !isAdmin()) {
    return { name: 'Dashboard' }
  }
})

export default router
