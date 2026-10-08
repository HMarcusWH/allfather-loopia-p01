#!/bin/sh
# Railway staging adaptation only. Does not authorize a mission, migration, or scheduler.
set -eu
umask 077

# Recheck Apache modules in the actual Railway container, not only the image build.
# The first staging boot observed AH00534 despite a successful image build.
for mpm in mpm_event mpm_worker; do
    if [ -e "/etc/apache2/mods-enabled/$mpm.load" ]; then
        a2dismod -f "$mpm" >&2
    fi
done
a2enmod mpm_prefork >&2
for module in /etc/apache2/mods-enabled/mpm_*.load; do
    [ -e "$module" ] || continue
    printf 'allfather-railway: apache MPM: %s\n' "${module##*/}" >&2
done
apache2ctl -t >&2 || { echo 'allfather-railway: invalid runtime Apache configuration' >&2; exit 1; }

if [ "${APP_ENV:-}" != "staging" ]; then
    echo 'allfather-railway: refusing non-staging APP_ENV' >&2
    exit 1
fi

: "${DB_HOST:?missing DB_HOST}"
: "${DB_PORT:?missing DB_PORT}"
: "${DB_NAME:?missing DB_NAME}"
: "${DB_USER:?missing DB_USER}"
: "${DB_PASSWORD:?missing DB_PASSWORD}"
: "${APP_MASTER_KEY:?missing APP_MASTER_KEY}"

# Reject CR and LF injection into the application's line-oriented .env format.
cr=$(printf '\r')
lf='
'
for item in "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$DB_PASSWORD" "$APP_MASTER_KEY" "${APP_BASE_URL:-}" "${RAILWAY_GIT_COMMIT_SHA:-}"; do
    case "$item" in
        *"$cr"*|*"$lf"*) echo 'allfather-railway: multiline configuration refused' >&2; exit 1 ;;
    esac
done

config=/srv/allfather/.env
config_tmp=/srv/allfather/.env.prepare
[ ! -L "$config" ] || { echo 'allfather-railway: symlink configuration refused' >&2; exit 1; }
[ ! -L "$config_tmp" ] || { echo 'allfather-railway: symlink temp configuration refused' >&2; exit 1; }
{
    printf 'APP_ENV=staging\n'
    printf 'APP_BASE_URL=%s\n' "${APP_BASE_URL:-https://railway-staging.invalid}"
    printf 'DB_HOST=%s\n' "$DB_HOST"
    printf 'DB_PORT=%s\n' "$DB_PORT"
    printf 'DB_NAME=%s\n' "$DB_NAME"
    printf 'DB_USER=%s\n' "$DB_USER"
    printf 'DB_PASSWORD=%s\n' "$DB_PASSWORD"
    printf 'APP_MASTER_KEY=%s\n' "$APP_MASTER_KEY"
    printf 'APP_RELEASE_REF=%s\n' "${RAILWAY_GIT_COMMIT_SHA:-UNQUALIFIED_RAILWAY_STAGING}"
    # Scheduler token deliberately omitted: scheduler is unreachable.
} > "$config_tmp"
chown root:www-data "$config_tmp"
chmod 0640 "$config_tmp"
mv -f "$config_tmp" "$config"

# This path must be backed by an isolated Railway staging volume.
mkdir -p /srv/allfather/storage
chown www-data:www-data /srv/allfather/storage
chmod 0700 /srv/allfather/storage

# Do not pass DB/key credentials to Apache's child process environment.
unset DB_PASSWORD APP_MASTER_KEY DB_HOST DB_PORT DB_NAME DB_USER
exec "$@"
