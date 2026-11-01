# Deploying

**`main` is production.** Every push to `main`, in practice every merged PR, is built and deployed to the FireflyX server at https://next.bankparse.dev with nothing else to do. Merge only what you want live; until then it stays on its branch.

## What a merge does

1. **Build** (`.github/workflows/deploy.yml`, about ten minutes): the `Dockerfile` builds `web/` and `server/` into one image, pushed to GHCR as `ghcr.io/majster01/firefly-pocket-next:sha-<commit>`.
2. **Pin**: the workflow starts *Pin fork image* in [FireflyX](https://github.com/Majster01/FireflyX) with `component=next`, which writes that tag into `compose/docker-compose.yml` and commits `Bump Next to <commit>` to FireflyX `master`.
3. **Deploy**: the server checks FireflyX `master` every two minutes, pulls the image, restarts only the `next` service and checks that `/` and `/api/test` answer (keep that route: it is the smoke test). A push notification (ntfy and the Pico app) says `FireflyX: deployed …`, or which step failed.

A push that only changes `*.md` or `docs/` builds and deploys nothing.

## The image

- One container, port 80: nginx serves the built web app (`web/dist`) with a fallback to `index.html` for the client-side routes, and sends `/api` to Laravel in php-fpm. Hashed files under `/assets/` are cached for a year; `index.html`, `sw.js` and the manifest are asked for again on every load, so a deploy reaches the phone.
- A bundled redis (no persistence) holds the Horizon queue and the cache of Firefly answers: the image sets `QUEUE_CONNECTION=redis` and `CACHE_DRIVER=redis` (README "Caching"). A restart starts with an empty cache.
- On start it waits for the database, runs the migrations and caches the configuration. `APP_VERSION` (build argument) ends up in `/api/info`.
- FireflyX sets the rest in its compose file: `APP_KEY`, `APP_URL`, `FIREFLY_URL`, the database, the importer API, and the push settings.

Build it locally with `docker build --build-arg APP_VERSION=dev -t firefly-pocket-next .`.

## Rules

- **Work on branches; merging into `main` is the deploy button.** Never push straight to `main`.
- **Agents: never merge into or push to `main` unless the user asked to deploy, in this session.** Open a PR and stop there.
- **Tags do nothing.** There are no releases; `main` is the version.
- **Server first.** A change that needs something new on the server (an environment variable, a FireflyX or importer API it calls) lands there first: the variable goes into FireflyX's `compose/docker-compose.yml` and the server's `.env`, the importer change into its `fireflyx` branch, and only then is this merged. Keep Pocket working against the older importer and suggester for as long as they may be older.
- **Migrations run on start**, against Pocket Next's own database (`firefly_next`, not Pico's), and it gets no backup right before it is deployed (only the nightly one at 02:00). Keep them additive: add tables and columns; drop or rewrite data only in a later release, once nothing reads it.
- **New environment variables** need a default or a line in FireflyX's compose file; the image is the same everywhere, the configuration is FireflyX's.
- **Firefly III's webhooks are off** on FireflyX (`ALLOW_WEBHOOKS: "false"`), so the cache learns about changes made outside Pocket only from `/api/internal/notify` and the TTL (README "Caching").

## When something goes wrong

| Where it stopped | Look at | Then |
|---|---|---|
| Build | this repository's Actions, *Deploy* | Fix and merge again. Nothing was deployed. |
| Pin | FireflyX's Actions, *Pin fork image* | "cannot be pulled without a login": the GHCR package is private (first publish only). Make it public in its package settings and re-run. |
| Deploy | the push notification; `journalctl -u fireflyx-deploy` on the server | A failed pull restarts nothing. After a failed restart, fix forward or roll back. |

**Roll back** with FireflyX's Actions → *Pin fork image* → *Run workflow*, `next` and an earlier tag (FireflyX's `Bump Next to …` commits list them). Reverting the PR here works too: it builds and deploys the revert. If the rolled-back version predates a migration, the `firefly_next` database may need restoring as well (FireflyX README, "Restoring").

The `FIREFLYX_DISPATCH_TOKEN` secret (a fine-grained token for FireflyX only, *Actions: Read and write*) is what lets this repository start the pin. When it expires, the *Deploy* job fails at its last step; renew it here and in the other forks.
