import apiClient from '../client'

export const dashboardService = {
  async getDashboard() {
    const response = await apiClient.get('/dashboard')
    return response.data
  },
}

export default dashboardService
