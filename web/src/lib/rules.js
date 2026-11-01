// Firefly III's rule vocabulary, as Firefly 6.6.6 accepts it (config/search.php operators for conditions,
// config/firefly.php rule-actions for actions). Firefly has no endpoint that lists them, so they live here.
// A rule may hold a type that is not listed (made in Firefly's own pages): the editor shows its keyword and keeps it.
//
// value: what the value is
//   none     no value; Firefly is sent "true" for conditions (like its own pages)
//   text     free text
//   optional free text that may stay empty
//   amount, date
//   type     withdrawal | deposit | transfer
//   account  an account id (a select)
//   category, budget, tag, currency, account-name: text, offered from Firefly's lists

const condition = (group, type, label, value = 'text') => ({ group, type, label, value })

export const CONDITIONS = [
  condition('Description', 'description_contains', 'Description contains'),
  condition('Description', 'description_starts', 'Description starts with'),
  condition('Description', 'description_ends', 'Description ends with'),
  condition('Description', 'description_is', 'Description is'),

  condition('Payee (destination)', 'destination_account_contains', 'Payee contains', 'account-name'),
  condition('Payee (destination)', 'destination_account_is', 'Payee is', 'account-name'),
  condition('Payee (destination)', 'destination_account_starts', 'Payee starts with', 'account-name'),
  condition('Payee (destination)', 'destination_account_ends', 'Payee ends with', 'account-name'),
  condition('Payee (destination)', 'destination_account_id', 'Payee account', 'account'),
  condition('Payee (destination)', 'destination_account_nr_contains', 'Payee IBAN or number contains'),
  condition('Payee (destination)', 'destination_account_nr_is', 'Payee IBAN or number is'),
  condition('Payee (destination)', 'destination_is_cash', 'Payee is cash', 'none'),

  condition('Payer (source)', 'source_account_contains', 'Payer contains', 'account-name'),
  condition('Payer (source)', 'source_account_is', 'Payer is', 'account-name'),
  condition('Payer (source)', 'source_account_starts', 'Payer starts with', 'account-name'),
  condition('Payer (source)', 'source_account_ends', 'Payer ends with', 'account-name'),
  condition('Payer (source)', 'source_account_id', 'Payer account', 'account'),
  condition('Payer (source)', 'source_account_nr_contains', 'Payer IBAN or number contains'),
  condition('Payer (source)', 'source_account_nr_is', 'Payer IBAN or number is'),
  condition('Payer (source)', 'source_is_cash', 'Payer is cash', 'none'),

  condition('Either account', 'account_is', 'Either account is', 'account-name'),
  condition('Either account', 'account_contains', 'Either account contains', 'account-name'),
  condition('Either account', 'account_id', 'Either account is account', 'account'),

  condition('Amount', 'amount_more', 'Amount more than', 'amount'),
  condition('Amount', 'amount_less', 'Amount less than', 'amount'),
  condition('Amount', 'amount_is', 'Amount is', 'amount'),
  condition('Amount', 'currency_is', 'Currency is', 'currency'),
  condition('Amount', 'foreign_currency_is', 'Foreign currency is', 'currency'),

  condition('Type and date', 'transaction_type', 'Type is', 'type'),
  condition('Type and date', 'date_on', 'Date is', 'date'),
  condition('Type and date', 'date_before', 'Date before', 'date'),
  condition('Type and date', 'date_after', 'Date after', 'date'),

  condition('Category', 'category_is', 'Category is', 'category'),
  condition('Category', 'category_contains', 'Category contains', 'category'),
  condition('Category', 'has_any_category', 'Has a category', 'none'),
  condition('Category', 'has_no_category', 'Has no category', 'none'),

  condition('Budget', 'budget_is', 'Budget is', 'budget'),
  condition('Budget', 'has_any_budget', 'Has a budget', 'none'),
  condition('Budget', 'has_no_budget', 'Has no budget', 'none'),

  condition('Tags', 'tag_is', 'Has tag', 'tag'),
  condition('Tags', 'tag_is_not', 'Does not have tag', 'tag'),
  condition('Tags', 'tag_contains', 'A tag contains', 'tag'),
  condition('Tags', 'has_any_tag', 'Has a tag', 'none'),
  condition('Tags', 'has_no_tag', 'Has no tags', 'none'),

  condition('Notes', 'notes_contains', 'Notes contain'),
  condition('Notes', 'notes_is', 'Notes are'),
  condition('Notes', 'any_notes', 'Has notes', 'none'),
  condition('Notes', 'no_notes', 'Has no notes', 'none'),

  condition('Other', 'bill_is', 'Bill is'),
  condition('Other', 'has_any_bill', 'Has a bill', 'none'),
  condition('Other', 'has_no_bill', 'Has no bill', 'none'),
  condition('Other', 'external_id_is', 'External ID is'),
  condition('Other', 'internal_reference_is', 'Internal reference is'),
  condition('Other', 'has_attachments', 'Has attachments', 'none'),
  condition('Other', 'has_no_attachments', 'Has no attachments', 'none'),
  condition('Other', 'reconciled', 'Is reconciled', 'none'),
]

