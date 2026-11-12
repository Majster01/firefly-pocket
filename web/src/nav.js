// Every section of the app: the desktop sidebar and the phone's More page list them in this order.
// feature: the settings.features switch that hides it
export const NAV = [
  {
    title: 'Overview',
    items: [
      { to: '/', label: 'Home', icon: 'home' },
      { to: '/transactions', label: 'Transactions', icon: 'list' },
      { to: '/accounts', label: 'Accounts', icon: 'wallet' },
    ],
  },
  {
    title: 'Plan',
    items: [
      { to: '/budgets', label: 'Budgets', icon: 'pie', feature: 'budgets' },
      { to: '/categories', label: 'Categories', icon: 'category' },
      { to: '/tags', label: 'Tags', icon: 'tag', feature: 'tags' },
      { to: '/piggy-banks', label: 'Piggy banks', icon: 'piggy', feature: 'piggyBanks' },
      { to: '/recurring', label: 'Recurring', icon: 'repeat', feature: 'recurring' },
      { to: '/templates', label: 'Templates', icon: 'template', feature: 'templates' },
    ],
  },
  {
    title: 'Automation',
    items: [
      { to: '/notifications', label: 'Notifications', icon: 'bell', badge: 'notifications' },
      { to: '/bank-connections', label: 'Bank connections', icon: 'bank', feature: 'bankConnections', badge: 'connections' },
      { to: '/suggestions', label: 'Suggestions', icon: 'sparkles', feature: 'ruleSuggestions', badge: 'suggestions' },
      { to: '/rules', label: 'Rules', icon: 'bolt', feature: 'rules' },
      { to: '/system-status', label: 'System status', icon: 'pulse', feature: 'bankConnections' },
    ],
  },
  {
    title: 'Money',
    items: [
      { to: '/currencies', label: 'Exchange rates', icon: 'currency' },
      { to: '/settings', label: 'Settings', icon: 'settings' },
    ],
  },
]
