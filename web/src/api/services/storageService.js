import apiClient from '../client'

export const storageService = {
  async upload(file, folder) {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('folder', folder)

    const response = await apiClient.post('/admin/storage/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    return {
      url: response.data?.data?.url,
      key: response.data?.data?.key,
    }
  },

  async getDownloadUrl(key) {
    const response = await apiClient.get('/storage/download', {
      params: { key },
    })
    return response.data?.data?.url
  },

  async delete(key) {
    await apiClient.delete('/admin/storage', { data: { key } })
  },
}

export default storageService
