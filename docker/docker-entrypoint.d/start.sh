#!/bin/sh
#
# Start Pocket: render the nginx site, wait for the database, migrate, cache
# the configuration, then hand over to supervisord (nginx, php-fpm, redis,
# Horizon).

# NGINX_PORT lets a host that does not allow non-root processes to bind port 80
# pick an unprivileged one (Synology, podman, hardened kernels)
NGINX_PORT="${NGINX_PORT:-80}"
case "$NGINX_PORT" in
    ''|*[!0-9]*)
        echo "Invalid NGINX_PORT '$NGINX_PORT', falling back to 80"
        NGINX_PORT=80
        ;;
esac
sed "s/\${NGINX_PORT}/$NGINX_PORT/g" \
    /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf
echo "nginx will listen on port $NGINX_PORT"

if [ "$DB_CONNECTION" = "sqlite" ]; then
    echo "Using a SQLite database"
    mkdir -p /var/www/html/database/data
else
    while true; do
        # A DB_HOST that starts with "/" is a Unix socket
        if echo "$DB_HOST" | grep -q "^/"; then
            if [ -S "$DB_HOST" ]; then
                echo "Successfully connected to DB via Unix socket: $DB_HOST"
                break
            fi
            echo "Failed to connect to DB via Unix socket: $DB_HOST. Retrying in 10 seconds..."
        else
            if nc -z -w 5 "$DB_HOST" "${DB_PORT:-5432}"; then
                echo "Successfully connected to DB via TCP: $DB_HOST:${DB_PORT:-5432}"
                break
            fi
            echo "Failed to connect to DB via TCP: $DB_HOST:${DB_PORT:-5432}. Retrying in 10 seconds..."
        fi
        sleep 10
    done
fi

set -e
# A configuration cached by an earlier start of this container would hide a
# changed environment
php /var/www/html/artisan config:clear
# --isolated takes a cache lock, and the bundled redis only starts with
# supervisord below, so the migration locks on the file store
CACHE_DRIVER=file php /var/www/html/artisan migrate --isolated --force
php /var/www/html/artisan config:cache
set +e

# The bundled redis does not persist, so the cache starts empty on every start
exec supervisord -c /etc/supervisord.conf
