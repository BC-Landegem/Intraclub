#!/usr/bin/env bash
#
# Eerste sync vanaf je laptop, met exact dezelfde tool en exclude-lijst als de
# workflow. Zo laat hij hetzelfde .ftp-deploy-sync-state.json achter en doet de
# GitHub-workflow daarna een echte delta-sync in plaats van 16.900 bestanden.
#
# Bouwt in .deploy-staging/ en raakt je eigen app/vendor NOOIT aan. Dat is geen
# luxe: `composer install --no-dev` en daarna terugschakelen laat op Windows een
# half verwijderde vendor en een ontregelde classmap achter.
#
# Gebruik:
#   bash .github/scripts/local-sync.sh          # proefdraaien, wijzigt niets
#   bash .github/scripts/local-sync.sh --go     # echt uploaden
#
# Verwacht FTP_SERVER, FTP_USERNAME, FTP_PASSWORD en FTP_SERVER_DIR in de
# omgeving of in .deploy.local (ge-gitignored, zie .deploy.local.example).
#
set -euo pipefail

wortel="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$wortel"

staging="$wortel/.deploy-staging"

echt=false
[ "${1:-}" = "--go" ] && echt=true

# shellcheck disable=SC1091
[ -f .deploy.local ] && . ./.deploy.local

: "${FTP_SERVER:?zet FTP_SERVER}"
: "${FTP_USERNAME:?zet FTP_USERNAME}"
: "${FTP_PASSWORD:?zet FTP_PASSWORD}"
: "${FTP_SERVER_DIR:?zet FTP_SERVER_DIR (met slash op het einde)}"

case "$FTP_SERVER_DIR" in
  */) ;;
  *) echo "FTP_SERVER_DIR moet op / eindigen." >&2; exit 1 ;;
esac

echo "==> Staging opzetten in .deploy-staging"
rm -rf "$staging"
mkdir -p "$staging"
# Broncode van de app, zonder vendor/build-artefacten en zonder jouw .env.
tar -c -C app \
  --exclude=./vendor \
  --exclude=./node_modules \
  --exclude=./.env \
  --exclude=./public/zaal \
  --exclude='./storage/logs/*.log' \
  . | tar -x -C "$staging"

# storage/ en bootstrap/cache/ moeten er staan (Laravel wil de mappen) maar leeg
# zijn, precies zoals een verse checkout in CI. Enkel de .gitignore-stubs blijven.
find "$staging/storage" "$staging/bootstrap/cache" -type f ! -name '.gitignore' -delete

echo "==> Productie-afhankelijkheden in staging (zonder dev)"
cp "$staging/.env.example" "$staging/.env"
( cd "$staging" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress )
( cd "$staging" && php artisan key:generate --no-interaction )

echo "==> Filament-assets"
( cd "$staging" && php artisan filament:assets )

# CI gebruikt `npm ci`, maar die wist node_modules volledig — op Windows loopt dat
# stuk zodra een draaiende `ng serve` esbuild.exe vasthoudt (EPERM/unlink).
echo "==> Zaal-app bouwen"
if [ -d zaal/node_modules ]; then
  ( cd zaal && npm install --no-audit --no-fund )
else
  ( cd zaal && npm ci )
fi
( cd zaal && npm run build )

test -f app/public/zaal/index.html || { echo "app/public/zaal/index.html ontbreekt." >&2; exit 1; }
mkdir -p "$staging/public"
cp -r app/public/zaal "$staging/public/"

# De .env uit staging mag nooit mee: hij bevat de sleutel uit .env.example en zou
# de serverconfiguratie overschrijven. Staat ook in ftp-exclude.txt; dubbel is hier
# goedkoper dan één keer fout.
rm -f "$staging/.env"

echo "==> Buildcontrole"
if grep -qE 'phpunit|fakerphp|sebastian' "$staging/vendor/composer/autoload_static.php"; then
  echo "FOUT: de autoloader in staging verwijst naar dev-packages. Sync geweigerd." >&2
  exit 1
fi
test -f "$staging/public/zaal/index.html" || { echo "zaal-app ontbreekt in staging." >&2; exit 1; }
test -f "$staging/public/index.php" || { echo "public/index.php ontbreekt in staging." >&2; exit 1; }
test ! -f "$staging/.env" || { echo "FOUT: er staat nog een .env in staging." >&2; exit 1; }
echo "    ok — $(find "$staging" -type f | wc -l) bestanden klaar"

# Zelfde lijst als de workflow; weigert als **/.env eruit verdwenen is.
mapfile -t excludes < <(bash .github/scripts/exclude-list.sh)

args=(
  --server "$FTP_SERVER"
  --username "$FTP_USERNAME"
  --password "$FTP_PASSWORD"
  --port "${FTP_PORT:-21}"
  --local-dir "$staging/"
  --server-dir "$FTP_SERVER_DIR"
  --log-level standard
)
for glob in "${excludes[@]}"; do
  args+=(--exclude "$glob")
done

if [ "$echt" = false ]; then
  echo
  echo "==> PROEFDRAAI — er wordt niets gewijzigd. Voeg --go toe om echt te uploaden."
  args+=(--dry-run)
else
  echo
  echo "==> ECHTE SYNC naar ${FTP_SERVER}:${FTP_SERVER_DIR}"
fi

# Zelfde engine als de action (die gebruikt @samkirkland/ftp-deploy ^1.2.4),
# dus het state-bestand is uitwisselbaar.
npx --yes @samkirkland/ftp-deploy@1.2.5 "${args[@]}"

echo
echo "==> Klaar. Draai daarna eenmalig de server-taken:"
echo "    URL=<https://...> TOKEN=<DEPLOY_TOKEN> bash .github/scripts/deploy-task.sh migrate"
echo "    URL=<https://...> TOKEN=<DEPLOY_TOKEN> bash .github/scripts/deploy-task.sh optimize"
