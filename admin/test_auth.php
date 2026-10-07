<?php

require_once dirname(__DIR__) . "/includes/auth.php";

echo "auth.php loaded successfully.<br>";

if (function_exists("require_login")) {
    echo "require_login() EXISTS.<br>";
} else {
    echo "require_login() DOES NOT EXIST.<br>";
}

if (function_exists("require_role")) {
    echo "require_role() EXISTS.<br>";
} else {
    echo "require_role() DOES NOT EXIST.<br>";
}