import axios from 'axios'
import { get } from 'lodash-es'
import BaseRepository from '~/repository/BaseRepository'

export default class SystemStatusRepository extends BaseRepository {
  constructor() {
    super('api/system-status')
  }

  // How the nightly imports went, where every bank connection stands and what the category suggester did over the last `days` days.
  // The importer collects all of it, so one request is enough
  async getStatus(days, { showLoading = true } = {}) {
    const response = await axios.get(this.getUrl(), { params: { days }, showLoading })
    return get(response, 'data.data')
  }
}
