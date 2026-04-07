import apiClient from '../client'

export const contractService = {
  async getContract() {
    const response = await apiClient.get('/contract')
    return response.data
  },

  async downloadContract(companyId) {
    const response = await apiClient.get('/contract/download', {
      params: { company_id: companyId },
      responseType: 'blob',
    })
    return response.data
  },
}

export default contractService
