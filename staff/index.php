<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('staff');
requireCompanyAccess();

header(
    'Location: '
    . BASE_URL
    . '/staff/dashboard.php'
);
exit;
