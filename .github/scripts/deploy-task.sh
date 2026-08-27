#!/usr/bin/env bash
# Roept één artisan-taak aan op de host. Vereist URL en TOKEN in de omgeving.
set -euo pipefail

taak="${1:?gebruik: deploy-task.sh <migrate|optimize|clear|reset>}"

code=$(curl -sS --max-time 600 \
  -o /tmp/deploy-body.txt -w '%{http_code}' \
  -X POST "${URL%/}/api/deploy/${taak}" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H 'Accept: application/json')

echo "HTTP ${code} — ${taak}"
cat /tmp/deploy-body.txt
echo

if [ "$code" != "200" ]; then
  if [ "$code" = "404" ]; then
    echo "::error::404. Ofwel staat DEPLOY_TOKEN niet (of anders) in de .env op de server, ofwel is INTRACLUB_ALLOW_RESET uit. Let op: na 'optimize' zit de configuratie in de cache — een .env-wijziging vraagt een nieuwe optimize."
  fi
  exit 1
fi
