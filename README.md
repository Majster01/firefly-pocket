# Firefly Pocket Next

## Note: this is vibe coded for personal use. If there is any adoption I can generalize this and expose my e2e stack for easier deploys on own server

A redesign of [Firefly Pocket](https://github.com/Majster01/firefly-pocket) (a Firefly Pico fork) for Firefly III: a mobile-first PWA for the iPhone Home Screen plus a desktop layout for macOS browsers, in the "Frosted glass" design language of [firefly-money](https://github.com/Muffy0906/firefly-money) (MIT): warm translucent glass over a soft-focus room, ink pills for the main action, a white pill on the current tab. Light and Night themes, or follow the system.

- `web/`: the UI (Vue 3 + Vite, installable PWA with Web Push)
- `server/`: Pocket's Laravel API, carried over from firefly-pocket `back/` (Firefly proxy, transaction templates, bank connections, rule suggestions, EUR/VWCE exchange rates, Web Push, system status)
- `dev/`: a throwaway local stack with dummy data only: Firefly III 6.6.6, Postgres, and a mock FireflyX importer + category suggester

**`main` is production**: every merge is built (`Dockerfile`) and deployed to FireflyX at https://next.bankparse.dev. See [DEPLOYING.md](DEPLOYING.md).

## What's in the app

| Section | What it does |
| --- | --- |
| Home | A month or a year (switch and step back in time), built from tiles you show, hide, reorder (drag or arrows), resize and set up: net worth, in · out · surplus with the period before, spending by category as bars or a donut (tap a category for its transactions in that period), spending or income over time, budgets, latest transactions, accounts, uncategorized, piggy banks, and the suggestion and bank connection notices. Phone and desktop keep separate layouts, saved in Pocket's profile so they follow you to another browser |
| Transactions | Day-grouped feed, Firefly search syntax, filters (expenses, income, transfers, uncategorized), totals of a search; add/edit/delete in a sheet with templates, categories, budgets, tags; EUR → VWCE transfers ask for the shares |
| Accounts | Cash & bank, investments (VWCE), liabilities, payees and payers; per-account feed; create/edit |
| Budgets, Categories, Tags, Piggy banks, Recurring, Templates | Browse and edit; budgets per month with limits, piggy bank add/take out, recurring pause/resume |
| Exchange rates | VWCE price and holding, price history stored in Firefly, a converter, rates of your currencies (EUR base) |
| Suggestions | Review `pico-suggestion:v1` rules: edit category and conditions with a live match count, approve as rule, set category only, or reject ([contract](https://github.com/Majster01/firefly-pocket/blob/main/docs/rule-suggestions.md)) |
| Rules | Firefly III's own rules, by group: turn on and off, reorder, edit conditions (any or all, "not") and actions, find the transactions a rule matches and run it on a date range. The suggester's Suggestions group stays out of sight (`RULE_GROUP_SUGGESTIONS`) |
| Bank connections | Connect a bank (consent at the bank), map its accounts, import a date range now, renew or remove consents |
| System status | Nightly imports, missed nights, connections, bank activity, the suggester's runs and outcomes |
| Settings | Theme, feature switches, push notifications (per device: import results, problems, the suggestions digest daily or weekly, upgrades; [docs/web-push.md](docs/web-push.md)), the Firefly token |

### iPhone

Open the app in Safari and use Share → Add to Home Screen. The layout pads for the notch and home indicator (`viewport-fit=cover`, safe-area insets), inputs are 16px so Safari never zooms, sheets slide up from the bottom, and Web Push works from the Home Screen app (iOS 16.4+). From 900px wide the tab bar becomes a floating sidebar and sheets become centered dialogs.

## Caching

Pocket keeps Firefly's answers so that opening a page does not wait on Firefly for data that has not changed (`server/app/Services/ResponseCache.php`, `server/app/Http/Middleware/CacheFireflyResponses.php`).

- Every GET Pocket forwards to Firefly is kept per token, under the current **data version**. Pocket's own data (profiles, templates, bank connections, system status, push, the assistant) is not.
- The data version moves on, and everything kept so far is out of date, when:
  - something is saved, changed or deleted through Pocket (adding a transaction, approving or rejecting a suggestion, an import started from Bank connections). Saving the Home layout and previewing a suggestion's conditions do not count;
  - Settings → Data → **Sync everything** is pressed (`POST /api/data-version`). It also throws away what this browser kept, and reads again the lists the app has loaded; every page then reads Firefly again when it is opened;
  - the importer or suggester calls `POST /api/internal/notify`;
  - Firefly's webhook calls `POST /api/internal/firefly-webhook`, for changes made outside Pocket (Firefly's own pages, the data importer).
- Answers expire after `RESPONSE_CACHE_TTL` (12 hours) in any case, and at midnight in Firefly's timezone (`RESPONSE_CACHE_TIMEZONE`, else `TZ`), since some answers move with the date alone.
- Answers carry an ETag, so the browser gets an empty 304 when nothing changed. A request with `Cache-Control: no-cache` skips the kept answer.
- A token Firefly accepted is trusted for `USER_CACHE_TTL` (10 minutes) before Pocket asks Firefly again. A token revoked in Firefly can read kept answers for at most that long.

**In the browser.** The web app keeps those answers too, in IndexedDB, so they survive closing the app (`web/src/lib/cache.js`):
- It shows a kept answer only while `GET /api/data-version` still names the version it was kept under, and only on the day it was kept.
- It asks for the version again:
  - when the app starts;
  - when it comes back to the screen;
  - after anything it saves;
  - before a read, when the last answer is more than 30 seconds old.
- Reads wait for that check, so the app never shows a kept answer and then swaps it for a newer one.
- Opening Home again with nothing changed sends only the version check and Pocket's own requests (profile, bank connections, exchange rates).
- Another token or signing out throws away what was kept.

**One request per page.** The app sends the reads a page asks for at the same moment as one `POST /api/batch` (`server/app/Services/BatchReader.php`).
- Pocket answers the kept ones from its cache and asks Firefly for the others in parallel.
- It then runs each read through its usual route, so the answers and the caching are the same as for single requests.
- Rule suggestions and search totals are left out: Pocket works those out with several calls one after another, and they would hold up the whole batch.

**Warm-up after a change** (`server/app/Services/CacheWarmUp.php`). Pocket remembers which reads each token's Home asked for in the last 8 days. `WARMUP_DELAY` seconds (default 60) after the data version moves on, a queued job fetches them again, so the first open after the nightly import finds them kept. Many changes in a row (an import) lead to a single warm-up.

What it needs:
- `WARMUP_FIREFLY_TOKEN`: a Firefly personal access token of the same user, used only for this. Pocket does not store the app's tokens: it fetches with this token and keeps the answers for each remembered token that belongs to the same Firefly user.
- A queue worker: the Docker image's Horizon with `QUEUE_CONNECTION=redis`. With the `sync` queue the warm-up is off.

The remembered reads are for whole months, so on the first day of a month the warm-up still fetches the month before.

In production use Redis, which the Docker image already runs for Horizon: `CACHE_DRIVER=redis`. Firefly III can use Redis for its own cache too (`CACHE_DRIVER=redis` and `REDIS_*` in Firefly's environment).

**Firefly webhook.** Webhooks may be switched off in Firefly III (`allow_webhooks`; the site owner turns them on in Firefly's administration settings). Then add one under Automation → Webhooks:
- URL: `http://<pocket>/api/internal/firefly-webhook`, an address reachable on the compose network;
- trigger: "After any event", which covers transactions, budgets and budgeted amounts;
- response: "No details" (Pocket reads only the signature, not the content).

Copy its secret to `FIREFLY_WEBHOOK_SECRET`. Like `/api/internal/notify`, keep the path off the public reverse proxy.

**Timing.** Every API answer has a `Server-Timing` header, shown in the browser's network panel:
- `total`: the time in Pocket;
- `upstream`: the time waiting on Firefly and the importer, with the number of calls;
- `cache`: hit, miss or bypass.

`SERVER_TIMING_LOG=true` also logs one line per request.

## Local development

Needs Docker, Node 22 and PHP 8.2+ with Composer. Nothing here talks to a real bank, a real Firefly or an LLM.

```sh
dev/scripts/bootstrap.sh   # Firefly III + Postgres + mock importer, a dev user, tokens, ~6 months of dummy data
dev/scripts/dev.sh         # Pocket's API on :8000 and the web app on :5173
```

Then open http://localhost:5173 and paste the token from `dev/.web-token.env`. Firefly III itself is at http://localhost:8081 (`dev@example.com` / `Pocket-dev-only-9f2c71`).

- `dev/scripts/bootstrap.sh --reset` throws the data away and starts over.
- On a host without IPv6 (some Linux cloud machines), set `POCKET_DEV_IPV4=1`.
- The seed (`dev/scripts/seed.mjs`) refuses any Firefly that is not on localhost.

The dummy data: EUR as primary currency; NLB Current, Revolut, Savings, Wallet, and an IBKR account in VWCE shares with a monthly €400 buy and monthly EUR prices; a car loan; 15 categories with notes; 5 budgets with monthly limits; tags, piggy banks and recurring transactions; the `Categorization` rule group (guard + category rules) and pending suggestions in `Suggestions` for the last ~40 days of bank-fed transactions, which stay uncategorized.

The mock importer (`dev/mock-importer/`) speaks the FireflyX fork's `/api/fireflyx/v1` contract: banks per country, a fake consent page (Allow / Deny) that sends the browser back to the web app, account mapping, imports that write a deterministic dummy bank feed into Firefly (re-imports are skipped as duplicates) and then run a table-driven suggester, and the status report.
