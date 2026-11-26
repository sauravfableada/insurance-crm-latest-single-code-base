#!/bin/bash
# Install Node.js (via NVM) for THIS account only + npm deps for whatsapp-session-server.
# Does NOT require root / system packages / PM2.
set -e

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SERVER_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "==> Project: $PROJECT_ROOT"
echo "==> Bridge:  $SERVER_DIR"

# --- NVM + Node (user home only) ---
export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"

if [ ! -s "$NVM_DIR/nvm.sh" ]; then
  echo "==> Installing NVM into $NVM_DIR ..."
  curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
fi

# shellcheck disable=SC1090
. "$NVM_DIR/nvm.sh"

if ! command -v node >/dev/null 2>&1; then
  echo "==> Installing Node.js LTS (user-only via NVM) ..."
  nvm install --lts
  nvm alias default 'lts/*'
fi

nvm use default >/dev/null 2>&1 || nvm use --lts >/dev/null 2>&1 || true

NODE_BIN="$(command -v node)"
NPM_BIN="$(command -v npm)"
echo "==> node: $NODE_BIN ($(node -v))"
echo "==> npm:  $NPM_BIN ($(npm -v))"

# Persist PATH hint for cron/non-login shells (keep-alive.sh already scans ~/.nvm)
if ! grep -q 'NVM_DIR' "$HOME/.bashrc" 2>/dev/null; then
  {
    echo ''
    echo '# NVM (poojasaman-erp WhatsApp bridge)'
    echo 'export NVM_DIR="$HOME/.nvm"'
    echo '[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"'
  } >> "$HOME/.bashrc"
fi

# --- Project deps only ---
cd "$SERVER_DIR"
echo "==> npm install in whatsapp-session-server ..."
npm install
chmod +x "$SERVER_DIR/keep-alive.sh" "$SERVER_DIR/install-node-project.sh" 2>/dev/null || true

# Clear stale socket and start bridge
rm -f /tmp/wa_session.sock
echo "==> Starting server.js in background ..."
nohup "$NODE_BIN" server.js >> "$SERVER_DIR/server.log" 2>&1 &
sleep 2

if curl -s --max-time 3 http://127.0.0.1:3001/health 2>/dev/null | grep -q '"status":"ok"'; then
  echo "==> OK: bridge is ONLINE on http://127.0.0.1:3001"
elif [ -S /tmp/wa_session.sock ] && curl -s --max-time 3 --unix-socket /tmp/wa_session.sock http://localhost/health 2>/dev/null | grep -q '"status":"ok"'; then
  echo "==> OK: bridge is ONLINE on unix:/tmp/wa_session.sock"
else
  echo "==> WARN: bridge not responding yet. Check: tail -n 40 $SERVER_DIR/server.log"
fi

echo ""
echo "Done. Only ONE cron is needed (bridge keep-alive runs inside schedule:run):"
echo "* * * * * cd $PROJECT_ROOT && php artisan schedule:run >> /dev/null 2>&1"
echo ""
echo "Then verify: cd $PROJECT_ROOT && php artisan whatsapp:check-server"
