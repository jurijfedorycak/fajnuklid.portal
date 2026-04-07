import { ref, computed, readonly } from 'vue'
import { authService } from '../api'

// State
const token = ref(localStorage.getItem('auth_token') || null)
const user = ref(JSON.parse(localStorage.getItem('auth_user') || 'null'))
const loading = ref(false)
const error = ref(null)

// Computed
const isAuthenticated = computed(() => !!token.value)
const isAdmin = computed(() => user.value?.is_admin || false)

// Actions
async function login(email, password) {
  loading.value = true
  error.value = null

  try {
    const response = await authService.login(email, password)

    if (response.success) {
      token.value = response.data.token
      user.value = response.data.user

      localStorage.setItem('auth_token', response.data.token)
      localStorage.setItem('auth_user', JSON.stringify(response.data.user))

      return { success: true }
    } else {
      error.value = response.message || 'Přihlášení se nezdařilo'
      return { success: false, message: error.value }
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Přihlášení se nezdařilo'
    return { success: false, message: error.value }
  } finally {
    loading.value = false
  }
}

async function logout() {
  try {
    await authService.logout()
  } catch {
    // Ignore logout errors
  } finally {
    token.value = null
    user.value = null
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
  }
}

async function checkAuth() {
  if (!token.value) {
    return false
  }

  try {
    const response = await authService.me()
    if (response.success) {
      user.value = response.data
      localStorage.setItem('auth_user', JSON.stringify(response.data))
      return true
    }
  } catch {
    token.value = null
    user.value = null
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
  }

  return false
}

function updateUser(userData) {
  user.value = { ...user.value, ...userData }
  localStorage.setItem('auth_user', JSON.stringify(user.value))
}

export function useAuth() {
  return {
    // State (readonly to prevent direct mutations)
    token: readonly(token),
    user: readonly(user),
    loading: readonly(loading),
    error: readonly(error),

    // Computed
    isAuthenticated,
    isAdmin,

    // Actions
    login,
    logout,
    checkAuth,
    updateUser,
  }
}

export default useAuth
