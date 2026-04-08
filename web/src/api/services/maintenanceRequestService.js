import apiClient from '../client'

export const maintenanceRequestService = {
  async list(status = null) {
    const params = status && status !== 'all' ? { status } : {}
    const response = await apiClient.get('/maintenance-requests', { params })
    return response.data
  },

  async get(id) {
    const response = await apiClient.get(`/maintenance-requests/${id}`)
    return response.data
  },

  async create(payload) {
    const response = await apiClient.post('/maintenance-requests', payload)
    return response.data
  },

  async confirm(id) {
    const response = await apiClient.post(`/maintenance-requests/${id}/confirm`)
    return response.data
  },

  async getFormOptions() {
    const response = await apiClient.get('/maintenance-requests/form-options')
    return response.data
  },
}

export const REQUEST_STATUSES = [
  { key: 'prijato',            label: 'Přijato',                badge: 'badge-info' },
  { key: 'resi_se',            label: 'Řeší se',                badge: 'badge-warning' },
  { key: 'ceka_na_potvrzeni',  label: 'Čeká na vaše potvrzení', badge: 'badge-info' },
  { key: 'vyreseno',           label: 'Vyřešeno',               badge: 'badge-success' },
  { key: 'zablokovano',        label: 'Zablokováno',            badge: 'badge-danger' },
]

export const REQUEST_CATEGORIES = [
  { key: 'elektro',  label: 'Elektro',  icon: 'Zap' },
  { key: 'voda',     label: 'Voda',     icon: 'Droplet' },
  { key: 'klima',    label: 'Klima',    icon: 'Wind' },
  { key: 'uklid',    label: 'Úklid',    icon: 'Sparkles' },
  { key: 'pristupy', label: 'Přístupy', icon: 'Key' },
  { key: 'jine',     label: 'Jiné',     icon: 'HelpCircle' },
]

export default maintenanceRequestService
