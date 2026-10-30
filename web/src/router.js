import { createRouter, createWebHistory } from 'vue-router'
import { settings } from './lib/settings.js'

const page = (name) => () => import(`./pages/${name}.vue`)

const routes = [
  { path: '/', name: 'home', component: page('Dashboard') },
  { path: '/transactions', name: 'transactions', component: page('Transactions') },
  { path: '/accounts', name: 'accounts', component: page('Accounts') },
  { path: '/accounts/:id', name: 'account', component: page('Account') },
  { path: '/budgets', name: 'budgets', component: page('Budgets') },
  { path: '/categories', name: 'categories', component: page('Categories') },
  { path: '/tags', name: 'tags', component: page('Tags') },
  { path: '/piggy-banks', name: 'piggy-banks', component: page('PiggyBanks') },
  { path: '/recurring', name: 'recurring', component: page('Recurring') },
  { path: '/templates', name: 'templates', component: page('Templates') },
  { path: '/currencies', name: 'currencies', component: page('Currencies') },
  { path: '/suggestions', name: 'suggestions', component: page('Suggestions') },
  { path: '/suggestions/:id', name: 'suggestion', component: page('Suggestion') },
  { path: '/bank-connections', name: 'bank-connections', component: page('BankConnections') },
  // The importer sends the browser back here after the bank's consent page
  { path: '/bank-connections/list', redirect: (to) => ({ name: 'bank-connections', query: to.query }) },
  { path: '/bank-connections/new', name: 'bank-connection-new', component: page('BankConnectionNew') },
  { path: '/bank-connections/setup', name: 'bank-connection-setup', component: page('BankConnectionSetup') },
  { path: '/system-status', name: 'system-status', component: page('SystemStatus') },
  { path: '/settings', name: 'settings', component: page('Settings') },
  { path: '/more', name: 'more', component: page('More') },
  { path: '/setup', name: 'setup', component: page('Setup'), meta: { bare: true } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: (to, from, saved) => saved ?? { top: 0 },
})

router.beforeEach((to) => {
  if (!settings.token && to.name !== 'setup') return { name: 'setup', query: to.fullPath !== '/' ? { next: to.fullPath } : {} }
})
