// The Transactions page's state, its URL and the Firefly search it stands for. The budget and category sheets build
// the same state, so what a sheet counts and what its "Show all" lists come from one query.

export const FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'withdrawal', label: 'Expenses' },
  { value: 'deposit', label: 'Income' },
  { value: 'transfer', label: 'Transfers' },
  { value: 'uncategorized', label: 'Uncategorized' },
]

const isDay = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value ?? '')
// A parameter given twice arrives as an array: ignored, like a missing one
const textOf = (value) => (typeof value === 'string' ? value : '')
const nameTerm = (operator, name) => `${operator}:"${name.replace(/"/g, '\\"')}"`

/** The state with everything unset filled in. The key order is fixed, so two states compare as JSON. */
export const pageState = ({ search = '', filter = 'all', category = '', budget = '', start = '', end = '' } = {}) => ({ search, filter, category, budget, start, end })

export const fromRouteQuery = (query) =>
  pageState({
    search: textOf(query.q),
    filter: FILTERS.some((option) => option.value === query.filter) ? query.filter : 'all',
    category: textOf(query.category),
    budget: textOf(query.budget),
    start: isDay(query.start) ? query.start : '',
    end: isDay(query.end) ? query.end : '',
  })

export function toRouteQuery(state) {
  const { search, filter, category, budget, start, end } = pageState(state)

  return {
    ...(search ? { q: search } : {}),
    ...(filter !== 'all' ? { filter } : {}),
    ...(category ? { category } : {}),
    ...(budget ? { budget } : {}),
    ...(start ? { start, ...(end ? { end } : {}) } : {}),
  }
}

/** Empty when no search is needed: the plain list, filtered by type, answers that. */
export function searchQuery(state) {
  const { search, filter, category, budget, start, end } = pageState(state)
  const parts = []

  if (search.trim()) {
    parts.push(search.trim())
  }

  if (category) {
    parts.push(nameTerm('category_is', category))
  }

  if (budget) {
    parts.push(nameTerm('budget_is', budget))
  }

  if (start) {
    parts.push(`date_after:${start}`)
  }

  if (end) {
    parts.push(`date_before:${end}`)
  }

  if (filter === 'uncategorized') {
    parts.push('has_no_category:true type:withdrawal')
  } else if (parts.length && filter !== 'all') {
    parts.push(`type:${filter}`)
  }

  return parts.join(' ')
}

export function isFiltered(state) {
  const { filter, category, budget, start } = pageState(state)

  return filter !== 'all' || Boolean(category || budget || start)
}

/** A total is worth showing for a search, a name or a period, not for a type alone. */
export function showsTotal(state) {
  const { search, category, budget, start } = pageState(state)

  return Boolean(search.trim() || category || budget || start)
}
