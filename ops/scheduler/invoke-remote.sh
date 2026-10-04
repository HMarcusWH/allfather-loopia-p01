#!/usr/bin/env bash
# Invoke the bounded scheduler tick on the Loopia host over SSH, from a scheduler you control
# (option F in docs/LOOPIA_HOST_MODEL.md §4; see docs/MONITOR_SCHEDULING.md).
#
# This runs ON THE EXTERNAL SCHEDULER HOST (a cron box, a CI schedule), never on Loopia: Loopia has
# no shell crontab. The authority is possession of a DEDICATED SSH key, used for nothing else. On
# Loopia shared hosting an SSH login is ACCOUNT-WIDE — it can read every site in the account — so this
# identity must not be described as site-isolated, and a forced command on the remote key is the
# operator's own hardening, not something this script can verify.
#
# It sends no work definition: the remote command is fixed to `php <app>/worker/tick.php`, which runs
# the next bounded batch and nothing else, and refuses to run from a web request. Whether that tick
# does anything is decided on the host by owner-admitted schedules (none ship with this repository).
# The output is the tick's small JSON receipt; nothing here prints a key, a token or an env file.
set -euo pipefail
umask 077

usage() {
  cat >&2 <<'EOF'
usage: ops/scheduler/invoke-remote.sh --host=<ssh-host> --user=<ssh-user> --identity=<0600 private key> \
       --known-hosts=<pinned known_hosts file> --app-dir=<absolute app path on the host> [--port=<22>]
EOF
  exit 2
}

HOST= SSH_USER= IDENTITY= KNOWN_HOSTS= APP_DIR= PORT=22
for arg in "$@"; do
  case "$arg" in
    --host=*) HOST="${arg#*=}" ;;
    --user=*) SSH_USER="${arg#*=}" ;;
    --identity=*) IDENTITY="${arg#*=}" ;;
    --known-hosts=*) KNOWN_HOSTS="${arg#*=}" ;;
    --app-dir=*) APP_DIR="${arg#*=}" ;;
    --port=*) PORT="${arg#*=}" ;;
    *) usage ;;
  esac
done
[ -n "$HOST" ] && [ -n "$SSH_USER" ] && [ -n "$IDENTITY" ] && [ -n "$KNOWN_HOSTS" ] && [ -n "$APP_DIR" ] || usage

# Everything that reaches the remote command line is validated to a closed alphabet: no quoting, no
# word splitting, no option injection into ssh, no shell metacharacters on the far side.
[[ "$HOST" =~ ^[A-Za-z0-9.-]{1,253}$ ]] || { echo "invoke-remote: unsafe host" >&2; exit 2; }
[[ "$SSH_USER" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || { echo "invoke-remote: unsafe user" >&2; exit 2; }
[[ "$PORT" =~ ^[0-9]{1,5}$ ]] && [ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] || { echo "invoke-remote: unsafe port" >&2; exit 2; }
[[ "$APP_DIR" =~ ^/[A-Za-z0-9._/-]{1,240}$ ]] || { echo "invoke-remote: app dir must be an absolute plain path" >&2; exit 2; }
case "$APP_DIR" in *..*|*/public_html|*/public_html/*) echo "invoke-remote: app dir may not traverse or be the served root" >&2; exit 2 ;; esac

# The key is private and dedicated; the host key is pinned, never learned on first use.
[ -f "$IDENTITY" ] && [ ! -L "$IDENTITY" ] || { echo "invoke-remote: identity file missing or a symlink" >&2; exit 2; }
mode="$(stat -c '%a' "$IDENTITY" 2>/dev/null || stat -f '%Lp' "$IDENTITY")"
[ "$mode" = "600" ] || [ "$mode" = "400" ] || { echo "invoke-remote: identity file must be mode 0600 or 0400" >&2; exit 2; }
[ -s "$KNOWN_HOSTS" ] && [ ! -L "$KNOWN_HOSTS" ] || { echo "invoke-remote: pinned known_hosts file missing, empty or a symlink" >&2; exit 2; }

exec ssh \
  -F /dev/null \
  -o BatchMode=yes \
  -o IdentitiesOnly=yes \
  -o IdentityAgent=none \
  -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile="$KNOWN_HOSTS" \
  -o GlobalKnownHostsFile=/dev/null \
  -o ForwardAgent=no \
  -o ForwardX11=no \
  -o ClearAllForwardings=yes \
  -o ConnectTimeout=20 \
  -o ServerAliveInterval=15 \
  -o ServerAliveCountMax=2 \
  -i "$IDENTITY" \
  -p "$PORT" \
  -l "$SSH_USER" \
  -- "$HOST" \
  "php $APP_DIR/worker/tick.php"
