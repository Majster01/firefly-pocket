/** A month or year step from the URL (0 = now, -1 the one before), no later than `max`. */
export const parseOffset = (value, max) => Math.min(max, (typeof value === 'string' && Number.parseInt(value, 10)) || 0)
