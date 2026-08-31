#!/usr/bin/env bash
# Controleert dat de app na de deploy echt antwoordt met geldige JSON.
set -euo pipefail

code=$(curl -sS --max-time 60 -o /tmp/health.json -w '%{http_code}' "${URL%/}/api/rankings")

echo "HTTP ${code} — /api/rankings"
head -c 400 /tmp/health.json
echo

if [ "$code" != "200" ]; then
  echo "::error::De app antwoordt met HTTP ${code} op /api/rankings."
  exit 1
fi

if ! jq -e . /tmp/health.json >/dev/null; then
  echo "::error::Het antwoord op /api/rankings is geen geldige JSON."
  exit 1
fi
