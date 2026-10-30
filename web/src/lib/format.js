const formatters = new Map()

function formatter(code, decimals) {
  const key = `${code}|${decimals}`
  if (!formatters.has(key)) {
    let value
    try {
      value = new Intl.NumberFormat(undefined, { style: 'currency', currency: code, minimumFractionDigits: decimals, maximumFractionDigits: decimals })
    } catch {
      // Not an ISO currency (VWCE shares): a plain number with the code after it
      const number = new Intl.NumberFormat(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
      value = { format: (amount) => `${number.format(amount)} ${code}` }
    }
    formatters.set(key, value)
  }
  return formatters.get(key)
}

export function money(amount, code = 'EUR', { decimals, signed = false } = {}) {
  const value = Number(amount ?? 0)
  const places = decimals ?? (code === 'VWCE' ? 4 : 2)
  const text = formatter(code || 'EUR', places).format(Math.abs(value))
  if (value < 0) return `−${text}`
  return signed && value > 0 ? `+${text}` : text
}

export const shares = (amount) => `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(Number(amount ?? 0))}`

const dayFormat = new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' })
const dayFormatYear = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'long', year: 'numeric' })
const shortFormat = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' })
const shortFormatYear = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
const monthFormat = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' })

export const isoDate = (date = new Date()) => {
  const value = new Date(date)
  return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
}
const toDate = (value) => (value instanceof Date ? value : new Date(String(value).length === 10 ? `${value}T12:00:00` : value))

export function dayLabel(value) {
  const date = toDate(value)
  const today = isoDate()
  const key = isoDate(date)
  if (key === today) return 'Today'
  if (key === isoDate(Date.now() - 86400000)) return 'Yesterday'
  return date.getFullYear() === new Date().getFullYear() ? dayFormat.format(date) : dayFormatYear.format(date)
}
export const shortDate = (value) => {
  if (!value) return ''
  const date = toDate(value)
  return date.getFullYear() === new Date().getFullYear() ? shortFormat.format(date) : shortFormatYear.format(date)
}
export const monthLabel = (value) => monthFormat.format(toDate(value))

export function timeAgo(value) {
  if (!value) return ''
  const seconds = (Date.now() - toDate(value).getTime()) / 1000
  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
  const steps = [
    [60, 'second'],
    [3600, 'minute', 60],
    [86400, 'hour', 3600],
    [86400 * 30, 'day', 86400],
    [86400 * 365, 'month', 86400 * 30],
    [Infinity, 'year', 86400 * 365],
  ]
  for (const [limit, unit, size = 1] of steps) {
    if (Math.abs(seconds) < limit) return rtf.format(-Math.round(seconds / size), unit)
  }
  return ''
}

export const monthRange = (offset = 0, base = new Date()) => {
  const start = new Date(base.getFullYear(), base.getMonth() + offset, 1)
  const end = new Date(base.getFullYear(), base.getMonth() + offset + 1, 0)
  return { start: isoDate(start), end: isoDate(end) }
}

export const initials = (name = '') =>
  name
    .replace(/[^\p{L}\p{N} ]/gu, ' ')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('') || '·'
