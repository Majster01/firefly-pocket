import axios from 'axios'
import { get } from 'lodash-es'
import BaseRepository from '~/repository/BaseRepository'

// Listing banks and starting a consent call the bank's API => allow more than the default timeout
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

  async getOneConnection(name, { showErrorToast = true } = {}) {
    const response = await axios.get(`${this.getUrl()}/${name}`, { showErrorToast })
    return get(response, 'data.data')
  }

  // Enable Banking's list for a country, with the logo and the account types each bank offers
  async getBanks(country) {
    const response = await axios.get(`${this.getUrl()}/banks`, { params: { country }, timeout: REAUTHENTICATE_TIMEOUT })
    return get(response, 'data.data', [])
  }

  // Starts the consent for a new connection. Returns the new connection's name and the bank's consent page,
  // which sends the user to the setup page
  async create({ country, bank, psuType }) {
    const response = await axios.post(this.getUrl(), { country, bank, psu_type: psuType }, { timeout: REAUTHENTICATE_TIMEOUT })
    return { name: get(response, 'data.data.name'), authUrl: get(response, 'data.data.auth_url') }
  }

  // accounts: [{ id, firefly_account_id }], null = not imported
  async saveAccounts(name, accounts, importSince) {
    const response = await axios.put(`${this.getUrl()}/${name}/accounts`, { accounts, import_since: importSince })
    return get(response, 'data.data')
  }

  async reauthenticate(name) {
    const response = await axios.post(`${this.getUrl()}/${name}/reauthenticate`, {}, { timeout: REAUTHENTICATE_TIMEOUT })
    return get(response, 'data.data.auth_url')
  }

  async delete(name) {
    return await axios.delete(`${this.getUrl()}/${name}`)
  }
}
