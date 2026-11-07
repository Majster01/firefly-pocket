// Splitting a Firefly III transaction: the arithmetic and the update body, apart from the sheet so they can be tested.
// Nothing is imported: the sheet parses what the user typed (format.js parseAmount) and passes numbers.
//
// Amounts are compared in minor units (cents; VWCE shares to 4 places), since 0.30 - 0.10 is not 0.20 in floats.

const minor = (amount, decimals) => Math.round(Number(amount) * 10 ** decimals)
const major = (units, decimals) => units / 10 ** decimals || 0

/** What an amount split off a split leaves: { ok, remaining, splitOff }, or { ok: false, reason } worded for the sheet's blocker line. */
export function planSplitOff({ amount, splitAmount, decimals }) {
  if (!Number.isFinite(amount)) return { ok: false, reason: 'The amount does not read as a number' }
  if (!Number.isFinite(splitAmount)) return { ok: false, reason: 'Enter the amount to split off' }
  const total = minor(amount, decimals)
  const taken = minor(splitAmount, decimals)
  if (taken <= 0) return { ok: false, reason: 'The amount to split off must be above zero' }
  if (taken >= total) return { ok: false, reason: `Enter an amount below ${major(total, decimals).toFixed(decimals)}` }
  return { ok: true, remaining: major(total - taken, decimals), splitOff: major(taken, decimals) }
}

/** By how much saving changes the transaction's total, when only this split's amount moves: 0 when it does not. */
export const totalChange = ({ original, edited, decimals }) => major(minor(edited, decimals) - minor(original, decimals), decimals)

/**
 * The split that is added: the edited one (toSplit(), so a changed account, type or payee counts) with its own amount,
 * description and category. Tags and notes stay behind, and so does a foreign amount, which would be the full one on
 * both splits. The external ID is copied so that every split of the group carries the bank row's ID: the importer
 * skips a row when Firefly finds the ID on any transaction.
 */
export function newSplitFrom({ edited, externalId, amount, description, categoryName }) {
  const { tags, notes, foreign_amount, foreign_currency_id, foreign_currency_code, ...kept } = edited
  return {
    ...kept,
    amount: amount.toFixed(4),
    description,
    category_name: categoryName || null,
    ...(externalId ? { external_id: externalId } : {}),
  }
}

const isJournal = (journalId) => (split) => String(split.transaction_journal_id) === String(journalId)

/** The split of the group that has this journal id, as Firefly has it now; undefined when it is gone. */
export const splitOf = ({ splits, journalId }) => splits.find(isJournal(journalId))

/**
 * The body of the group update. Firefly drops the splits an update leaves out, so the others are sent by id alone; it
 * refuses a group of several splits without a title, and the new split counts toward that.
 */
export function groupPayload({ splits, groupTitle, journalId, edited, newSplit = null }) {
  const transactions = splits.map((other) =>
    isJournal(journalId)(other) ? { transaction_journal_id: other.transaction_journal_id, ...edited } : { transaction_journal_id: other.transaction_journal_id },
  )
  if (newSplit) transactions.push(newSplit)
  const title = transactions.length > 1 ? { group_title: groupTitle || edited.description } : {}
  return { apply_rules: false, ...title, transactions }
}
