<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');
requireCompanyAccess();

header(
    'Location: '
    . BASE_URL
    . '/admin/dashboard.php'
);
exit;
