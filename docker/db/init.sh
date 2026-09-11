#!/bin/bash
set -eu

# Extra databanken voor de importketen (PLAN.md, fase 6). MYSQL_DATABASE
# maakt al `intraclub` aan; dit script draait alleen bij een lege volume.
mariadb -u root <<-EOSQL
    CREATE DATABASE IF NOT EXISTS intraclub_legacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE DATABASE IF NOT EXISTS intraclub_oud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EOSQL
