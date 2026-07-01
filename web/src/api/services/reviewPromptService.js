import apiClient from '../client'

export const reviewPromptService = {
  // Client clicked "later" — hide the block until the server-computed snooze date.
  async snooze() {
    const response = await apiClient.post('/review-prompt/snooze')
    return response.data
  },

  // Client picked a star rating. The backend records it and returns where to route
  // the client: 'google' (4-5 stars) with a googleUrl, or 'complaint' (1-3 stars).
  async complete(rating) {
    const response = await apiClient.post('/review-prompt/complete', { rating })
    return response.data
  },
}

export default reviewPromptService
