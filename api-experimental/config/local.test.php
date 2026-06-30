<?php

declare(strict_types=1);

// Test environment (APP_ENV=test). Database credentials come from environment
// variables so the same config works locally and in CI.

return function (array $settings): array {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    $settings['error']['display_error_details'] = true;

    $settings['db']['host'] = getenv('DB_HOST') ?: '127.0.0.1';
    $settings['db']['port'] = getenv('DB_PORT') ?: '3306';
    $settings['db']['database'] = getenv('DB_DATABASE') ?: 'intraclub_test';
    $settings['db']['username'] = getenv('DB_USERNAME') ?: 'root';
    $settings['db']['password'] = getenv('DB_PASSWORD') ?: '';

    // Fixed signing secret for the test suite.
    $settings['jwt']['secret'] = getenv('JWT_SECRET') ?: 'test-secret-key-which-is-long-enough-to-be-safe';

    // A known allowed origin so CORS behaviour can be asserted.
    $settings['cors']['allowedOrigins'] = ['https://app.test'];

    return $settings;
};
