import apiClient from '../client'

export const adminService = {
  // Stats
  async getStats() {
    const response = await apiClient.get('/admin/stats')
    return response.data
  },

  // File upload
  async uploadFile(file, folder) {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('folder', folder)

    const response = await apiClient.post('/admin/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return response.data?.url || response.data?.data?.url
  },

  // Clients
  async getClients(page = 1, perPage = 20, search = null) {
    const params = { page, per_page: perPage }
    if (search) params.search = search

    const response = await apiClient.get('/admin/clients', { params })
    return response.data
  },

  async getClient(id) {
    const response = await apiClient.get(`/admin/clients/${id}`)
    return response.data
  },

  async createClient(data) {
    const response = await apiClient.post('/admin/clients', data)
    return response.data
  },

  async updateClient(id, data) {
    const response = await apiClient.put(`/admin/clients/${id}`, data)
    return response.data
  },

  async deleteClient(id) {
    const response = await apiClient.delete(`/admin/clients/${id}`)
    return response.data
  },

  // Employees
  async getEmployees(page = 1, perPage = 20, search = null) {
    const params = { page, per_page: perPage }
    if (search) params.search = search

    const response = await apiClient.get('/admin/employees', { params })
    return response.data
  },

  async getEmployee(id) {
    const response = await apiClient.get(`/admin/employees/${id}`)
    return response.data
  },

  async createEmployee(data) {
    const response = await apiClient.post('/admin/employees', data)
    return response.data
  },

  async updateEmployee(id, data) {
    const response = await apiClient.put(`/admin/employees/${id}`, data)
    return response.data
  },

  async deleteEmployee(id) {
    const response = await apiClient.delete(`/admin/employees/${id}`)
    return response.data
  },

  async saveEmployees(employees) {
    const response = await apiClient.put('/admin/employees', employees)
    return response.data
  },

  // Staff contacts (Fajnuklid team)
  async getStaffContacts(page = 1, perPage = 50, search = null) {
    const params = { page, per_page: perPage }
    if (search) params.search = search

    const response = await apiClient.get('/admin/staff-contacts', { params })
    return response.data
  },

  async getStaffContact(id) {
    const response = await apiClient.get(`/admin/staff-contacts/${id}`)
    return response.data
  },

  async createStaffContact(data) {
    const response = await apiClient.post('/admin/staff-contacts', data)
    return response.data
  },

  async updateStaffContact(id, data) {
    const response = await apiClient.put(`/admin/staff-contacts/${id}`, data)
    return response.data
  },

  async deleteStaffContact(id) {
    const response = await apiClient.delete(`/admin/staff-contacts/${id}`)
    return response.data
  },

  async reorderStaffContacts(ids) {
    const response = await apiClient.post('/admin/staff-contacts/reorder', { ids })
    return response.data
  },
}

export default adminService
