# Firefly Pocket Next

A redesign of [Firefly Pocket](https://github.com/Majster01/firefly-pocket) (a Firefly Pico fork) for Firefly III: a mobile-first PWA for iPhone Home Screen use plus a desktop layout for macOS browsers, in the frosted-glass design language of [firefly-money](https://github.com/Muffy0906/firefly-money) (MIT).

- `web/`: the new UI (Vue 3 + Vite PWA)
- `server/`: Pocket's Laravel API, carried over from firefly-pocket `back/` (Firefly proxy, bank connections, rule suggestions, EUR/VWCE exchange rates, Web Push, system status)
- `dev/`: a throwaway local stack with dummy data: Firefly III 6.6.6, Postgres, and a mock FireflyX importer/suggester

Work in progress.
