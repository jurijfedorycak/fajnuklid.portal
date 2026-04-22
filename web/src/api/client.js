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

// Response interceptor - only wipe auth on a *token* 401, not any 401.
// A 401 from /auth/me or /auth/login means our stored token is bad and the session
// should be cleared. A 401 from some other endpoint could be a genuine permission
// response for a logged-in user (e.g. backend legacy code using AuthException for
// "no client assigned") — clearing auth there causes a login-redirect loop.
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      const url = error.config?.url || ''
      const isTokenEndpoint = url.includes('/auth/me') || url.includes('/auth/login')
      if (isTokenEndpoint) {
        localStorage.removeItem('auth_token')
        localStorage.removeItem('auth_user')
        if (window.location.pathname !== '/') {
          window.location.assign('/')
        }
      }
    }
    return Promise.reject(error)
  }
)

export default apiClient
