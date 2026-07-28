<?php
require_once __DIR__ . '/lib/auth.php';
boot();
$u = current_user();
header('Location: ' . ($u ? home_for($u) : 'login.php'));
