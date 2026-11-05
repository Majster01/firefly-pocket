# AGENTS.md

Guidance for AI coding agents working in this repository. README.md describes the app; DEPLOYING.md how it reaches production.

## Deploying: `main` is production

Every push to `main` (a merged PR) is built and deployed to the live Firefly Pocket server (https://next.bankparse.dev) within about ten minutes; see [DEPLOYING.md](DEPLOYING.md). So:

- Never merge into or push to `main` unless the user asked to deploy, in this session. Work on a branch, open a PR, and stop there.
- A change that needs something new on the server (an environment variable, a firefly-pocket-stack or importer API) is deployed there first, through firefly-pocket-stack. Say so in the PR.
- Keep migrations additive: there is no backup right before a deploy.
