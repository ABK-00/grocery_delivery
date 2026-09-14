<?php

require_once __DIR__ . "/config/db.php";

try {
    $result = $conn->query("SELECT 1");
    
    if ($result) {
        echo "Database connection successful! 🎉";
    }
} catch (PDOException $e) {
    echo "Database test failed: " . $e->getMessage();
}