<?php
declare(strict_types=1);

// Copy this to .hostboy-private/config.php. It is included by index.php only.
if (!defined('HOSTBOY_CHECKER_BOOTSTRAP')) {
    http_response_code(404);
    exit;
}

return [
    'mode' => 'form', // 'form' or 'auto' (run on page load)
    'cooldownSeconds' => 60, // shared across all visitors to this installation
    'reportUrl' => 'https://thehostboy.com/api/v1/reports',
    'checkerKey' => 'SET_THIS_TO_YOUR_INSTALLATION_KEY',
    'provider' => 'InfinityFree',
    'plan' => 'Free Hosting',
    'region' => 'Not disclosed',
    'configuration' => 'InfinityFree free account; PHP web runtime; manually triggered',
    'brand' => 'TheHostBoy',
];
