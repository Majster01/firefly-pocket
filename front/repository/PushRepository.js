import axios from 'axios'
import { get } from 'lodash-es'
import BaseRepository from '~/repository/BaseRepository'

export default class PushRepository extends BaseRepository {
  constructor() {
    super('api/push')
  }

  // { configured, public_key }. An older backend answers something else => not configured
  async getPublicKey() {
    const response = await axios.get(`${this.getUrl()}/public-key`, { showLoading: false, showErrorToast: false })
    return get(response, 'data.data', { configured: false })
  }

  // The browser's PushSubscription as JSON: { endpoint, keys: { p256dh, auth } }
  async subscribe(subscription) {
    return await axios.post(`${this.getUrl()}/subscriptions`, subscription, { showLoading: false })
  }

  async unsubscribe(endpoint) {
    return await axios.delete(`${this.getUrl()}/subscriptions`, { data: { endpoint }, showLoading: false })
  }
}