const action = (group, type, label, value = 'text') => ({ group, type, label, value })

export const ACTIONS = [
  action('Category', 'set_category', 'Set category', 'category'),
  action('Category', 'clear_category', 'Clear category', 'none'),

  action('Budget', 'set_budget', 'Set budget', 'budget'),
  action('Budget', 'clear_budget', 'Clear budget', 'none'),

  action('Tags', 'add_tag', 'Add tag', 'tag'),
  action('Tags', 'remove_tag', 'Remove tag', 'tag'),
  action('Tags', 'remove_all_tags', 'Remove all tags', 'none'),

  action('Description and notes', 'set_description', 'Set description'),
  action('Description and notes', 'set_notes', 'Set notes'),
  action('Description and notes', 'clear_notes', 'Clear notes', 'none'),

  action('Accounts', 'set_source_account', 'Set payer (source) account', 'account-name'),
  action('Accounts', 'set_destination_account', 'Set payee (destination) account', 'account-name'),
  action('Accounts', 'set_source_to_cash', 'Set payer to cash', 'none'),
  action('Accounts', 'set_destination_to_cash', 'Set payee to cash', 'none'),
  action('Accounts', 'switch_accounts', 'Swap payer and payee', 'none'),

  action('Type', 'convert_withdrawal', 'Convert to expense', 'optional'),
  action('Type', 'convert_deposit', 'Convert to income', 'optional'),
  action('Type', 'convert_transfer', 'Convert to transfer (to account)', 'account-name'),

  action('Other', 'set_amount', 'Set amount', 'amount'),
  action('Other', 'link_to_bill', 'Link to bill'),
  action('Other', 'update_piggy', 'Add to piggy bank'),
  action('Other', 'delete_transaction', 'Delete the transaction', 'none'),
]

// What Firefly calls "trigger": when the rule runs on its own
export const WHEN = [
  { value: 'store-journal', label: 'On new transactions' },
  { value: 'update-journal', label: 'On edited transactions' },
  { value: 'manual-activation', label: 'Only by hand' },
]

export const TRANSACTION_TYPES = [
  { value: 'withdrawal', label: 'Expense' },
  { value: 'deposit', label: 'Income' },
  { value: 'transfer', label: 'Transfer' },
]

const conditionsByType = Object.fromEntries(CONDITIONS.map((item) => [item.type, item]))
const actionsByType = Object.fromEntries(ACTIONS.map((item) => [item.type, item]))

/** The listed condition or action of that type, or a stand-in that keeps an unlisted type as Firefly sent it */
export const conditionType = (type) => conditionsByType[type] ?? { group: 'Other', type, label: type, value: 'text', unknown: true }
export const actionType = (type) => actionsByType[type] ?? { group: 'Other', type, label: type, value: 'text', unknown: true }

/** [{group, items}] for <optgroup>s */
export function grouped(list) {
  const groups = []
  for (const item of list) {
    const last = groups.at(-1)
    if (last?.group === item.group) last.items.push(item)
    else groups.push({ group: item.group, items: [item] })
  }
  return groups
}

export const needsValue = (definition) => !['none', 'optional'].includes(definition.value)

// Firefly sends rule descriptions HTML-escaped
export function unescapeHtml(text) {
  if (!text) return ''
  return new DOMParser().parseFromString(`<!doctype html><body>${text}`, 'text/html').body.textContent ?? ''
}

/** "Description contains “lidl”" for a condition or action, with the account name for an account id */
export function describe(item, definition, accountName = (id) => `#${id}`) {
  if (definition.value === 'none') return definition.label
  const raw = String(item.value ?? '')
  if (definition.value === 'optional' && !raw) return definition.label
  let value = raw
  if (definition.value === 'account') value = raw.split(',').map((id) => accountName(id.trim())).join(', ')
  if (definition.value === 'type') value = TRANSACTION_TYPES.find((option) => option.value === raw)?.label ?? raw
  return `${definition.label} “${value}”`
}

export function describeCondition(trigger, accountName) {
  const text = describe(trigger, conditionType(trigger.type), accountName)
  return trigger.prohibited ? `Not: ${text}` : text
}

export const describeAction = (item, accountName) => describe(item, actionType(item.type), accountName)

/** One line for the list: the first active condition and action, with how many more there are */
export function summary(rule, accountName) {
  const { triggers = [], actions = [], strict } = rule.attributes
  const on = (list) => list.filter((item) => item.active !== false)
  const part = (list, text, joiner) => {
    const active = on(list)
    if (!active.length) return ''
    const more = active.length > 1 ? ` ${joiner} ${active.length - 1} more` : ''
    return text(active[0]) + more
  }
  const when = part(triggers, (trigger) => describeCondition(trigger, accountName), strict ? 'and' : 'or')
  const then = part(actions, (item) => describeAction(item, accountName), 'and')
  return [when, then].filter(Boolean).join(' → ')
}
