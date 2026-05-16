import apiClient from '../client'

export const attendanceService = {
  async getAttendance(year = null, month = null, previewClientId = null) {
    const params = {}
    if (year) params.year = year
    if (month) params.month = month
    if (previewClientId) params.previewClientId = previewClientId

    const response = await apiClient.get('/attendance', { params })
    return response.data
  },
}

export default attendanceService
