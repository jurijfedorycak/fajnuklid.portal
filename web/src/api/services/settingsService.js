import apiClient from '../client'

export const settingsService = {
  async getSettings() {
    const response = await apiClient.get('/settings')
    return response.data
  },

  async updateSettings(settings) {
    const response = await apiClient.put('/settings', { settings })
    return response.data
  },

  async changePassword(currentPassword, newPassword, confirmPassword) {
    const response = await apiClient.post('/settings/password', {
      current_password: currentPassword,
      new_password: newPassword,
      confirm_password: confirmPassword,
    })
    return response.data
  },
}

export default settingsService
