#!/bin/sh
set -eu

cd /var/www/html

# php artisan serve (PHP built-in server) geeft Compose-variabelen niet door
# aan request-workers. Zonder deze prepend wint app/.env (DB_HOST=127.0.0.1).
php -r '
$keys = [
    "APP_URL",
    "DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD",
    "LEGACY_DB_HOST", "LEGACY_DB_DATABASE", "LEGACY_DB_USERNAME", "LEGACY_DB_PASSWORD",
    "ARCHIVE_DB_HOST", "ARCHIVE_DB_DATABASE", "ARCHIVE_DB_USERNAME", "ARCHIVE_DB_PASSWORD",
    "SANCTUM_STATEFUL_DOMAINS",
];
$lines = ["<?php", "\$vars = ["];
foreach ($keys as $key) {
    $value = getenv($key);
    if ($value === false) {
        continue;
    }
    $lines[] = "    " . var_export($key, true) . " => " . var_export($value, true) . ",";
}
$lines[] = "];";
$lines[] = "foreach (\$vars as \$name => \$value) {";
$lines[] = "    putenv(\"{\$name}={\$value}\");";
$lines[] = "    \$_ENV[\$name] = \$value;";
$lines[] = "    \$_SERVER[\$name] = \$value;";
$lines[] = "}";
file_put_contents("/usr/local/etc/php/docker-env.php", implode("\n", $lines) . "\n");
'

if [ ! -f .env ]; then
    cp .env.example .env
fi

composer install --no-interaction --prefer-dist --no-progress

app_key=$(grep -E '^APP_KEY=' .env | cut -d= -f2- || true)
if [ -z "$app_key" ]; then
    php artisan key:generate --force --ansi
fi

php artisan config:clear --ansi >/dev/null
php artisan migrate --force --ansi
php artisan db:seed --force --ansi
if [ ! -L public/storage ]; then
    php artisan storage:link --ansi
fi

exec php artisan serve --host=0.0.0.0 --port=8000
