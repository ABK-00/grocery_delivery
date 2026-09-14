<?php
function e($v){ return htmlspecialchars((string)$v,ENT_QUOTES,"UTF-8"); }
function redirect($url){ header("Location: $url"); exit; }
