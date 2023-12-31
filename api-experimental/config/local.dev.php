<?php
use Monolog\Logger;

// Dev environment

return function (array $settings): array {
    // Error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');

    $settings['error']['display_error_details'] = true;
    $settings['logger']['level'] = Logger::DEBUG;

    // Database
    $settings['db']['database'] = 'intraclub';
    $settings['db']['username'] = 'root';
    $settings['db']['password'] = 'root';

    return $settings;
};