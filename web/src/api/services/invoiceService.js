import apiClient from '../client'

export const invoiceService = {
  async getInvoices() {
    const response = await apiClient.get('/invoices')
    return response.data
  },
}

export default invoiceService
