#!/usr/bin/env bash
# Túnel público estable hacia nginx local via ngrok.
# Uso:
#   ./scripts/dev-tunnel.sh
# Con dominio reservado (recomendado si pagás ngrok):
#   NGROK_DOMAIN=tu-subdominio.ngrok-free.app ./scripts/dev-tunnel.sh
# o seteá NGROK_DOMAIN en .env

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PORT="${TUNNEL_PORT:-}"
DOMAIN="${NGROK_DOMAIN:-}"

if [[ -f .env ]]; then
  if [[ -z "$PORT" ]]; then
    PORT="$(grep -E '^APP_PORT=' .env | head -1 | cut -d= -f2- | tr -d '\r' || true)"
  fi
  if [[ -z "$DOMAIN" ]]; then
    DOMAIN="$(grep -E '^NGROK_DOMAIN=' .env | head -1 | cut -d= -f2- | tr -d '\r' || true)"
  fi
fi

PORT="${PORT:-8082}"

# Detener quick tunnels de Cloudflare (inestables) si siguen vivos.
pkill -f 'cloudflared tunnel --url' 2>/dev/null || true

if [[ -n "${DOMAIN}" ]]; then
  echo "Iniciando ngrok estable → https://${DOMAIN} → localhost:${PORT}"
  exec ngrok http "${PORT}" --domain="${DOMAIN}" --log=stdout
fi

echo "WARN: NGROK_DOMAIN vacío — ngrok asignará un host efímero."
echo "Reservá un dominio en https://dashboard.ngrok.com/domains y seteá NGROK_DOMAIN en .env"
exec ngrok http "${PORT}" --log=stdout
