<?php
require_once __DIR__ . "/auth.php";
ota_auth_start_session();

if (isset($_GET["logout"])) {
    ota_auth_logout();
    header("Location: login.php");
    exit;
}

ota_auth_require_login();

header("Content-Type: text/html; charset=UTF-8");
ini_set('default_charset', 'UTF-8');

// ================================
// PATHS
// ================================
$configPath         = __DIR__ . "/ota_config.json";
$historyPath        = __DIR__ . "/version_history.json";
$deviceOverridePath = __DIR__ . "/device_overrides.json";
$fwBasePath         = __DIR__ . "/ota_fw/";
$assetConfigPath    = __DIR__ . "/ota_assets_config.json";
$assetOverridePath  = __DIR__ . "/asset_overrides.json";
$assetBasePath      = __DIR__ . "/ota_assets/";

// ================================
// LOAD JSON
// ================================
$config          = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
$history         = file_exists($historyPath) ? json_decode(file_get_contents($historyPath), true) : [];
$deviceOverrides = file_exists($deviceOverridePath) ? json_decode(file_get_contents($deviceOverridePath), true) : [];
$assetConfig     = file_exists($assetConfigPath) ? json_decode(file_get_contents($assetConfigPath), true) : [];
$assetOverrides  = file_exists($assetOverridePath) ? json_decode(file_get_contents($assetOverridePath), true) : [];

$boardsFile      = __DIR__ . "/boards.json";
$boardsMeta      = file_exists($boardsFile) ? json_decode(file_get_contents($boardsFile), true) : [];
if (!is_array($boardsMeta)) $boardsMeta = [];

// Backwards compatibility mapping for dropdown selectors
$models = [];
foreach ($boardsMeta as $bk => $meta) {
    if (is_array($meta) && isset($meta["model"])) {
        $models[$meta["model"]] = $bk;
    } else {
        $models[$meta] = $bk;
    }
}

$statsPath       = __DIR__ . "/device_stats.json";
$deviceStats     = file_exists($statsPath) ? json_decode(file_get_contents($statsPath), true) : [];

if (!is_array($config)) $config = [];
if (!is_array($history)) $history = [];
if (!is_array($deviceOverrides)) $deviceOverrides = [];
if (!is_array($assetConfig)) $assetConfig = [];
if (!is_array($assetOverrides)) $assetOverrides = [];
if (!is_array($deviceStats)) $deviceStats = [];

// ================================
// EXTRACT VERSION FROM BIN
// ================================
function extractVersionFromBin($file) {
    $data = file_get_contents($file);
    preg_match('/\d+\.\d+\.\d+(?:\.\d+)?(?:-[A-Za-z0-9]+)?/', $data, $m);
    return $m[0] ?? "0.0.0";
}

function normalizeIdentifier($value) {
    return strtolower(trim((string)$value));
}

function buildOverrideKey($type, $identifier, $board) {
    return strtolower(trim((string)$type)) . ":" . normalizeIdentifier($identifier) . ":" . strtolower(trim((string)$board));
}

