#!/usr/bin/env bash
# Geeft de exclude-globs terug, één per regel: commentaar en lege regels eruit.
set -euo pipefail

bestand="$(dirname "$0")/../ftp-exclude.txt"

# Vangnet vóór alles: zonder deze regel overschrijft een sync de productie-.env
# met de dev-.env. Liever hier falen dan op de server.
for verplicht in '**/.env' 'storage/app/private/**'; do
  if ! grep -qFx "$verplicht" "$bestand"; then
    echo "FOUT: '$verplicht' staat niet in ftp-exclude.txt — sync geweigerd." >&2
    exit 1
  fi
done

grep -v -E '^[[:space:]]*(#|$)' "$bestand"
