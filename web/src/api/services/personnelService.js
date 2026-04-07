import apiClient from '../client'

export const personnelService = {
  async getPersonnel() {
    const response = await apiClient.get('/personnel')
    return response.data
  },
}

export default personnelService
