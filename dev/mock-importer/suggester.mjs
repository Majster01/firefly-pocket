// A mock of the FireflyX category suggester: no LLM, a fixed table of counterparties instead.
// It writes inactive rules in the "Suggestions" group exactly as firefly-pocket-stack's docs/rule-suggestions.md (pico-suggestion:v1) describes.

export const MARKER = 'pico-suggestion:v1 '
export const MIN_CONFIDENCE = 0.6
const MODEL = 'mock-suggester'

// What the "model" knows. keyword: matched case-insensitively against description and destination; it becomes the
// description_contains trigger, so it must be a substring of every description it covers (>= 4 characters).
// person: a private person, so only description triggers are written
const KNOWN = [
  { keyword: 'BURGERLAB', category: 'Food & Drink', company: 'Burgerlab — burger restaurant, Ljubljana', reason: 'Card purchase at a burger chain', confidence: 0.91 },
  { keyword: 'KAVARNA', category: 'Food & Drink', company: 'Kavarna Rog — café, Ljubljana', reason: 'Small card purchase at a café', confidence: 0.84 },
  { keyword: 'WOLT', category: 'Food & Drink', company: 'Wolt — food delivery', reason: 'Food delivery order', confidence: 0.88 },
  { keyword: 'BOLT', category: 'Transport', company: 'Bolt — ride hailing', reason: 'Ride hailing trip', confidence: 0.86 },
  { keyword: 'PETROL', category: 'Transport', company: 'Petrol d.d. — fuel stations', reason: 'Fuel station purchase', confidence: 0.93 },
  { keyword: 'IKEA', category: 'Home', company: 'IKEA — furniture store, Ljubljana', reason: 'Furniture and home goods', confidence: 0.9 },
  { keyword: 'DECATHLON', category: 'Shopping', company: 'Decathlon — sports store', reason: 'Sports equipment store', confidence: 0.82 },
  { keyword: 'LEKARNA', category: 'Health', company: 'Lekarna Ljubljana — pharmacy', reason: 'Pharmacy purchase', confidence: 0.9 },
  { keyword: 'KOLOSEJ', category: 'Entertainment', company: 'Kolosej — cinema', reason: 'Cinema tickets', confidence: 0.87 },
  { keyword: 'YOUTUBE', category: 'Subscriptions', company: 'Google — YouTube Premium', reason: 'Monthly streaming subscription', confidence: 0.92 },
  { keyword: 'NRC00 ANA NOVAK', category: 'Gifts', company: null, reason: 'Instant payment to a private person, likely splitting a gift', confidence: 0.64, person: true },
]

// Best guesses for descriptions the "model" does not know: always below the threshold
const FALLBACK = { category: 'Other', company: null, reason: 'Counterparty unknown; best guess from the amount and description', confidence: 0.38 }

const today = () => new Date().toISOString().slice(0, 10)

const toSplits = (groups) =>
  groups.flatMap((group) => (group.attributes.transactions ?? []).map((split) => ({ ...split, transaction_id: group.id })))

