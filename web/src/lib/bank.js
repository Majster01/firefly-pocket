export const STATUS = {
  active: { label: 'Active', tone: 'good' },
  expiring: { label: 'Expiring', tone: 'warn' },
  expired: { label: 'Expired', tone: 'bad' },
  error: { label: 'Error', tone: 'bad' },
  needs_mapping: { label: 'Set up', tone: 'warn' },
  pending: { label: 'Waiting for the bank', tone: '' },
}

// ?reason=… the importer appends when a consent did not go through
export const FAILURE = {
  cancelled: 'The consent was cancelled at the bank.',
  expired: 'The consent link expired. Start again.',
  no_accounts: 'The bank shared no accounts.',
  unknown: 'The bank did not confirm the consent.',
}

export const IMPORT_STATUS = {
  queued: 'Queued',
  importing: 'Importing…',
  suggesting: 'Suggesting categories…',
  done: 'Done',
  failed: 'Failed',
}
export const IMPORT_ACTIVE = ['queued', 'importing', 'suggesting']
