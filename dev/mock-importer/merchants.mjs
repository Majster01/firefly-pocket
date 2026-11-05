// Dummy counterparties, shared by the seed script and the mock importer's fake bank feed.
// category null = the bank feed brings it without one; the Categorization rules or the suggester pick it up.

export const GROCERIES = [
  { description: 'MERCATOR HM CENTER LJUBLJANA', destination: 'Mercator', min: 18, max: 92 },
  { description: 'SPAR SLOVENIJA 0412 LJUBLJANA', destination: 'Spar', min: 12, max: 70 },
  { description: 'LIDL SLOVENIJA TRZASKA', destination: 'Lidl', min: 15, max: 65 },
  { description: 'HOFER TRGOVINA 112', destination: 'Hofer', min: 10, max: 55 },
]

export const EATING_OUT = [
  { description: 'GOSTILNA SESTICA LJUBLJANA', destination: 'Gostilna Šestica', min: 18, max: 46 },
  { description: 'BURGERLAB CENTER LJUBLJANA', destination: 'Burgerlab', min: 11, max: 24 },
  { description: 'KAVARNA ROG LJUBLJANA', destination: 'Kavarna Rog', min: 3.2, max: 9.8 },
  { description: 'WOLT.COM ORDER', destination: 'Wolt', min: 14, max: 32 },
]

export const TRANSPORT = [
  { description: 'PETROL BS LJUBLJANA TRZASKA', destination: 'Petrol', min: 42, max: 74 },
  { description: 'BOLT.EU RIDE', destination: 'Bolt', min: 5.5, max: 14 },
]

export const OCCASIONAL = [
  { description: 'IKEA LJUBLJANA', destination: 'IKEA', min: 25, max: 180, category: 'Home' },
  { description: 'DECATHLON LJUBLJANA', destination: 'Decathlon', min: 19, max: 95, category: 'Shopping' },
  { description: 'AMAZON EU MKTP', destination: 'Amazon', min: 12, max: 120, category: 'Shopping' },
  { description: 'ZARA CITYPARK', destination: 'Zara', min: 25, max: 89, category: 'Shopping' },
  { description: 'LEKARNA LJUBLJANA 23', destination: 'Lekarna Ljubljana', min: 6, max: 38, category: 'Health' },
  { description: 'KOLOSEJ KINEMATOGRAFI', destination: 'Kolosej', min: 9, max: 24, category: 'Entertainment' },
]

// Only ever uncategorized: the suggester's material
export const UNSORTED = [
  { description: 'GOOGLE *YOUTUBE PREMIUM', destination: 'Google', min: 13.99, max: 13.99 },
  { description: 'NRC00 ANA NOVAK DARILO', destination: 'Ana Novak', min: 20, max: 35 },
  { description: 'SP MOJCA KRALJ S.P.', destination: 'Mojca Kralj s.p.', min: 45, max: 60 },
  { description: 'TRAFIKA 3DVA BTC', destination: 'Trafika 3DVA', min: 4, max: 12 },
]

// Rough monthly VWCE close in EUR (dummy)
export const VWCE_PRICES = {
  '2026-04': 126.8,
  '2026-05': 128.4,
  '2026-06': 131.2,
  '2026-07': 133.0,
  '2026-08': 129.7,
  '2026-09': 134.9,
  '2026-10': 137.6,
  '2026-11': 138.1,
}

// A tiny seeded PRNG so a re-seed gives the same data
export function random(seed = 42) {
  let state = seed >>> 0
  const next = () => {
    state = (state + 0x6d2b79f5) >>> 0
    let t = state
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
  return {
    next,
    pick: (list) => list[Math.floor(next() * list.length)],
    amount: (min, max) => (min === max ? min : Math.round((min + next() * (max - min)) * 100) / 100),
    chance: (p) => next() < p,
  }
}

export function iban(country, bban) {
  const digits = `${bban}${country}00`.replace(/[A-Z]/g, (letter) => String(letter.charCodeAt(0) - 55))
  const check = String(98n - (BigInt(digits) % 97n)).padStart(2, '0')
  return `${country}${check}${bban}`
}
