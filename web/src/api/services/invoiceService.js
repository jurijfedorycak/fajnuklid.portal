import apiClient from '../client'

export const invoiceService = {
  async getInvoices(ico = null) {
    const params = ico ? { ico } : {}
    const response = await apiClient.get('/invoices', { params })
    return response.data
  },

  async downloadPdf(dbId) {
    const response = await apiClient.get(`/invoices/${dbId}/pdf`, {
      responseType: 'blob',
    })
    return response.data
  },

  async syncInvoices() {
    const response = await apiClient.post('/invoices/sync')
    return response.data
  },
}

export default invoiceService
