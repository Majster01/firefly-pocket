/** What is left of a limit, in cents' precision: an exactly spent budget is 0 left, not a float's hair over. */
export const leftOf = (limit, spent) => Math.round((limit - spent) * 100) / 100 || 0

/** Spent as a share of the limit, from the same cents as `leftOf`: above 1 only when a cent or more over. */
export const ratioOf = (limit, spent) => (limit > 0 ? (limit - leftOf(limit, spent)) / limit : 0)

export const isOverLimit = ({ limit, left }) => limit > 0 && left < 0

/** "€12.00 left of €400.00": where a budget stands against its month's limit. `money` formats the amounts. */
export function budgetStanding(budget, periodName, money) {
  const { limit, left } = budget

  if (!(limit > 0)) {
    return `No limit in ${periodName}`
  }

  return isOverLimit(budget) ? `${money(-left)} over ${money(limit)}` : `${money(left)} left of ${money(limit)}`
}
