#!/bin/sh
# One-time (idempotent) local dev setup: installs deps and creates back/.env + SQLite DB.
# Usage: scripts/dev-setup.sh [FIREFLY_URL]   (default: https://firefly.bankparse.dev, the ../fireflyx deployment)
set -e
cd "$(dirname "$0")/.."
# Match production PHP (8.4); the composer lockfile does not support 8.5 yet
[ -d /opt/homebrew/opt/php@8.4/bin ] && PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
FIREFLY_URL="${1:-https://firefly.bankparse.dev}"

(cd back && composer install)
if [ ! -f back/.env ]; then
  sed "s#^FIREFLY_URL=.*#FIREFLY_URL=$FIREFLY_URL#; s#^APP_URL=.*#APP_URL=http://localhost:8000#" back/.env.example > back/.env
  (cd back && php artisan key:generate)
fi
mkdir -p back/database/data
touch back/database/data/database.sqlite
(cd back && php artisan migrate --force)

(cd front && npm install)

[ -f .dev.env ] || printf '# Firefly III Personal Access Token used by Claude/playwright to log in locally. Gitignored.\nPICO_DEV_TOKEN=\n' > .dev.env
echo "Done. Put a Firefly PAT in .dev.env, then run scripts/dev.sh"
