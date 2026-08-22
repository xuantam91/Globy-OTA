<?php

function ota_auth_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");
    session_set_cookie_params([
        "httponly" => true,
        "secure" => $isHttps,
        "samesite" => "Lax"
    ]);
    session_start();
}

function ota_auth_users_path() {
    return __DIR__ . "/auth_users.json";
}

function ota_auth_load_users() {
    $path = ota_auth_users_path();
    if (!file_exists($path)) {
        return ["recovery_email" => "techgloby@gmail.com", "users" => []];
    }

    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        return ["recovery_email" => "techgloby@gmail.com", "users" => []];
    }

    if (!isset($data["users"]) || !is_array($data["users"])) {
        $data["users"] = [];
    }
    if (empty($data["recovery_email"])) {
        $data["recovery_email"] = "techgloby@gmail.com";
    }

    return $data;
}

function ota_auth_save_users($data) {
    file_put_contents(ota_auth_users_path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function ota_auth_normalize_username($username) {
    return strtolower(trim((string)$username));
}

function ota_auth_is_logged_in() {
    ota_auth_start_session();
    return !empty($_SESSION["ota_admin_user"]);
}

function ota_auth_current_user() {
    ota_auth_start_session();
    return $_SESSION["ota_admin_user"] ?? "";
}

function ota_auth_login($username, $password, &$error = null) {
    ota_auth_start_session();
    $usernameRaw = trim((string)$username);
    $key = ota_auth_normalize_username($usernameRaw);
    $data = ota_auth_load_users();
    $user = $data["users"][$key] ?? null;
    if (!is_array($user)) {
        $error = "Invalid username or password.";
        return false;
    }

    $hash = (string)($user["password_hash"] ?? "");
    if (!$hash || !password_verify((string)$password, $hash)) {
        $error = "Invalid username or password.";
        return false;
    }

    $_SESSION["ota_admin_user"] = (string)($user["username"] ?? $usernameRaw);
    $_SESSION["ota_admin_login_at"] = date("c");
    session_regenerate_id(true);
    return true;
}

function ota_auth_logout() {
    ota_auth_start_session();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
}

function ota_auth_safe_next($next) {
    $next = trim((string)$next);
    if ($next === "" || strpos($next, "://") !== false) return "admin.php";
    if ($next[0] === "/") return ltrim($next, "/");
    return $next;
}

function ota_auth_require_login() {
    if (ota_auth_is_logged_in()) {
        return;
    }
    $target = rawurlencode($_SERVER["REQUEST_URI"] ?? "admin.php");
    header("Location: login.php?next={$target}");
    exit;
}

function ota_auth_generate_temp_password() {
    return "GlobyTmp" . random_int(100000, 999999);
}

function ota_auth_send_reset_email($to, $username, $tempPassword) {
    $subject = "[Globy OTA] Password reset for {$username}";
    $body = "A password reset was requested for account {$username}.\n\n";
    $body .= "Temporary password: {$tempPassword}\n";
    $body .= "Time (server): " . date("Y-m-d H:i:s") . "\n";
    $body .= "IP: " . ($_SERVER["REMOTE_ADDR"] ?? "unknown") . "\n\n";
    $body .= "Please login and change your password immediately.\n";
    $mailHost = (string)($_SERVER["HTTP_HOST"] ?? ($_SERVER["SERVER_NAME"] ?? "localhost"));
    $mailHost = preg_replace('/:\d+$/', '', $mailHost);
    if (!$mailHost) $mailHost = "localhost";
    $headers = "From: no-reply@" . $mailHost . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    return @mail($to, $subject, $body, $headers);
}

function ota_auth_recover_password($username, &$message = null) {
    $usernameRaw = trim((string)$username);
    $key = ota_auth_normalize_username($usernameRaw);
    $data = ota_auth_load_users();
    $user = $data["users"][$key] ?? null;
    if (!is_array($user)) {
        $message = "If the account exists, a reset email has been sent.";
        return true;
    }

    $recoveryEmail = (string)($data["recovery_email"] ?? "techgloby@gmail.com");
    $tempPassword = ota_auth_generate_temp_password();
    if (!ota_auth_send_reset_email($recoveryEmail, (string)$user["username"], $tempPassword)) {
        $message = "Mail service is unavailable. Please contact techgloby@gmail.com directly.";
        return false;
    }

    $data["users"][$key]["password_hash"] = password_hash($tempPassword, PASSWORD_DEFAULT);
    $data["users"][$key]["updated_at"] = date("c");
    ota_auth_save_users($data);
    $message = "Password reset email has been sent to {$recoveryEmail}.";
    return true;
}
