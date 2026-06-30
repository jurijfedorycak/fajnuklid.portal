import apiClient from '../client'

export const contractService = {
  // Returns { contractsEnabled, hasDocuments, companies: [{ id, name, registrationNumber, documents: [] }], contact }
  async getContract() {
    const response = await apiClient.get('/contract')
    return response.data
  },

  // Streams a single document (authenticated, ownership-checked) as a blob.
  async downloadDocument(documentId) {
    const response = await apiClient.get(`/documents/${documentId}/download`, {
      responseType: 'blob',
    })
    return response.data
  },
}

export default contractService
