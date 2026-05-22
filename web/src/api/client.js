import axios from 'axios'

const API_BASE_URL = import.meta.env.VITE_API_URL || '/api'

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
})

// Request interceptor - add Bearer token
apiClient.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('auth_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// A 401 from /auth/me is the only signal that our stored token has expired —
// wipe and full-reload so the auth store's module-level refs re-initialise
// from empty localStorage. Soft router.replace would leave token.value
// truthy until checkAuth's catch ran, and the guard would still treat the
// user as authenticated. /auth/login 401 (bad credentials) and other 401s
// (e.g. legacy AuthException for "no client assigned") must propagate so
// the caller can render its own error UI.
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && (error.config?.url || '').includes('/auth/me')) {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
      // replace (not assign) so back-button can't return to the half-loaded
      // protected page that just retriggered the same 401.
      window.location.replace('/')
    }
    return Promise.reject(error)
  }
)

export default apiClient
