import axios from 'axios'
import { get } from 'lodash-es'
import BaseRepository from '~/repository/BaseRepository'

// Re-authentication first asks the bank for its consent limit => allow more than the default timeout
const REAUTHENTICATE_TIMEOUT = 60000

export default class BankConnectionRepository extends BaseRepository {
  constructor() {
    super('api/bank-connections')
  }

  // The importer returns every connection at once: no paging or filters
  async getAll({ showLoading = true, showErrorToast = true } = {}) {
    const response = await axios.get(this.getUrl(), { showLoading, showErrorToast })
    return get(response, 'data', {})
  }

  async reauthenticate(name) {
    const response = await axios.post(`${this.getUrl()}/${name}/reauthenticate`, {}, { timeout: REAUTHENTICATE_TIMEOUT })
    return get(response, 'data.data.auth_url')
  }

  async delete(name) {
    return await axios.delete(`${this.getUrl()}/${name}`)
  }
}