function ensureDirectory($path) {
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

function buildOverrideFirmwarePath($fwBasePath, $board, $key) {
    $hash = substr(sha1($key), 0, 16);
    $relativeDir = "device_overrides/" . strtolower(trim((string)$board)) . "/" . $hash . "/";
    return [
        "dir" => $fwBasePath . $relativeDir,
        "file" => $relativeDir . "xiaozhi.bin"
    ];
}

function buildOverrideAssetPath($assetBasePath, $board, $key) {
    $hash = substr(sha1($key), 0, 16);
    $relativeDir = "device_overrides/" . strtolower(trim((string)$board)) . "/" . $hash . "/";
    return [
        "dir" => $assetBasePath . $relativeDir,
        "file" => $relativeDir . "assets.bin"
    ];
}

function readAssetPackageChecksum($file) {
    if (!file_exists($file)) return "";
    $handle = fopen($file, "rb");
    if (!$handle) return "";
    $header = fread($handle, 8);
    fclose($handle);
    if ($header === false || strlen($header) < 8) return "";
    $parts = unpack("Vchecksum", substr($header, 4, 4));
    return isset($parts["checksum"]) ? sprintf("%08x", (int)$parts["checksum"]) : "";
}

// ================================
// TOGGLE ENABLE / DISABLE
// ================================
if (isset($_GET["toggle"])) {
    $b = $_GET["toggle"];
    if (isset($config[$b])) {
        $config[$b]["enable"] = $config[$b]["enable"] ? 0 : 1;
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
    }
    header("Location: admin.php");
    exit;
}

if (isset($_GET["toggle_asset"])) {
    $b = (string)$_GET["toggle_asset"];
    if (isset($assetConfig[$b]) && is_array($assetConfig[$b])) {
        $assetConfig[$b]["enable"] = !empty($assetConfig[$b]["enable"]) ? 0 : 1;
        file_put_contents($assetConfigPath, json_encode($assetConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    header("Location: admin.php");
    exit;
}

if (isset($_GET["remove_override"])) {
    $key = (string)$_GET["remove_override"];
    if (isset($deviceOverrides[$key])) {
        unset($deviceOverrides[$key]);
        file_put_contents($deviceOverridePath, json_encode($deviceOverrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    header("Location: admin.php");
    exit;
}

if (isset($_GET["remove_asset_override"])) {
    $key = (string)$_GET["remove_asset_override"];
    if (isset($assetOverrides[$key])) {
        unset($assetOverrides[$key]);
        file_put_contents($assetOverridePath, json_encode($assetOverrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    header("Location: admin.php");
    exit;
}

if (isset($_GET["toggle_override"])) {
    $key = (string)$_GET["toggle_override"];
    if (isset($deviceOverrides[$key]) && is_array($deviceOverrides[$key])) {
        $isEnabled = (int)($deviceOverrides[$key]["enable"] ?? 0) === 1;
        if ($isEnabled) {
            $deviceOverrides[$key]["enable"] = 0;
            $deviceOverrides[$key]["status"] = "disabled";
            $deviceOverrides[$key]["disabled_at"] = date("Y-m-d H:i:s");
        } else {
            $deviceOverrides[$key]["enable"] = 1;
            $deviceOverrides[$key]["status"] = "active";
            $deviceOverrides[$key]["enabled_at"] = date("Y-m-d H:i:s");
            $deviceOverrides[$key]["issued_at"] = "";
            $deviceOverrides[$key]["updated_at"] = "";
        }
        file_put_contents($deviceOverridePath, json_encode($deviceOverrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    header("Location: admin.php");
    exit;
}

if (isset($_GET["toggle_asset_override"])) {
    $key = (string)$_GET["toggle_asset_override"];
    if (isset($assetOverrides[$key]) && is_array($assetOverrides[$key])) {
        $isEnabled = (int)($assetOverrides[$key]["enable"] ?? 0) === 1;
        if ($isEnabled) {
            $assetOverrides[$key]["enable"] = 0;
            $assetOverrides[$key]["status"] = "disabled";
            $assetOverrides[$key]["disabled_at"] = date("Y-m-d H:i:s");
        } else {
            $assetOverrides[$key]["enable"] = 1;
            $assetOverrides[$key]["status"] = "active";
            $assetOverrides[$key]["enabled_at"] = date("Y-m-d H:i:s");
            $assetOverrides[$key]["issued_at"] = "";
            $assetOverrides[$key]["updated_at"] = "";
        }
        file_put_contents($assetOverridePath, json_encode($assetOverrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    header("Location: admin.php");
    exit;
}

if (isset($_GET["delete_board"])) {
    $boardToDelete = $_GET["delete_board"];
    if (isset($boardsMeta[$boardToDelete])) {
        unset($boardsMeta[$boardToDelete]);
        file_put_contents($boardsFile, json_encode($boardsMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        if (isset($config[$boardToDelete])) {
            unset($config[$boardToDelete]);
            file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        if (isset($assetConfig[$boardToDelete])) {
            unset($assetConfig[$boardToDelete]);
            file_put_contents($assetConfigPath, json_encode($assetConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
    header("Location: admin.php");
    exit;
}

// ================================
// HANDLE UPLOAD
// ================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "upload_firmware";

    if ($action === "save_board") {
        $newModel    = trim((string)($_POST["new_model_name"] ?? ""));
        $newBoard    = strtolower(trim((string)($_POST["new_board_key"] ?? "")));
        $oldBoard    = strtolower(trim((string)($_POST["old_board"] ?? "")));
        $routeRaw    = strtolower(trim((string)($_POST["route_raw_board"] ?? "")));
        $routeSuffix = trim((string)($_POST["route_suffix"] ?? ""));

        if (!$newModel || !$newBoard) {
            die("Invalid board details");
        }

        if ($oldBoard !== "" && $oldBoard !== $newBoard) {
            if (isset($boardsMeta[$oldBoard])) {
                unset($boardsMeta[$oldBoard]);
            }

            if (isset($config[$oldBoard])) {
                $config[$newBoard] = $config[$oldBoard];
                $config[$newBoard]["model"] = $newModel;
                unset($config[$oldBoard]);
                file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            if (isset($assetConfig[$oldBoard])) {
                $assetConfig[$newBoard] = $assetConfig[$oldBoard];
                $assetConfig[$newBoard]["model"] = $newModel;
                $assetConfig[$newBoard]["board"] = $newBoard;
                unset($assetConfig[$oldBoard]);
                file_put_contents($assetConfigPath, json_encode($assetConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        $entry = ["model" => $newModel];
        if ($routeRaw !== "") {
            $entry["route_raw_board"] = $routeRaw;
        }
        if ($routeSuffix !== "") {
            $entry["route_suffix"] = $routeSuffix;
        }

        $boardsMeta[$newBoard] = $entry;

        file_put_contents($boardsFile, json_encode($boardsMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "<script>alert('Board configuration saved'); location.href='admin.php';</script>";
        exit;
    }

    if ($action === "upload_assets") {
        $model = trim((string)($_POST["asset_model"] ?? ""));
        $board = trim((string)($_POST["asset_board"] ?? ""));
        $version = trim((string)($_POST["asset_version"] ?? ""));
        $notes = trim((string)($_POST["asset_notes"] ?? ""));

        if (!$model || !$board || !$version || !isset($_FILES["asset_file"])) {
            die("Invalid asset upload");
        }

        $assetDir = $assetBasePath . $board . "/";
        ensureDirectory($assetDir);
        $path = $assetDir . "assets.bin";

        if (!move_uploaded_file($_FILES["asset_file"]["tmp_name"], $path)) {
            die("Failed to save asset file");
        }

        $size = filesize($path);
        $date = date("Y-m-d H:i:s");
        $checksum = readAssetPackageChecksum($path);

        $assetConfig[$board] = [
            "model" => $model,
            "board" => $board,
            "version" => $version,
            "checksum" => $checksum,
            "file" => $board . "/assets.bin",
            "size" => $size,
            "date" => $date,
            "notes" => $notes,
            "enable" => 1
        ];

        file_put_contents($assetConfigPath, json_encode($assetConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "<script>alert('Assets upload successful'); location.href='admin.php';</script>";
        exit;
    }

    if ($action === "add_override") {
        $model          = trim((string)($_POST["override_model"] ?? ""));
        $board          = trim((string)($_POST["override_board"] ?? ""));
        $deviceName     = trim((string)($_POST["override_device_name"] ?? ""));
        $identifierType = trim((string)($_POST["identifier_type"] ?? "uuid"));
        $identifier     = normalizeIdentifier($_POST["identifier"] ?? "");
        $note           = trim((string)($_POST["override_note"] ?? ""));
        $firmwareSource = trim((string)($_POST["override_firmware_source"] ?? "current"));

        if (!$model || !$board || !$identifier) {
            die("Invalid override");
        }

        $key = buildOverrideKey($identifierType, $identifier, $board);
        $version = "0.0.0";
        $file = "";
        $size = 0;
        $sourceLabel = "current";

        if ($firmwareSource === "custom") {
            if (!isset($_FILES["override_fw"]) || !is_uploaded_file($_FILES["override_fw"]["tmp_name"])) {
                die("Custom firmware is required");
            }

            $overridePath = buildOverrideFirmwarePath($fwBasePath, $board, $key);
            ensureDirectory($overridePath["dir"]);
            $path = $overridePath["dir"] . "xiaozhi.bin";

            if (!move_uploaded_file($_FILES["override_fw"]["tmp_name"], $path)) {
                die("Failed to save custom firmware");
            }

            $version = extractVersionFromBin($path);
            $file = $overridePath["file"];
            $size = filesize($path);
            $sourceLabel = "custom";
        } else {
            if (!isset($config[$board]) || !is_array($config[$board])) {
                die("Current OTA firmware is not available for this board");
            }

            $fw = $config[$board];
            $version = (string)($fw["version"] ?? "0.0.0");
            $file = (string)($fw["file"] ?? "");
            $size = (int)($fw["size"] ?? 0);
        }

        $deviceOverrides[$key] = [
            "model"           => $model,
            "board"           => $board,
            "device_name"     => $deviceName,
            "identifier_type" => $identifierType,
            "identifier"      => $identifier,
            "version"         => $version,
            "file"            => $file,
            "size"            => $size,
            "source"          => $sourceLabel,
            "notes"           => $note,
            "enable"          => 1,
            "status"          => "active",
            "created_at"      => date("Y-m-d H:i:s"),
            "issued_at"       => "",
            "updated_at"      => "",
            "disabled_at"     => ""
        ];

        file_put_contents($deviceOverridePath, json_encode($deviceOverrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "<script>alert('Device override saved'); location.href='admin.php';</script>";
        exit;
    }

    if ($action === "add_asset_override") {
        $model          = trim((string)($_POST["asset_override_model"] ?? ""));
        $board          = trim((string)($_POST["asset_override_board"] ?? ""));
        $deviceName     = trim((string)($_POST["asset_override_device_name"] ?? ""));
        $identifierType = trim((string)($_POST["asset_identifier_type"] ?? "uuid"));
        $identifier     = normalizeIdentifier($_POST["asset_identifier"] ?? "");
        $note           = trim((string)($_POST["asset_override_note"] ?? ""));
        $source         = trim((string)($_POST["asset_override_source"] ?? "current"));
        $version        = trim((string)($_POST["asset_override_version"] ?? ""));

        if (!$model || !$board || !$identifier) {
            die("Invalid asset override");
        }

        $key = buildOverrideKey($identifierType, $identifier, $board);
        $file = "";
        $size = 0;
        $checksum = "";
        $sourceLabel = "current";

        if ($source === "custom") {
            if (!$version) {
                die("Asset version is required for custom asset override");
            }
            if (!isset($_FILES["asset_override_file"]) || !is_uploaded_file($_FILES["asset_override_file"]["tmp_name"])) {
                die("Custom asset file is required");
            }

            $overridePath = buildOverrideAssetPath($assetBasePath, $board, $key);
            ensureDirectory($overridePath["dir"]);
            $path = $overridePath["dir"] . "assets.bin";

            if (!move_uploaded_file($_FILES["asset_override_file"]["tmp_name"], $path)) {
                die("Failed to save custom asset file");
            }

            $file = $overridePath["file"];
            $size = filesize($path);
            $checksum = readAssetPackageChecksum($path);
            $sourceLabel = "custom";
        } else {
            if (!isset($assetConfig[$board]) || !is_array($assetConfig[$board])) {
                die("Current OTA assets are not available for this board");
            }
            $asset = $assetConfig[$board];
            $version = trim((string)($asset["version"] ?? ""));
            $file = (string)($asset["file"] ?? "");
            $size = (int)($asset["size"] ?? 0);
            $checksum = (string)($asset["checksum"] ?? "");
            if (strlen($checksum) !== 8) {
                $checksum = readAssetPackageChecksum($assetBasePath . $file);
            }
            $sourceLabel = "current";
        }

        if (!$version || !$file) {
            die("Invalid asset override payload");
        }

        $assetOverrides[$key] = [
            "model" => $model,
            "board" => $board,
            "device_name" => $deviceName,
            "identifier_type" => $identifierType,
            "identifier" => $identifier,
            "version" => $version,
            "checksum" => $checksum,
            "file" => $file,
            "size" => $size,
            "source" => $sourceLabel,
            "notes" => $note,
            "enable" => 1,
            "status" => "active",
            "created_at" => date("Y-m-d H:i:s"),
            "issued_at" => "",
            "updated_at" => "",
            "disabled_at" => ""
        ];

        file_put_contents($assetOverridePath, json_encode($assetOverrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "<script>alert('Asset override saved'); location.href='admin.php';</script>";
        exit;
    }

    $model = $_POST["model"] ?? "";
    $board = $_POST["board"] ?? "";
    $notes = trim($_POST["notes"] ?? "");

    if (!$model || !$board || !isset($_FILES["fw"])) {
        die("Invalid upload");
    }

    $fwDir = $fwBasePath . $board . "/";
    if (!is_dir($fwDir)) mkdir($fwDir, 0777, true);

    $fwFile = "xiaozhi.bin";
    $path   = $fwDir . $fwFile;

    move_uploaded_file($_FILES["fw"]["tmp_name"], $path);

    $size    = filesize($path);
    $version = extractVersionFromBin($path);
    $date    = date("Y-m-d H:i:s");

    $config[$board] = [
        "model"   => $model,
        "version" => $version,
        "file"    => $board . "/" . $fwFile,
        "size"    => $size,
        "date"    => $date,
        "notes"   => $notes,
        "force"   => 0,
        "enable"  => 1
    ];
    file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $history[] = [
        "date"    => $date,
        "model"   => $model,
        "board"   => $board,
        "version" => $version,
        "file"    => $fwFile,
        "size"    => $size,
        "notes"   => $notes
    ];
    file_put_contents($historyPath, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    echo "<script>alert('Upload successful'); location.href='admin.php';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Globy OTA Admin</title>

<style>
:root {
    --bg: #f4f1ea;
    --panel: #fffdf8;
    --panel-strong: #f8f2e7;
    --line: #ddcfb8;
    --text: #2f2418;
    --muted: #786956;
    --accent: #b85c38;
    --accent-dark: #8d4124;
    --success: #2f7d4b;
    --danger: #b2392f;
    --shadow: 0 18px 40px rgba(79, 54, 31, 0.08);
}

* {
    box-sizing: border-box;
}

html,
body {
    width: 100%;
    overflow-x: hidden;
}

body {
    margin: 0;
    font-family: "Segoe UI", Arial, sans-serif;
    background:
        radial-gradient(circle at top left, rgba(184, 92, 56, 0.12), transparent 30%),
        linear-gradient(180deg, #f8f4ec 0%, var(--bg) 100%);
    color: var(--text);
}

.page {
    width: min(1360px, 100%);
    margin-inline: auto;
    padding: 28px 20px 40px;
}

.top-row {
    display: block;
    margin-bottom: 18px;
}

.hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 0;
    margin-bottom: 14px;
}

.hero h1 {
    margin: 0;
    font-size: 28px;
    line-height: 1.05;
    letter-spacing: 0.04em;
}

.title-with-icon {
    display: inline-flex;
    align-items: center;
    gap: 16px;
}

.logout-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 36px;
    min-width: 88px;
    border-radius: 999px;
    border: 1px solid var(--line);
    color: var(--accent-dark);
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
    background: var(--panel);
}

.section-title {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.icon-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 999px;
    background: var(--panel-strong);
    color: var(--accent-dark);
    font-size: 14px;
    line-height: 1;
}

.hero-icon {
    position: relative;
    width: 68px;
    height: 68px;
    min-width: 68px;
    border-radius: 22px;
    background:
        radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.25) 35%, transparent 36%),
        linear-gradient(135deg, #ffb36b 0%, #e36a3a 48%, #b2462a 100%);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.35),
        0 12px 24px rgba(179, 91, 50, 0.22);
}

.hero-icon::before,
.hero-icon::after {
    content: "";
    position: absolute;
    border-radius: 999px;
    border: 4px solid rgba(255, 248, 240, 0.96);
    width: 28px;
    height: 28px;
}

.hero-icon::before {
    top: 12px;
    left: 12px;
    border-right-color: transparent;
    border-bottom-color: transparent;
    transform: rotate(18deg);
}

.hero-icon::after {
    right: 12px;
    bottom: 12px;
    border-left-color: transparent;
    border-top-color: transparent;
    transform: rotate(18deg);
}

.hero-icon span {
    position: absolute;
    color: #fff7ef;
    font-size: 18px;
    font-weight: 700;
    line-height: 1;
}

.hero-icon .arrow-up {
    top: 10px;
    right: 11px;
}

.hero-icon .arrow-down {
    bottom: 10px;
    left: 11px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    column-gap: 12px;
    row-gap: 12px;
    width: 100%;
    align-items: stretch;
    min-width: 0;
}

.stat-card,
.panel {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 20px;
    box-shadow: var(--shadow);
}

.stat-card {
    padding: 12px 14px;
    min-height: 0;
    min-width: 0;
}

.stat-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 8px;
}

.stat-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    color: var(--accent-dark);
    font-size: 13px;
    line-height: 1;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
}

.workspace {
    display: grid;
    grid-template-columns: 320px minmax(0, 1fr);
    gap: 14px;
    align-items: start;
}

.stack {
    display: grid;
    gap: 14px;
    position: sticky;
    top: 20px;
}

.panel {
    padding: 16px;
}

.panel h2 {
    margin: 0 0 6px;
    font-size: 18px;
}

.panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
}

.panel-head h2 {
    margin: 0;
}

.panel-intro {
    margin: 0 0 14px;
    color: var(--muted);
    font-size: 13px;
}

.panel-toggle {
    width: 32px;
    min-width: 32px;
    height: 32px;
    min-height: 32px;
    padding: 0;
    border-radius: 999px;
    background: transparent;
    color: var(--accent-dark);
    border: 1px solid var(--line);
    font-size: 18px;
    line-height: 1;
}

.panel.collapsed .panel-body {
    display: none;
}

.panel.collapsed .panel-intro {
    margin-bottom: 0;
}

.form-grid {
    display: grid;
    gap: 12px;
}

.field {
    display: grid;
    gap: 6px;
}

.field label {
    font-size: 13px;
    font-weight: 600;
    color: var(--muted);
}

.hint {
    color: var(--muted);
    font-size: 12px;
}

input[type="text"],
input[type="file"],
select,
textarea {
    width: 100%;
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 10px 12px;
    font-size: 14px;
    background: #fff;
    color: var(--text);
}

textarea {
    min-height: 84px;
    resize: vertical;
}

.actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

button,
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 0 14px;
    border-radius: 999px;
    border: 1px solid var(--accent);
    background: var(--accent);
    color: #fff;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}

.btn-secondary {
    background: transparent;
    color: var(--accent-dark);
}

.status-pill {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
}

.enabled,
.status-enabled {
    color: var(--success);
    background: rgba(47, 125, 75, 0.12);
}

.disabled,
.status-disabled {
    color: var(--danger);
    background: rgba(178, 57, 47, 0.12);
}

.status-updated {
    color: #2f618d;
    background: rgba(47, 97, 141, 0.12);
}

.main-panels {
    display: grid;
    gap: 14px;
}

.table-wrap {
    overflow-x: auto;
    margin: 0 -2px;
}

table {
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
}

th,
td {
    padding: 10px 8px;
    text-align: left;
    vertical-align: top;
    border-top: 1px solid rgba(221, 207, 184, 0.7);
    word-break: normal;
    overflow-wrap: break-word;
}

td {
    font-size: 13px;
    line-height: 1.4;
}

th {
    color: var(--muted);
    font-size: 11px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    border-top: none;
    background: var(--panel-strong);
}

tr:hover td {
    background: rgba(248, 242, 231, 0.55);
}

.col-date    { width: 112px; }
.col-model   { width: 118px; }
.col-device  { width: 150px; }
.col-board   { width: 150px; }
.col-version { width: 72px; }
.col-stats   { width: 110px; }
.col-file    { width: 132px; }
.col-size    { width: 92px; }
.col-notes   { width: auto; }
.col-status  { width: 92px; }
.col-action  { width: 92px; }

.muted {
    color: var(--muted);
    font-size: 13px;
}

.compact {
    font-size: 13px;
    line-height: 1.4;
}

.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
}

.col-date,
.col-model,
.col-device,
.col-board,
.col-version,
.col-stats,
.col-file,
.col-size,
.col-notes,
.col-status,
.col-action {
    font-size: 13px;
    line-height: 1.4;
}

.col-status .status-pill,
.col-action .btn {
    font-size: 12px;
}

.btn-enable {
    border-color: var(--success);
    color: var(--success);
}

.notes-cell {
    min-width: 260px;
    max-width: 420px;
    white-space: normal;
    word-break: normal;
    overflow-wrap: anywhere;
}

.hide-desktop {
    display: none;
}

.empty-note {
    color: var(--muted);
    font-style: italic;
}

@media (max-width: 1120px) {
    .workspace {
        grid-template-columns: 1fr;
    }

    .stack {
        position: static;
    }
}

@media (max-width: 720px) {
    .hero {
        min-height: auto;
        align-items: flex-start;
        flex-direction: column;
        gap: 10px;
    }

    .hero h1 {
        font-size: 22px;
    }

    .stats {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .page {
        padding: 18px 14px 28px;
    }

    .hide-mobile {
        display: none;
    }
}
</style>

<script>
function updateBoard() {
    const map = <?= json_encode($models) ?>;
    document.getElementById("board").value =
        map[document.getElementById("model").value] || "";
}

function updateOverrideFirmwareSource() {
    const source = document.getElementById("override_firmware_source").value;
    const customField = document.getElementById("override_fw_wrap");
    const customInput = document.getElementById("override_fw");
    const currentHint = document.getElementById("override_current_hint");

    const isCustom = source === "custom";
    customField.style.display = isCustom ? "grid" : "none";
    customInput.required = isCustom;
    currentHint.style.display = isCustom ? "none" : "block";
}

function togglePanel(id) {
    const panel = document.getElementById(id);
    if (!panel) return;
    panel.classList.toggle("collapsed");
    const button = panel.querySelector(".panel-toggle");
    if (button) {
        const collapsed = panel.classList.contains("collapsed");
        button.textContent = collapsed ? "+" : "-";
        button.setAttribute("aria-expanded", collapsed ? "false" : "true");
    }
}

function editBoard(model, board, routeRaw, routeSuffix) {
    const panel = document.getElementById('manage-boards-panel');
    if (panel.classList.contains('collapsed')) {
        togglePanel('manage-boards-panel');
    }
    document.getElementById('board_form_title').textContent = 'Edit Board';
    document.getElementById('board_model_name').value = model;
    document.getElementById('board_key').value = board;
    document.getElementById('board_old_model').value = model;
    document.getElementById('board_old_board').value = board;
    document.getElementById('board_route_raw').value = routeRaw || '';
    document.getElementById('board_route_suffix').value = routeSuffix || '';
    document.getElementById('board_save_btn').textContent = 'Update Board';
    document.getElementById('board_cancel_btn').style.display = 'inline-flex';
}

function cancelEditBoard() {
    document.getElementById('board_form_title').textContent = 'Add New Board';
    document.getElementById('board_model_name').value = '';
    document.getElementById('board_key').value = '';
    document.getElementById('board_old_model').value = '';
    document.getElementById('board_old_board').value = '';
    document.getElementById('board_route_raw').value = '';
    document.getElementById('board_route_suffix').value = '';
    document.getElementById('board_save_btn').textContent = 'Add Board';
    document.getElementById('board_cancel_btn').style.display = 'none';
}
</script>
</head>

<body>
<?php
$totalBoards = count($config);
$enabledBoards = 0;
foreach ($config as $fw) {
    if (!empty($fw["enable"])) $enabledBoards++;
}
$activeOverrides = 0;
foreach ($deviceOverrides as $override) {
    if (!empty($override["enable"])) $activeOverrides++;
}
$historyCount = count($history);
$totalUniqueDevices = count($deviceStats);
?>

<div class="page">
    <div class="top-row">
        <div class="hero">
            <h1 class="title-with-icon"><span class="hero-icon" aria-hidden="true"><span class="arrow-up">&#8593;</span><span class="arrow-down">&#8595;</span></span>GLOBY OTA</h1>
            <a class="logout-link" href="?logout=1">Logout</a>
        </div>
        <div class="stats">
            <div class="stat-card">
                <span class="stat-label"><span class="stat-icon">&#128230;</span>Models Published</span>
                <div class="stat-value"><?= $totalBoards ?></div>
            </div>
            <div class="stat-card">
                <span class="stat-label"><span class="stat-icon">&#9889;</span>OTA Enabled</span>
                <div class="stat-value"><?= $enabledBoards ?></div>
            </div>
            <div class="stat-card">
                <span class="stat-label"><span class="stat-icon">&#128295;</span>Device Overrides</span>
                <div class="stat-value"><?= $activeOverrides ?></div>
            </div>
            <div class="stat-card">
                <span class="stat-label"><span class="stat-icon">&#128221;</span>Release History</span>
                <div class="stat-value"><?= $historyCount ?></div>
            </div>
            <div class="stat-card">
                <span class="stat-label"><span class="stat-icon">&#128225;</span>Active Devices</span>
                <div class="stat-value"><?= $totalUniqueDevices ?></div>
                <div class="muted">unique devices checked-in</div>
            </div>
        </div>
    </div>

    <div class="workspace">
        <div class="stack">
            <section class="panel collapsed" id="manage-boards-panel">
                <div class="panel-head">
                    <h2 class="section-title"><span class="icon-mark">&#128227;</span>Manage Boards</h2>
                    <button type="button" class="panel-toggle" aria-expanded="false" onclick="togglePanel('manage-boards-panel')">+</button>
                </div>
                <p class="panel-intro">Add, edit, or delete board configurations and model mappings.</p>
                <div class="panel-body">
                    <div style="margin-bottom: 15px; border-bottom: 1px solid var(--line); padding-bottom: 12px; overflow-x: auto;">
                        <table style="width:100%; border-collapse: collapse; min-width: 320px;">
                            <thead>
                                <tr style="background: var(--panel-strong);">
                                    <th style="font-size:10px; padding:6px 4px; text-transform:uppercase; color:var(--muted);">Model</th>
                                    <th style="font-size:10px; padding:6px 4px; text-transform:uppercase; color:var(--muted);">Board Key</th>
                                    <th style="font-size:10px; padding:6px 4px; text-transform:uppercase; color:var(--muted);">Routing Rule</th>
                                    <th style="font-size:10px; padding:6px 4px; text-transform:uppercase; color:var(--muted); text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($boardsMeta as $b => $meta): ?>
                                <?php
                                    $m = $meta["model"] ?? "";
                                    $routeRaw = $meta["route_raw_board"] ?? "";
                                    $routeSuffix = $meta["route_suffix"] ?? "";
                                    $routingDisplay = ($routeRaw !== "" && $routeSuffix !== "") ? htmlspecialchars($routeRaw) . " (" . htmlspecialchars($routeSuffix) . ")" : "-";
                                ?>
                                <tr>
                                    <td style="padding:6px 4px; font-size:12px; border-top:1px solid rgba(221,207,184,0.4);"><?= htmlspecialchars($m) ?></td>
                                    <td style="padding:6px 4px; font-size:12px; font-family:monospace; border-top:1px solid rgba(221,207,184,0.4);"><?= htmlspecialchars($b) ?></td>
                                    <td style="padding:6px 4px; font-size:12px; border-top:1px solid rgba(221,207,184,0.4); color:var(--muted);"><?= $routingDisplay ?></td>
                                    <td style="padding:6px 4px; font-size:12px; text-align:right; white-space:nowrap; border-top:1px solid rgba(221,207,184,0.4);">
                                        <a href="javascript:void(0)" onclick="editBoard('<?= addslashes($m) ?>', '<?= addslashes($b) ?>', '<?= addslashes($routeRaw) ?>', '<?= addslashes($routeSuffix) ?>')" style="color:var(--accent); text-decoration:none; margin-right:8px; font-weight:bold;">Edit</a>
                                        <a href="?delete_board=<?= urlencode($b) ?>" onclick="return confirm('Are you sure you want to delete board mapping for <?= htmlspecialchars($m) ?>?')" style="color:var(--danger); text-decoration:none; font-weight:bold;">Delete</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <form method="POST" class="form-grid" id="board-form">
                        <input type="hidden" name="action" value="save_board">
                        <input type="hidden" name="old_model" id="board_old_model" value="">
                        <input type="hidden" name="old_board" id="board_old_board" value="">
                        
                        <div class="field">
                            <label id="board_form_title" style="font-size:14px; color:var(--accent-dark);">Add New Board</label>
                        </div>
                        
                        <div class="field">
                            <label>Model Name</label>
                            <input type="text" name="new_model_name" id="board_model_name" placeholder="e.g. Globy Rabbit Pro V2" required>
                        </div>
                        
                        <div class="field">
                            <label>Board Key</label>
                            <input type="text" name="new_board_key" id="board_key" placeholder="e.g. jiuchuan-s3-v2" required>
                            <span class="hint">lowercase, alphanumeric, dashes</span>
                        </div>
                        
                        <div class="field">
                            <label>Route Raw Board Key (Optional)</label>
                            <input type="text" name="route_raw_board" id="board_route_raw" placeholder="e.g. jiuchuan-s3">
                            <span class="hint">Raw board name sent by the device (e.g. jiuchuan-s3)</span>
                        </div>
                        
                        <div class="field">
                            <label>Route Version Suffix (Optional)</label>
                            <input type="text" name="route_suffix" id="board_route_suffix" placeholder="e.g. -r2">
                            <span class="hint">Suffix in firmware version triggering this mapping (e.g. -r2)</span>
                        </div>
                        
                        <div class="actions">
                            <button type="submit" id="board_save_btn">Add Board</button>
                            <button type="button" id="board_cancel_btn" class="btn btn-secondary" style="display:none;" onclick="cancelEditBoard()">Cancel</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="panel collapsed" id="upload-panel">
                <div class="panel-head">
                    <h2 class="section-title"><span class="icon-mark">&#11014;</span>Upload Firmware</h2>
                    <button type="button" class="panel-toggle" aria-expanded="false" onclick="togglePanel('upload-panel')">+</button>
                </div>
                <p class="panel-intro">Publish firmware moi cho tung model. Ban publish xong thi board do se duoc bat OTA ngay.</p>
                <div class="panel-body">
                    <form method="POST" enctype="multipart/form-data" class="form-grid">
                        <div class="field">
                            <label>Model</label>
                            <select name="model" id="model" onchange="updateBoard()" required>
                                <option value="">Select model</option>
                                <?php foreach ($models as $m => $b): ?>
                                    <option><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label>Board</label>
                            <input type="text" id="board" name="board" readonly required>
                        </div>

                        <div class="field">
                            <label>Firmware (.bin)</label>
                            <input type="file" name="fw" accept=".bin" required>
                        </div>

                        <div class="field">
                            <label>Release Notes</label>
                            <textarea name="notes" rows="4" required></textarea>
                        </div>

                        <div class="actions">
                            <button type="submit">Upload & Publish</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="panel collapsed" id="asset-upload-panel">
                <div class="panel-head">
                    <h2 class="section-title"><span class="icon-mark">&#127912;</span>Upload Assets</h2>
                    <button type="button" class="panel-toggle" aria-expanded="false" onclick="togglePanel('asset-upload-panel')">+</button>
                </div>
                <p class="panel-intro">Publish assets.bin cho tung board de cap nhat tai nguyen, icon, font va data di kem.</p>
                <div class="panel-body">
                    <form method="POST" enctype="multipart/form-data" class="form-grid">
                        <input type="hidden" name="action" value="upload_assets">

                        <div class="field">
                            <label>Model</label>
                            <select name="asset_model" id="asset_model" onchange="updateAssetBoard()" required>
                                <option value="">Select model</option>
                                <?php foreach ($models as $m => $b): ?>
                                    <option><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label>Board</label>
                            <input type="text" id="asset_board" name="asset_board" readonly required>
                        </div>

                        <div class="field">
                            <label>Assets Version</label>
                            <input type="text" name="asset_version" placeholder="2026.03.21-ui-01" required>
                        </div>

                        <div class="field">
                            <label>Assets File (.bin)</label>
                            <input type="file" name="asset_file" accept=".bin" required>
                        </div>

                        <div class="field">
                            <label>Notes</label>
                            <textarea name="asset_notes" rows="3" placeholder="New icons, language pack, idle screen bundle"></textarea>
                        </div>

                        <div class="actions">
                            <button type="submit">Upload Assets</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="panel" id="force-panel">
                <div class="panel-head">
                    <h2 class="section-title"><span class="icon-mark">&#128736;</span>Force One Device</h2>
                    <button type="button" class="panel-toggle" aria-expanded="true" onclick="togglePanel('force-panel')">-</button>
                </div>
                <p class="panel-intro">Chi ap dung cho mot may cu the theo UUID hoac MAC, khong anh huong may khac cung model.</p>
                <div class="panel-body">
                    <form method="POST" enctype="multipart/form-data" class="form-grid">
                        <input type="hidden" name="action" value="add_override">

                        <div class="field">
                            <label>Model</label>
                            <select name="override_model" id="override_model" onchange="updateOverrideBoard()" required>
                                <option value="">Select model</option>
                                <?php foreach ($models as $m => $b): ?>
                                    <option><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label>Board</label>
                            <input type="text" id="override_board" name="override_board" readonly required>
                        </div>

                        <div class="field">
                            <label>Device Name</label>
                            <input type="text" name="override_device_name" placeholder="Customer Living Room Speaker">
                        </div>

                        <div class="field">
                            <label>Match By</label>
                            <select name="identifier_type" required>
                                <option value="uuid">UUID</option>
                                <option value="mac">MAC Address</option>
                            </select>
                        </div>

                        <div class="field">
                            <label>UUID / MAC</label>
                            <input type="text" name="identifier" placeholder="9c9d197a-44ef-4ea9-ae02-31ecbc56e01b" required>
                        </div>

                        <div class="field">
                            <label>Firmware Source</label>
                            <select name="override_firmware_source" id="override_firmware_source" onchange="updateOverrideFirmwareSource()" required>
                                <option value="current">Use current OTA firmware</option>
                                <option value="custom">Upload custom firmware</option>
                            </select>
                            <span class="hint" id="override_current_hint">Dung firmware dang publish cho model nay de ep cai lai tren mot may cu the.</span>
                        </div>

                        <div class="field" id="override_fw_wrap" style="display:none;">
                            <label>Custom Firmware (.bin)</label>
                            <input type="file" name="override_fw" id="override_fw" accept=".bin">
                            <span class="hint">File nay chi dung cho override nay, khong thay doi firmware OTA chung.</span>
                        </div>

                        <div class="field">
                            <label>Reason</label>
                            <textarea name="override_note" rows="3" placeholder="Repair one faulty device without affecting others"></textarea>
                        </div>

                        <div class="actions">
                            <button type="submit">Save Device Override</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="panel collapsed" id="asset-force-panel">
                <div class="panel-head">
                    <h2 class="section-title"><span class="icon-mark">&#127911;</span>Force Assets One Device</h2>
                    <button type="button" class="panel-toggle" aria-expanded="false" onclick="togglePanel('asset-force-panel')">+</button>
                </div>
                <p class="panel-intro">Force cap nhat assets cho mot thiet bi cu the ma khong anh huong cac may khac.</p>
                <div class="panel-body">
                    <form method="POST" enctype="multipart/form-data" class="form-grid">
                        <input type="hidden" name="action" value="add_asset_override">

                        <div class="field">
                            <label>Model</label>
                            <select name="asset_override_model" id="asset_override_model" onchange="updateAssetOverrideBoard()" required>
                                <option value="">Select model</option>
                                <?php foreach ($models as $m => $b): ?>
                                    <option><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label>Board</label>
                            <input type="text" id="asset_override_board" name="asset_override_board" readonly required>
                        </div>

                        <div class="field">
                            <label>Device Name</label>
                            <input type="text" name="asset_override_device_name" placeholder="Customer Living Room Speaker">
                        </div>

                        <div class="field">
                            <label>Match By</label>
                            <select name="asset_identifier_type" required>
                                <option value="uuid">UUID</option>
                                <option value="mac">MAC Address</option>
                            </select>
                        </div>

                        <div class="field">
                            <label>UUID / MAC</label>
                            <input type="text" name="asset_identifier" placeholder="9c9d197a-44ef-4ea9-ae02-31ecbc56e01b" required>
                        </div>

                        <div class="field">
                            <label>Assets Source</label>
                            <select name="asset_override_source" id="asset_override_source" onchange="updateAssetOverrideSource()" required>
                                <option value="current">Use current OTA assets</option>
                                <option value="custom">Upload custom assets</option>
                            </select>
                            <span class="hint" id="asset_override_current_hint">Dung assets dang publish cho board nay de sua rieng mot may.</span>
                        </div>

                        <div class="field" id="asset_override_version_wrap" style="display:none;">
                            <label>Assets Version</label>
                            <input type="text" name="asset_override_version" id="asset_override_version" placeholder="2026.03.21-repair-01">
                        </div>

                        <div class="field" id="asset_override_file_wrap" style="display:none;">
                            <label>Custom Assets (.bin)</label>
                            <input type="file" name="asset_override_file" id="asset_override_file" accept=".bin">
                            <span class="hint">File nay chi dung cho asset override nay, khong thay doi assets OTA chung.</span>
                        </div>

                        <div class="field">
                            <label>Reason</label>
                            <textarea name="asset_override_note" rows="3" placeholder="Repair assets package for one device"></textarea>
                        </div>

                        <div class="actions">
                            <button type="submit">Save Asset Override</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>

        <div class="main-panels">
            
            <section class="panel">
                <h2 class="section-title"><span class="icon-mark">&#128190;</span>Current Firmware</h2>
                <p class="panel-intro">Danh sach firmware dang phat hanh theo tung board va trang thai OTA hien tai.</p>
                <div class="table-wrap">
                    <table>
                        <tr>
                            <th class="col-date">Date</th>
                            <th class="col-model">Model</th>
                            <th class="col-board">Board</th>
                            <th class="col-version">Version</th>
                            <th class="col-stats">Devices</th>
                            <th class="col-file hide-mobile">File</th>
                            <th class="col-size hide-mobile">Size</th>
                            <th class="col-notes">Release Notes</th>
                            <th class="col-status">Status</th>
                            <th class="col-action">Action</th>
                        </tr>

                        <?php foreach ($config as $board => $fw): ?>
                        <?php
                            $targetVersion = (string)($fw["version"] ?? "");
                            $totalDevices = 0;
                            $updatedDevices = 0;
                            $versionCounts = [];
                            foreach ($deviceStats as $devId => $dev) {
                                if (($dev["board"] ?? "") === $board) {
                                    $totalDevices++;
                                    $devVer = (string)($dev["version"] ?? "");
                                    if ($devVer === $targetVersion) {
                                        $updatedDevices++;
                                    }
                                    if ($devVer !== "") {
                                        $versionCounts[$devVer] = ($versionCounts[$devVer] ?? 0) + 1;
                                    }
                                }
                            }
                            $breakdownParts = [];
                            foreach ($versionCounts as $v => $c) {
                                $breakdownParts[] = "$v: $c device" . ($c > 1 ? "s" : "");
                            }
                            $tooltip = count($breakdownParts) > 0 ? "Version breakdown:\n" . implode("\n", $breakdownParts) : "No check-ins yet";
                        ?>
                        <tr>
                            <td class="col-date"><?= htmlspecialchars($fw["date"] ?? "") ?></td>
                            <td class="col-model"><?= htmlspecialchars($fw["model"] ?? "") ?></td>
                            <td class="col-board"><?= htmlspecialchars($board) ?></td>
                            <td class="col-version"><?= htmlspecialchars($fw["version"] ?? "") ?></td>
                            <td class="col-stats">
                                <?php if ($totalDevices > 0): ?>
                                    <strong><?= $updatedDevices ?> / <?= $totalDevices ?></strong>
                                    <div style="font-size: 11px; color: var(--muted);" title="<?= htmlspecialchars($tooltip) ?>">
                                        <?= round(($updatedDevices / $totalDevices) * 100) ?>% updated <span style="cursor: help; text-decoration: underline dotted; color: var(--accent);">[?]</span>
                                    </div>
                                <?php else: ?>
                                    <span class="muted" style="font-size: 11px;">0 devices seen</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-file hide-mobile mono"><?= htmlspecialchars(basename($fw["file"] ?? "")) ?></td>
                            <td class="col-size hide-mobile compact"><?= number_format((int)($fw["size"] ?? 0)) ?></td>
                            <td class="col-notes notes-cell compact"><?= nl2br(htmlspecialchars($fw["notes"] ?? "")) ?></td>
                            <td class="col-status">
                                <span class="status-pill <?= !empty($fw["enable"]) ? 'status-enabled' : 'status-disabled' ?>">
                                    <?= !empty($fw["enable"]) ? "ENABLED" : "DISABLED" ?>
                                </span>
                            </td>
                            <td class="col-action">
                                <a class="btn btn-secondary" href="?toggle=<?= urlencode($board) ?>">
                                    <?= !empty($fw["enable"]) ? "Disable" : "Enable" ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>

            <section class="panel">
                <h2 class="section-title"><span class="icon-mark">&#128295;</span>Device Force Overrides</h2>
                <p class="panel-intro">Danh sach cac may dang duoc force update tam thoi. Go bo khi da sua xong de tranh lap lai OTA.</p>
                <div class="table-wrap">
                    <table>
                        <tr>
                            <th class="col-date">Created</th>
                            <th class="col-device">Device Name</th>
                            <th class="col-model">Model</th>
                            <th class="col-board">Board</th>
                            <th class="col-version">Version</th>
                            <th class="col-file">Match</th>
                            <th class="col-version">Source</th>
                            <th class="col-notes">Reason</th>
                            <th class="col-status">Status</th>
                            <th class="col-action">Action</th>
                        </tr>

                        <?php foreach (array_reverse($deviceOverrides, true) as $key => $override): ?>
                        <?php
                            $isEnabled = (int)($override["enable"] ?? 0) === 1;
                            $rawStatus = strtolower((string)($override["status"] ?? ""));
                            if ($isEnabled) {
                                $statusLabel = "ACTIVE";
                                $statusClass = "status-enabled";
                            } elseif ($rawStatus === "updated" || !empty($override["updated_at"])) {
                                $statusLabel = "UPDATED";
                                $statusClass = "status-updated";
                            } else {
                                $statusLabel = "DISABLED";
                                $statusClass = "status-disabled";
                            }
                        ?>
                        <tr>
                            <td class="col-date"><?= htmlspecialchars($override["created_at"] ?? "") ?></td>
                            <td class="col-device"><?= htmlspecialchars($override["device_name"] ?? "-") ?></td>
                            <td class="col-model"><?= htmlspecialchars($override["model"] ?? "") ?></td>
                            <td class="col-board"><?= htmlspecialchars($override["board"] ?? "") ?></td>
                            <td class="col-version"><?= htmlspecialchars($override["version"] ?? "") ?></td>
                            <td class="col-file mono"><?= strtoupper(htmlspecialchars($override["identifier_type"] ?? "")) ?>: <?= htmlspecialchars($override["identifier"] ?? "") ?></td>
                            <td class="col-version compact"><?= htmlspecialchars(strtoupper((string)($override["source"] ?? "CURRENT"))) ?></td>
                            <td class="col-notes notes-cell compact">
                                <?php if (!empty($override["notes"])): ?>
                                    <?= nl2br(htmlspecialchars($override["notes"])) ?>
                                <?php else: ?>
                                    <span class="empty-note">No reason provided</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-status"><span class="status-pill <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                            <td class="col-action">
                                <a class="btn btn-secondary <?= $isEnabled ? '' : 'btn-enable' ?>" href="?toggle_override=<?= urlencode($key) ?>">
                                    <?= $isEnabled ? "Disable" : "Enable" ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>

            <section class="panel">
                <h2 class="section-title"><span class="icon-mark">&#127912;</span>Current Assets</h2>
                <p class="panel-intro">Danh sach assets.bin dang duoc phat hanh theo tung board cho cap nhat tai nguyen thong thuong.</p>
                <div class="table-wrap">
                    <table>
                        <tr>
                            <th class="col-date">Date</th>
                            <th class="col-model">Model</th>
                            <th class="col-board">Board</th>
                            <th class="col-version">Version</th>
                            <th class="col-file">File</th>
                            <th class="col-size">Size</th>
                            <th class="col-notes">Notes</th>
                            <th class="col-status">Status</th>
                            <th class="col-action">Action</th>
                        </tr>

                        <?php foreach ($assetConfig as $board => $asset): ?>
                        <tr>
                            <td class="col-date"><?= htmlspecialchars($asset["date"] ?? "") ?></td>
                            <td class="col-model"><?= htmlspecialchars($asset["model"] ?? "") ?></td>
                            <td class="col-board"><?= htmlspecialchars($board) ?></td>
                            <td class="col-version"><?= htmlspecialchars($asset["version"] ?? "") ?></td>
                            <td class="col-file mono"><?= htmlspecialchars(basename($asset["file"] ?? "")) ?></td>
                            <td class="col-size compact"><?= number_format((int)($asset["size"] ?? 0)) ?></td>
                            <td class="col-notes notes-cell compact"><?= nl2br(htmlspecialchars($asset["notes"] ?? "")) ?></td>
                            <td class="col-status">
                                <span class="status-pill <?= !empty($asset["enable"]) ? 'status-enabled' : 'status-disabled' ?>">
                                    <?= !empty($asset["enable"]) ? "ENABLED" : "DISABLED" ?>
                                </span>
                            </td>
                            <td class="col-action">
                                <a class="btn btn-secondary" href="?toggle_asset=<?= urlencode($board) ?>">
                                    <?= !empty($asset["enable"]) ? "Disable" : "Enable" ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>

            <section class="panel">
                <h2 class="section-title"><span class="icon-mark">&#127911;</span>Device Asset Overrides</h2>
                <p class="panel-intro">Danh sach cac may dang duoc force cap nhat assets. Sau khi thiet bi tai xong se tu dong chuyen sang UPDATED va tat override.</p>
                <div class="table-wrap">
                    <table>
                        <tr>
                            <th class="col-date">Created</th>
                            <th class="col-device">Device Name</th>
                            <th class="col-model">Model</th>
                            <th class="col-board">Board</th>
                            <th class="col-version">Version</th>
                            <th class="col-file">Match</th>
                            <th class="col-version">Source</th>
                            <th class="col-notes">Reason</th>
                            <th class="col-status">Status</th>
                            <th class="col-action">Action</th>
                        </tr>

                        <?php foreach (array_reverse($assetOverrides, true) as $key => $override): ?>
                        <?php
                            $isEnabled = (int)($override["enable"] ?? 0) === 1;
                            $rawStatus = strtolower((string)($override["status"] ?? ""));
                            if ($isEnabled) {
                                $statusLabel = "ACTIVE";
                                $statusClass = "status-enabled";
                            } elseif ($rawStatus === "updated" || !empty($override["updated_at"])) {
                                $statusLabel = "UPDATED";
                                $statusClass = "status-updated";
                            } else {
                                $statusLabel = "DISABLED";
                                $statusClass = "status-disabled";
                            }
                        ?>
                        <tr>
                            <td class="col-date"><?= htmlspecialchars($override["created_at"] ?? "") ?></td>
                            <td class="col-device"><?= htmlspecialchars($override["device_name"] ?? "-") ?></td>
                            <td class="col-model"><?= htmlspecialchars($override["model"] ?? "") ?></td>
                            <td class="col-board"><?= htmlspecialchars($override["board"] ?? "") ?></td>
                            <td class="col-version"><?= htmlspecialchars($override["version"] ?? "") ?></td>
                            <td class="col-file mono"><?= strtoupper(htmlspecialchars($override["identifier_type"] ?? "")) ?>: <?= htmlspecialchars($override["identifier"] ?? "") ?></td>
                            <td class="col-version compact"><?= htmlspecialchars(strtoupper((string)($override["source"] ?? "CURRENT"))) ?></td>
                            <td class="col-notes notes-cell compact">
                                <?php if (!empty($override["notes"])): ?>
                                    <?= nl2br(htmlspecialchars($override["notes"])) ?>
                                <?php else: ?>
                                    <span class="empty-note">No reason provided</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-status"><span class="status-pill <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                            <td class="col-action">
                                <a class="btn btn-secondary <?= $isEnabled ? '' : 'btn-enable' ?>" href="?toggle_asset_override=<?= urlencode($key) ?>">
                                    <?= $isEnabled ? "Disable" : "Enable" ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>

            <section class="panel">
                <h2 class="section-title"><span class="icon-mark">&#128221;</span>Upload History</h2>
                <p class="panel-intro">Lich su phat hanh firmware, uu tien moi nhat o tren cung.</p>
                <div class="table-wrap">
                    <table>
                        <tr>
                            <th class="col-date">Date</th>
                            <th class="col-model">Model</th>
                            <th class="col-board">Board</th>
                            <th class="col-version">Version</th>
                            <th class="col-file hide-mobile">File</th>
                            <th class="col-size hide-mobile">Size</th>
                            <th class="col-notes">Release Notes</th>
                        </tr>

                        <?php foreach (array_reverse($history) as $h): ?>
                        <tr>
                            <td class="col-date"><?= htmlspecialchars($h["date"] ?? "") ?></td>
                            <td class="col-model"><?= htmlspecialchars($h["model"] ?? "") ?></td>
                            <td class="col-board"><?= htmlspecialchars($h["board"] ?? "") ?></td>
                            <td class="col-version"><?= htmlspecialchars($h["version"] ?? "") ?></td>
                            <td class="col-file hide-mobile mono"><?= htmlspecialchars($h["file"] ?? "") ?></td>
                            <td class="col-size hide-mobile compact"><?= number_format((int)($h["size"] ?? 0)) ?></td>
                            <td class="col-notes notes-cell compact"><?= nl2br(htmlspecialchars($h["notes"] ?? "")) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
function updateAssetBoard() {
    const map = <?= json_encode($models) ?>;
    document.getElementById("asset_board").value =
        map[document.getElementById("asset_model").value] || "";
}

function updateOverrideBoard() {
    const map = <?= json_encode($models) ?>;
    document.getElementById("override_board").value =
        map[document.getElementById("override_model").value] || "";
}

function updateAssetOverrideBoard() {
    const map = <?= json_encode($models) ?>;
    document.getElementById("asset_override_board").value =
        map[document.getElementById("asset_override_model").value] || "";
}

function updateAssetOverrideSource() {
    const source = document.getElementById("asset_override_source").value;
    const customField = document.getElementById("asset_override_file_wrap");
    const versionField = document.getElementById("asset_override_version_wrap");
    const customInput = document.getElementById("asset_override_file");
    const versionInput = document.getElementById("asset_override_version");
    const currentHint = document.getElementById("asset_override_current_hint");

    const isCustom = source === "custom";
    customField.style.display = isCustom ? "grid" : "none";
    versionField.style.display = isCustom ? "grid" : "none";
    customInput.required = isCustom;
    versionInput.required = isCustom;
    currentHint.style.display = isCustom ? "none" : "block";
}

updateOverrideFirmwareSource();
updateAssetOverrideSource();
</script>
</body>
</html>
