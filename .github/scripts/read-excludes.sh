#!/usr/bin/env bash
# Leest .github/ftp-exclude.txt en schrijft het als GitHub-output 'list'.
# Dezelfde lijst wordt door local-sync.sh gebruikt, zodat een lokale sync nooit
# meer uploadt dan de workflow zou doen.
set -euo pipefail

lijst=$(bash "$(dirname "$0")/exclude-list.sh")

echo 'list<<INTRACLUB_EOF'
echo "$lijst"
echo 'INTRACLUB_EOF'
