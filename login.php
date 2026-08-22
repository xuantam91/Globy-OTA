<?php
require_once __DIR__ . "/auth.php";
ota_auth_start_session();

$next = ota_auth_safe_next($_GET["next"] ?? "admin.php");
$error = "";
$notice = "";

if (ota_auth_is_logged_in()) {
    header("Location: " . $next);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "login";
    $next = ota_auth_safe_next($_POST["next"] ?? "admin.php");

    if ($action === "recover") {
        $username = trim((string)($_POST["username"] ?? ""));
        ota_auth_recover_password($username, $notice);
    } else {
        $username = trim((string)($_POST["username"] ?? ""));
        $password = (string)($_POST["password"] ?? "");
        if (!ota_auth_login($username, $password, $error)) {
            if (!$error) $error = "Login failed.";
        } else {
            header("Location: " . $next);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Globy OTA Login</title>
<style>
:root {
    --bg1: #f8f3e9;
    --bg2: #eee4d2;
    --panel: #fffdf7;
    --line: #dbc9ae;
    --text: #31261a;
    --muted: #776552;
    --accent: #b75e3c;
    --danger: #b13a31;
    --ok: #2e7d4a;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    min-height: 100vh;
    font-family: "Segoe UI", Arial, sans-serif;
    color: var(--text);
    background:
        radial-gradient(circle at 10% 0%, rgba(183, 94, 60, 0.16), transparent 35%),
        linear-gradient(160deg, var(--bg1), var(--bg2));
    display: grid;
    place-items: center;
    padding: 16px;
}
.card {
    width: min(460px, 96vw);
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 20px;
    box-shadow: 0 20px 45px rgba(78, 53, 33, 0.15);
    padding: 24px;
}
.title {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
}
.logo {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, #ffb56a, #e36a3a);
    color: #fff;
    display: grid;
    place-items: center;
    font-size: 20px;
    font-weight: 700;
}
h1 {
    margin: 0;
    font-size: 28px;
    letter-spacing: 0.02em;
}
p {
    margin: 0 0 16px;
    color: var(--muted);
}
.field { margin-bottom: 12px; }
label {
    display: block;
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 6px;
    font-weight: 600;
}
input {
    width: 100%;
    height: 42px;
    border: 1px solid var(--line);
    border-radius: 11px;
    padding: 0 12px;
    font-size: 15px;
    color: var(--text);
}
button {
    width: 100%;
    height: 42px;
    border-radius: 999px;
    border: 1px solid var(--accent);
    background: var(--accent);
    color: #fff;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
}
.sub-btn {
    margin-top: 8px;
    background: transparent;
    color: var(--accent);
}
.error, .notice {
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 12px;
    font-size: 14px;
}
.error {
    background: rgba(177, 58, 49, 0.1);
    color: var(--danger);
}
.notice {
    background: rgba(46, 125, 74, 0.12);
    color: var(--ok);
}
.foot {
    margin-top: 14px;
    font-size: 12px;
    color: var(--muted);
    text-align: center;
}
</style>
</head>
<body>
    <div class="card">
        <div class="title">
            <div class="logo">&#10227;</div>
            <h1>GLOBY OTA</h1>
        </div>
        <p>Admin login required</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($notice): ?>
            <div class="notice"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
            <div class="field">
                <label>Username</label>
                <input type="text" name="username" required>
            </div>
            <div class="field">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit">Login</button>
        </form>

        <form method="POST">
            <input type="hidden" name="action" value="recover">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
            <div class="field" style="margin-top:12px;">
                <label>Forgot password (enter username)</label>
                <input type="text" name="username" required>
            </div>
            <button type="submit" class="sub-btn">Send reset to techgloby@gmail.com</button>
        </form>

        <div class="foot">Accounts: GlobyA1, GlobyA2</div>
    </div>
</body>
</html>
