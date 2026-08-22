<?php
require_once __DIR__ . "/../auth.php";
ota_auth_start_session();

if (!ota_auth_is_logged_in()) {
    header("Location: ../login.php?next=" . rawurlencode("admin.php"));
    exit;
}

header("Location: ../admin.php");
exit;
