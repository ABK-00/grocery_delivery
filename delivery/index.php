<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('delivery_partner');
requireCompanyAccess();

header(
    'Location: '
    . BASE_URL
    . '/delivery/dashboard.php'
);
exit;
