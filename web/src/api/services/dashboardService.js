import apiClient from '../client'

export const dashboardService = {
  async getDashboard({ ico, from, to } = {}) {
    const params = {}
    if (ico) params.ico = ico
    if (from) params.from = from
    if (to) params.to = to
    const response = await apiClient.get('/dashboard', { params })
    return response.data
  },
}

export default dashboardService
