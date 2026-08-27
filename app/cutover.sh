#!/usr/bin/env bash
set -euo pipefail

DUMP="mysqldump -u root --single-transaction --no-tablespaces --default-character-set=utf8mb4"

DATA="players seasons rounds games player_round_statistics player_season_statistics
      archive_players archive_seasons archive_rounds archive_games
      archive_player_season_statistics archive_player_round_statistics"

# cutover: structuur van alle tabellen, daarna enkel de data die telt
$DUMP --no-data --add-drop-table intraclub > cutover.sql
$DUMP --no-create-info --skip-add-locks --complete-insert \
  intraclub $DATA migrations users >> cutover.sql

gzip -9 -f cutover.sql
