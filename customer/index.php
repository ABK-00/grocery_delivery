<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('customer');

header(
    'Location: '
    . BASE_URL
    . '/customer/dashboard.php'
);
exit;
