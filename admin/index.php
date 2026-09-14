<?php
require_once __DIR__ . "/../includes/auth.php";
requireRole("admin");

header("Location: dashboard.php");
exit;