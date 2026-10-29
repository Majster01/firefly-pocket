import axios from 'axios'
import BaseRepository from '~/repository/BaseRepository'

// Approving searches and updates many transactions in Firefly => allow more than the default query timeout
const RESOLVE_TIMEOUT = 60000

export default class RuleSuggestionRepository extends BaseRepository {
  constructor() {
    super('api/rule-suggestions')
  }

  async preview(data) {
    return await axios.post(`${this.getUrl()}/preview`, data, { showLoading: false, showErrorToast: false })
  }

  async approve(id, data) {
    return await axios.post(`${this.getUrl()}/${id}/approve`, data, { timeout: RESOLVE_TIMEOUT })
  }

  async categorize(id, data) {
    return await axios.post(`${this.getUrl()}/${id}/categorize`, data, { timeout: RESOLVE_TIMEOUT })
  }
}
