import { START_LOCATION, createRouter, createWebHistory } from 'vue-router'
import { nextTick } from 'vue'
import { settings } from './lib/settings.js'

const page = (name) => () => import(`./pages/${name}.vue`)

// meta.title: the browser tab and what a screen reader hears on arrival; meta.feature: the settings.features switch that hides it (as in nav.js)
const routes = [
  { path: '/', name: 'home', component: page('Dashboard'), meta: { title: 'Home' } },
  { path: '/transactions', name: 'transactions', component: page('Transactions'), meta: { title: 'Transactions' } },
  { path: '/accounts', name: 'accounts', component: page('Accounts'), meta: { title: 'Accounts' } },
  { path: '/accounts/:id', name: 'account', component: page('Account'), meta: { title: 'Account' } },
  { path: '/budgets', name: 'budgets', component: page('Budgets'), meta: { title: 'Budgets', feature: 'budgets' } },
  { path: '/categories', name: 'categories', component: page('Categories'), meta: { title: 'Categories' } },
  { path: '/tags', name: 'tags', component: page('Tags'), meta: { title: 'Tags', feature: 'tags' } },
  { path: '/piggy-banks', name: 'piggy-banks', component: page('PiggyBanks'), meta: { title: 'Piggy banks', feature: 'piggyBanks' } },
  { path: '/recurring', name: 'recurring', component: page('Recurring'), meta: { title: 'Recurring', feature: 'recurring' } },
  { path: '/templates', name: 'templates', component: page('Templates'), meta: { title: 'Templates', feature: 'templates' } },
  { path: '/currencies', name: 'currencies', component: page('Currencies'), meta: { title: 'Exchange rates' } },
  { path: '/suggestions', name: 'suggestions', component: page('Suggestions'), meta: { title: 'Suggestions', feature: 'ruleSuggestions' } },
  { path: '/suggestions/:id', name: 'suggestion', component: page('Suggestion'), meta: { title: 'Suggestion', feature: 'ruleSuggestions' } },
  { path: '/rules', name: 'rules', component: page('Rules'), meta: { title: 'Rules', feature: 'rules' } },
  // "new" before :id; ?group=<id> picks the group of a new rule
  { path: '/rules/new', name: 'rule-new', component: page('Rule'), meta: { title: 'New rule', feature: 'rules' } },
  { path: '/rules/:id(\\d+)', name: 'rule', component: page('Rule'), meta: { title: 'Rule', feature: 'rules' } },
  { path: '/bank-connections', name: 'bank-connections', component: page('BankConnections'), meta: { title: 'Bank connections', feature: 'bankConnections' } },
  // The importer sends the browser back here after the bank's consent page
  { path: '/bank-connections/list', redirect: (to) => ({ name: 'bank-connections', query: to.query }) },
  { path: '/bank-connections/new', name: 'bank-connection-new', component: page('BankConnectionNew'), meta: { title: 'Connect a bank', feature: 'bankConnections' } },
  { path: '/bank-connections/setup', name: 'bank-connection-setup', component: page('BankConnectionSetup'), meta: { title: 'Set up accounts', feature: 'bankConnections' } },
  { path: '/system-status', name: 'system-status', component: page('SystemStatus'), meta: { title: 'System status', feature: 'bankConnections' } },
  { path: '/settings', name: 'settings', component: page('Settings'), meta: { title: 'Settings' } },
  { path: '/more', name: 'more', component: page('More'), meta: { title: 'More' } },
  { path: '/setup', name: 'setup', component: page('Setup'), meta: { bare: true, title: 'Set up' } },
  { path: '/oauth/callback', name: 'oauth-callback', component: page('OAuthCallback'), meta: { bare: true, title: 'Signing in' } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: (to, from, saved) => saved ?? { top: 0 },
})

router.beforeEach((to) => {
  if (!settings.token && to.name !== 'setup' && to.name !== 'oauth-callback') return { name: 'setup', query: to.fullPath !== '/' ? { next: to.fullPath } : {} }
  // A switched-off section stays closed to bookmarks and old links too
  if (to.meta.feature && !settings.features[to.meta.feature]) return '/'
})

router.afterEach((to, from, failure) => {
  if (failure) return
  document.title = to.meta.title ? `${to.meta.title} · Pocket` : 'Firefly Pocket'
  // A single-page app announces nothing on its own: move focus to the new page's heading.
  // Not on the first load (the browser starts at the top anyway) and not when only the query changes.
  if (from === START_LOCATION || from.path === to.path) return
  nextTick(() => document.querySelector('main h1, h1')?.focus({ preventScroll: true }))
})

// After an upgrade the open page still asks for the old build's files, which the server no longer has: the page's
// chunk fails to load and the navigation silently goes nowhere (the tab bar looks dead). Load the page afresh at the
// place that was asked for, which runs the new version. Once per minute at most, so a server that is really down
// cannot make a reload loop.
router.onError((error, to) => {
  if (!/dynamically imported module|Importing a module script failed|Loading chunk|Unable to preload/i.test(String(error?.message ?? error))) return
  try {
    const last = Number(sessionStorage.getItem('chunk-reload') ?? 0)
    if (Date.now() - last < 60000) return
    sessionStorage.setItem('chunk-reload', String(Date.now()))
  } catch {
    // No sessionStorage: reload anyway, once, below
  }
  window.location.assign(to?.fullPath ?? '/')
})
