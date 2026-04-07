import apiClient from '../client'

export const authService = {
  async login(email, password) {
    const response = await apiClient.post('/auth/login', { email, password })
    return response.data
  },

  async logout() {
    try {
      await apiClient.post('/auth/logout')
    } finally {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
    }
  },

  async me() {
    const response = await apiClient.get('/auth/me')
    return response.data
  },
}

export default authService
