#!/bin/bash
# Shared-host keep-alive for WhatsApp Baileys bridge (no PM2 required).
# Cron example (every 2 minutes):
#   */2 * * * * /bin/bash /path/to/poojasaman-erp/whatsapp-session-server/keep-alive.sh
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR" || exit 1

# Load NVM if present (cron shells often have empty PATH)
export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
if [ -s "$NVM_DIR/nvm.sh" ]; then
    # shellcheck disable=SC1090
    . "$NVM_DIR/nvm.sh" >/dev/null 2>&1 || true
fi

# Auto-detect node binary in PATH, NVM, and common locations
NODE_BIN="$(command -v node 2>/dev/null || true)"
if [ -z "$NODE_BIN" ] || [ ! -x "$NODE_BIN" ]; then
    # Prefer highest version under ~/.nvm
    for cand in $(ls -1d "$HOME"/.nvm/versions/node/*/bin/node 2>/dev/null | sort -V -r); do
        if [ -x "$cand" ]; then
            NODE_BIN="$cand"
            export PATH="$(dirname "$cand"):$PATH"
            break
        fi
    done
fi
if [ -z "$NODE_BIN" ] || [ ! -x "$NODE_BIN" ]; then
    for cand in /usr/local/bin/node /usr/bin/node /opt/alt/alt-nodejs*/root/usr/bin/node; do
        if [ -x "$cand" ]; then
            NODE_BIN="$cand"
            export PATH="$(dirname "$cand"):$PATH"
            break
        fi
    done
fi
NODE_BIN="${NODE_BIN:-node}"

SOCKET="/tmp/wa_session.sock"
HEALTHY=0

# Prefer TCP localhost health (works when Node is listening on 3001)
if curl -s --max-time 2 http://127.0.0.1:3001/health 2>/dev/null | grep -q '"status":"ok"'; then
    HEALTHY=1
# Fallback: UNIX socket (common on Hostinger / shared hosts when TCP is flaky)
elif [ -S "$SOCKET" ] && curl -s --max-time 2 --unix-socket "$SOCKET" http://localhost/health 2>/dev/null | grep -q '"status":"ok"'; then
    HEALTHY=1
fi

if [ "$HEALTHY" -eq 0 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WhatsApp session server not running. Auto-starting with $NODE_BIN..." >> "$DIR/server.log"
    # Clear stale socket / orphaned process from this bridge only
    pkill -9 -f "whatsapp-session-server/server.js" 2>/dev/null || true
    sleep 0.5
    rm -f "$SOCKET"
    nohup "$NODE_BIN" server.js >> "$DIR/server.log" 2>&1 &
fi
