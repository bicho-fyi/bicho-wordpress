#!/bin/sh
set -eu
: "${WP_ADMIN_PASSWORD:?WP_ADMIN_PASSWORD is required}"
: "${WP_SITE_URL:?WP_SITE_URL is required}"
remaining=120
until [ -f wp-includes/version.php ] && [ -f wp-settings.php ] && [ -f wp-config.php ]; do
    remaining=$((remaining - 1))
    if [ "$remaining" -eq 0 ]; then
        printf '%s\n' 'Timed out waiting for WordPress core extraction' >&2
        exit 1
    fi
    sleep 1
done
if ! wp core is-installed; then
    printf '%s\n' "$WP_ADMIN_PASSWORD" | wp core install \
        --url="$WP_SITE_URL" \
        --title='Bicho WordPress Development' \
        --admin_user="${WP_ADMIN_USER:-bicho}" \
        --admin_email="${WP_ADMIN_EMAIL:-dev@example.test}" \
        --skip-email --prompt=admin_password
fi
unset WP_ADMIN_PASSWORD
wp option update home "$WP_SITE_URL"
wp option update siteurl "$WP_SITE_URL"
wp plugin activate bicho-error-tracking
wp rewrite flush
printf '%s\n' 'WordPress is ready'
exec tail -f /dev/null
