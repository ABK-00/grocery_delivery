<?php
require_once __DIR__ . "/../includes/auth.php";
requireRole("admin");
requireCompanyAccess();
$companyId = currentCompanyId();

header("Location: dashboard.php");
exit;