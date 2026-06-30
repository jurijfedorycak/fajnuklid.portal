import apiClient from '../client'

export const maintenanceRequestService = {
  async list({ status = null, limit = null, date = null } = {}) {
    const params = {}
    if (status && status !== 'all') params.status = status
    if (limit) params.limit = limit
    if (date) params.date = date
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

  async reject(id, comment) {
    const response = await apiClient.post(`/maintenance-requests/${id}/reject`, { comment })
    return response.data
  },

  async cancel(id) {
    const response = await apiClient.post(`/maintenance-requests/${id}/cancel`)
    return response.data
  },

  async uploadAttachment(id, file) {
    const fd = new FormData()
    fd.append('file', file)
    const response = await apiClient.post(`/maintenance-requests/${id}/attachments`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data
  },

  async getCalendar(year, month) {
    const response = await apiClient.get('/maintenance-requests/calendar', {
      params: { year, month },
    })
    return response.data
  },

  async getFormOptions() {
    const response = await apiClient.get('/maintenance-requests/form-options')
    return response.data
  },
}

export const REQUEST_STATUSES = [
  { key: 'prijato',  label: 'Nový',     badge: 'badge-info' },
  { key: 'resi_se',  label: 'V řešení', badge: 'badge-warning' },
  { key: 'vyreseno', label: 'Uzavřeno', badge: 'badge-success' },
]

export const REQUEST_CATEGORIES = [
  { key: 'reklamace',       label: 'Reklamace',       icon: 'AlertTriangle' },
  { key: 'mimoradna_prace', label: 'Mimořádná práce', icon: 'Wrench' },
  { key: 'jine',            label: 'Jiné',            icon: 'HelpCircle' },
]

// How a request reached us. 'portal' is the client self-service channel; the rest are
// off-portal channels an admin selects when logging a request on the client's behalf.
export const REQUEST_SOURCES = [
  { key: 'portal',    label: 'Portál',   icon: 'Globe' },
  { key: 'whatsapp',  label: 'WhatsApp', icon: 'MessageCircle' },
  { key: 'phone',     label: 'Telefon',  icon: 'Phone' },
  { key: 'in_person', label: 'Osobně',   icon: 'Users' },
  { key: 'email',     label: 'E-mail',   icon: 'Mail' },
]

// Whether the client sees the record in their portal, or it stays an internal admin note.
export const REQUEST_VISIBILITIES = [
  { key: 'client',   label: 'Viditelné klientovi', icon: 'Eye' },
  { key: 'internal', label: 'Interní poznámka',    icon: 'Lock' },
]

export const ATTACHMENT_LIMITS = {
  maxFiles: 5,
  maxBytes: 10 * 1024 * 1024,
  acceptedMimes: [
    'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
    'application/pdf',
  ],
  acceptAttr: 'image/*,application/pdf',
}

export default maintenanceRequestService
