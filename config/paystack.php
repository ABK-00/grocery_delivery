<?php

require_once __DIR__ . '/app.php';

$secretKey = trim((string) getenv('PAYSTACK_SECRET_KEY'));

$localConfigFile = __DIR__ . '/paystack.local.php';

if ($secretKey === '' && is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;

    if (is_array($localConfig)) {
        $secretKey = trim((string) ($localConfig['secret_key'] ?? ''));
    }
}

if ($secretKey === '') {
    throw new RuntimeException(
        'Paystack is not configured. Set PAYSTACK_SECRET_KEY or create config/paystack.local.php.'
    );
}

define('PAYSTACK_SECRET_KEY', $secretKey);

define(
    'PAYSTACK_BASE_URL',
    'https://api.paystack.co'
);

/*
 * Keep this aligned with the Paystack integration Payment Session Timeout.
 * 900 seconds = 15 minutes.
 */
define('PAYMENT_SESSION_TIMEOUT_SECONDS', 900);

$httpsEnabled =
    !empty($_SERVER['HTTPS'])
    && strtolower((string) $_SERVER['HTTPS']) !== 'off';

$scheme = $httpsEnabled ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

define(
    'PAYSTACK_CALLBACK_URL',
    $scheme
    . '://'
    . $host
    . BASE_URL
    . '/payment_callback.php'
);
