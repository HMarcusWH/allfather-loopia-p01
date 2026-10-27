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

# P11 key ownership is in app/Provider/KeyRing.php. The renderer uses that
# validator (legacy OR versioned, never both), avoids value logging, and
# confirms bytes round-trip through the application's private Env parser.
config=/srv/allfather/.env
config_tmp=/srv/allfather/.env.prepare
[ ! -L "$config" ] || { echo 'allfather-railway: symlink configuration refused' >&2; exit 1; }
[ ! -e "$config_tmp" ] && [ ! -L "$config_tmp" ] || {
    echo 'allfather-railway: private temp configuration already exists' >&2
    exit 1
}
# A failed renderer, chown or chmod must not strand new key bytes in the
# prepared file. On success mv removes the old path before we clear the trap.
# The preexisting-file guard above makes it safe to own this cleanup.
trap 'rm -f -- "$config_tmp"' EXIT
php /srv/allfather/ops/railway/render-private-env.php "$config_tmp" || exit 1
chown root:www-data "$config_tmp"
chmod 0640 "$config_tmp"
mv -f "$config_tmp" "$config"
trap - EXIT

# This path must be backed by an isolated Railway staging volume.
mkdir -p /srv/allfather/storage
chown www-data:www-data /srv/allfather/storage
chmod 0700 /srv/allfather/storage

# Do not pass DB/master-key/scheduler credentials to Apache's child process
# environment. Only master-key VARIABLE NAMES are enumerated; no values are
# echoed and there is no eval. Preserve ordinary non-secret Railway variables.
for name in $(env | sed -n 's/^\(APP_MASTER_KEY_[A-Za-z0-9_]*\)=.*/\1/p'); do
    unset "$name"
done
unset DB_PASSWORD APP_MASTER_KEY DB_HOST DB_PORT DB_NAME DB_USER
unset SCHEDULER_TOKEN SCHEDULER_TOKEN_HASH
exec "$@"
