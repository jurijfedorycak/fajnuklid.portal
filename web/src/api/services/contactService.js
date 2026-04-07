import apiClient from '../client'

export const contactService = {
  async getContacts() {
    const response = await apiClient.get('/contacts')
    return response.data
  },
}

export default contactService
