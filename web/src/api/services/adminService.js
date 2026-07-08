import apiClient from '../client'

export const adminService = {
  // Stats
  async getStats() {
    const response = await apiClient.get('/admin/stats')
    return response.data
  },

  // Global app settings (company-wide config, e.g. Google review link)
  async getSettings() {
    const response = await apiClient.get('/admin/settings')
    return response.data
  },

  async updateSettings(data) {
    const response = await apiClient.put('/admin/settings', data)
    return response.data
  },

  // File upload. Optional entity param persists the URL to DB immediately.
  // entity: { type: 'employee'|'company'|'staff_contact', id: number, field: string }
  async uploadFile(file, folder, entity = null) {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('folder', folder)

    if (entity?.type && entity?.id && entity?.field) {
      formData.append('entity_type', entity.type)
      formData.append('entity_id', String(entity.id))
      formData.append('field', entity.field)
    }

    const response = await apiClient.post('/admin/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return response.data?.url || response.data?.data?.url
  },

  // File removal — clears a file field on a DB record immediately
  async removeFile(entityType, entityId, field) {
    const response = await apiClient.delete('/admin/upload', {
      data: { entity_type: entityType, entity_id: entityId, field },
    })
    return response.data
  },

  // Clients
  async getClients(page = 1, perPage = 20, search = null, status = 'active') {
    const params = { page, per_page: perPage }
    if (search) params.search = search
    if (status === 'archived') params.status = 'archived'

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

  async restoreClient(id) {
    const response = await apiClient.post(`/admin/clients/${id}/restore`)
    return response.data
  },

  async syncIdokladForCompany(companyId) {
    const response = await apiClient.post(`/admin/companies/${companyId}/idoklad-sync`)
    return response.data
  },

  // Company documents (smlouvy a dokumenty)
  async uploadCompanyDocument(companyId, file, title, documentType) {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('title', title)
    if (documentType) formData.append('document_type', documentType)

    const response = await apiClient.post(`/admin/companies/${companyId}/documents`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data?.data
  },

  async updateCompanyDocument(documentId, { title, documentType }) {
    const response = await apiClient.put(`/admin/documents/${documentId}`, {
      title,
      document_type: documentType,
    })
    return response.data?.data
  },

  async deleteCompanyDocument(documentId) {
    const response = await apiClient.delete(`/admin/documents/${documentId}`)
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

  async setStaffContactPassword(id, password) {
    const response = await apiClient.post(`/admin/staff-contacts/${id}/password`, { password })
    return response.data
  },

  async revokeStaffContactLogin(id) {
    const response = await apiClient.delete(`/admin/staff-contacts/${id}/login`)
    return response.data
  },

  // Maintenance requests
  async getMaintenanceRequests(clientId = null, status = null) {
    const params = {}
    if (clientId) params.clientId = clientId
    if (status && status !== 'all') params.status = status
    const response = await apiClient.get('/admin/maintenance-requests', { params })
    return response.data
  },

  async getMaintenanceRequest(id) {
    const response = await apiClient.get(`/admin/maintenance-requests/${id}`)
    return response.data
  },

  // Options for the admin "new record" form: all clients, plus the selected client's
  // protistrany (IČO) when clientId is provided.
  async getMaintenanceRequestFormOptions(clientId = null) {
    const params = {}
    if (clientId) params.clientId = clientId
    const response = await apiClient.get('/admin/maintenance-requests/form-options', { params })
    return response.data
  },

  async createMaintenanceRequest(data) {
    const response = await apiClient.post('/admin/maintenance-requests', data)
    return response.data
  },

  async updateMaintenanceRequest(id, data) {
    const response = await apiClient.put(`/admin/maintenance-requests/${id}`, data)
    return response.data
  },

  async addMaintenanceRequestActivity(id, message, internal = false) {
    const response = await apiClient.post(`/admin/maintenance-requests/${id}/activity`, { message, internal })
    return response.data
  },

  async deleteMaintenanceRequest(id) {
    const response = await apiClient.delete(`/admin/maintenance-requests/${id}`)
    return response.data
  },
}

export default adminService