const isPrivateDestination = (split) => /NRC00|^\(unknown/i.test(split.destination_name ?? '')

/**
 * Suggests categories for the uncategorized withdrawals in [dateFrom, dateTo]. Returns the run record the status page shows.
 * memory.rejected: keys ("keyword|category") the user rejected before; those are never suggested again.
 */
export async function suggest(firefly, { dateFrom, dateTo, source = 'nightly', memory = { rejected: [], created: [] } }) {
  const run = {
    id: `run-${Date.now()}`,
    started: new Date().toISOString(),
    source,
    status: 'done',
    calls: 0,
    searches: 0,
    suggestions: 0,
    low_confidence: 0,
    input_tokens: 0,
    output_tokens: 0,
    group_errors: 0,
    write_errors: 0,
    error: null,
    error_details: [],
    min_confidence: MIN_CONFIDENCE,
  }
  try {
    const group = await firefly.ruleGroup('Suggestions', { create: true, active: false, description: 'Pending category suggestions (mock suggester)' })
    await reconcile(firefly, group, memory)

    // Journals some pending suggestion already covers
    const existing = await firefly.all(`rule-groups/${group.id}/rules`)
    const covered = new Set(
      existing.flatMap((rule) => {
        const metadata = parseMetadata(rule.attributes.description)
        return (metadata?.transactions ?? []).map((item) => String(item.journal_id))
      }),
    )

    const query = `has_no_category:true type:withdrawal date_after:${dayBefore(dateFrom)} date_before:${dayAfter(dateTo)}`
    const splits = toSplits(await firefly.all('search/transactions', { query })).filter((split) => !covered.has(String(split.transaction_journal_id)))

    // One suggestion per known keyword, one per unknown description
    const buckets = new Map()
    for (const split of splits) {
      const haystack = `${split.description} ${split.destination_name ?? ''}`.toUpperCase()
      const known = KNOWN.find((item) => haystack.includes(item.keyword))
      const key = known ? `known:${known.keyword}` : `unknown:${split.description.trim().toUpperCase()}`
      if (!buckets.has(key)) {
        buckets.set(key, { known, splits: [] })
      }
      buckets.get(key).splits.push(split)
    }

    for (const { known, splits: bucket } of buckets.values()) {
      run.calls++
      run.input_tokens += 900 + 60 * bucket.length
      run.output_tokens += 140
      const guess = known ?? FALLBACK
      const keyword = known ? known.keyword : bucket[0].description.trim()
      if (memory.rejected.includes(`${keyword.toUpperCase()}|${guess.category}`)) {
        continue
      }
      if (known && !known.person) {
        run.searches++
      }
      const lowConfidence = guess.confidence < MIN_CONFIDENCE
      const triggers = buildTriggers(known, bucket, keyword, lowConfidence)
      const metadata = {
        v: 1,
        transactions: bucket.map((split) => ({ transaction_id: Number(split.transaction_id), journal_id: Number(split.transaction_journal_id) })),
        category: guess.category,
        confidence: guess.confidence,
        low_confidence: lowConfidence,
        basis: triggers.some((t) => t.type.startsWith('destination')) ? (triggers.some((t) => t.type === 'description_contains') ? 'both' : 'destination') : 'description',
        company: guess.company,
        reason: guess.reason,
        model: MODEL,
        created: today(),
      }
      try {
        const rule = await firefly.post('rules', {
          title: `Suggest: ${guess.category} · ${known?.company?.split(' — ')[0] ?? keyword}`,
          description: MARKER + JSON.stringify(metadata),
          rule_group_id: group.id,
          trigger: 'store-journal',
          active: false,
          strict: false,
          stop_processing: true,
          triggers: triggers.map((trigger) => ({ ...trigger, active: true, prohibited: false, stop_processing: true })),
          actions: [{ type: 'set_category', value: guess.category, active: true, stop_processing: false }],
        })
        memory.created.push({ rule_id: rule.data.id, keyword: keyword.toUpperCase(), category: guess.category, journal_ids: metadata.transactions.map((t) => t.journal_id) })
        run.suggestions++
        if (lowConfidence) {
          run.low_confidence++
        }
      } catch (error) {
        run.write_errors++
        run.error_details.push(error.message.slice(0, 200))
      }
    }
  } catch (error) {
    run.status = 'failed'
    run.error = error.message.slice(0, 300)
  }
  run.finished = new Date().toISOString()
  return run
}

function buildTriggers(known, bucket, keyword, lowConfidence) {
  if (lowConfidence && !known) {
    return [{ type: 'description_contains', value: keyword }]
  }
  const triggers = []
  const destinationIds = [...new Set(bucket.filter((split) => !isPrivateDestination(split)).map((split) => split.destination_id))]
  if (!known.person && destinationIds.length === 1) {
    triggers.push({ type: 'destination_account_id', value: String(destinationIds[0]) })
  }
  if (bucket.every((split) => split.description.toUpperCase().includes(keyword))) {
    triggers.push({ type: 'description_contains', value: keyword.toLowerCase() })
  }
  return triggers.length ? triggers : [{ type: 'description_contains', value: bucket[0].description }]
}

/**
 * Suggestions created earlier that are gone now were either resolved (a transaction got a category) or rejected.
 */
async function reconcile(firefly, group, memory) {
  const pendingIds = new Set((await firefly.all(`rule-groups/${group.id}/rules`)).map((rule) => String(rule.id)))
  memory.resolved ??= 0
  const stillPending = []
  for (const created of memory.created) {
    if (pendingIds.has(String(created.rule_id))) {
      stillPending.push(created)
      continue
    }
    let anyCategorized = false
    for (const journalId of created.journal_ids) {
      const found = await firefly.get('search/transactions', { query: `journal_id:${journalId} has_any_category:true`, limit: 1 }).catch(() => null)
      if (found?.data?.length) {
        anyCategorized = true
        break
      }
    }
    if (anyCategorized) {
      memory.resolved++
    } else {
      memory.rejected.push(`${created.keyword}|${created.category}`)
    }
  }
  memory.created = stillPending
}

export function parseMetadata(description) {
  const decoded = (description ?? '').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
  if (!decoded.startsWith(MARKER)) {
    return null
  }
  try {
    return JSON.parse(decoded.slice(MARKER.length))
  } catch {
    return null
  }
}

const shiftDay = (date, days) => {
  const value = new Date(`${date}T12:00:00Z`)
  value.setUTCDate(value.getUTCDate() + days)
  return value.toISOString().slice(0, 10)
}
const dayBefore = (date) => shiftDay(date, -1)
const dayAfter = (date) => shiftDay(date, 1)
