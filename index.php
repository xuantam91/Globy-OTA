<?php
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// ================================
// PATHS
// ================================
$configPath         = __DIR__ . "/ota_config.json";
$deviceOverridePath = __DIR__ . "/device_overrides.json";
$fwBasePath         = __DIR__ . "/ota_fw/";
$assetConfigPath    = __DIR__ . "/ota_assets_config.json";
$assetOverridePath  = __DIR__ . "/asset_overrides.json";
$assetBasePath      = __DIR__ . "/ota_assets/";

// Build firmware base URL from current request host to avoid cross-domain 404.
$isHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");
if (!empty($_SERVER["HTTP_X_FORWARDED_PROTO"])) {
    $isHttps = strtolower((string)$_SERVER["HTTP_X_FORWARDED_PROTO"]) === "https";
}
$scheme = $isHttps ? "https" : "http";
$host = (string)($_SERVER["HTTP_HOST"] ?? ($_SERVER["SERVER_NAME"] ?? "localhost"));
$fwBaseUrl = $scheme . "://" . $host . "/ota_fw/";
$assetBaseUrl = $scheme . "://" . $host . "/ota_assets/";

// ================================
// LOAD CONFIG
// ================================
$config = [];
if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true);
    if (!is_array($config)) $config = [];
}

$deviceOverrides = [];
if (file_exists($deviceOverridePath)) {
    $deviceOverrides = json_decode(file_get_contents($deviceOverridePath), true);
    if (!is_array($deviceOverrides)) $deviceOverrides = [];
}

$assetConfig = [];
if (file_exists($assetConfigPath)) {
    $assetConfig = json_decode(file_get_contents($assetConfigPath), true);
    if (!is_array($assetConfig)) $assetConfig = [];
}

$assetOverrides = [];
if (file_exists($assetOverridePath)) {
    $assetOverrides = json_decode(file_get_contents($assetOverridePath), true);
    if (!is_array($assetOverrides)) $assetOverrides = [];
}

function saveDeviceOverrides($path, $overrides) {
    file_put_contents($path, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
// READ REQUEST JSON
// ================================
$raw  = file_get_contents("php://input");
$body = json_decode($raw, true);

$boardType = strtolower($body["board"]["type"] ?? "");
$rawBoardType = $boardType;
$curVer    = $body["application"]["version"] ?? "0.0.0";
$curAssetChecksum = strtolower(trim((string)($body["assets"]["checksum"] ?? "")));
$uuid      = trim((string)($body["uuid"] ?? ""));
$macAddr   = strtolower(trim((string)($body["mac_address"] ?? ($body["board"]["mac"] ?? ""))));

// Parse dynamic mapping rules from boards.json
$boardsFile = __DIR__ . "/boards.json";
$boardsMeta = file_exists($boardsFile) ? json_decode(file_get_contents($boardsFile), true) : [];
if (is_array($boardsMeta)) {
    foreach ($boardsMeta as $bk => $meta) {
        if (!empty($meta["route_raw_board"]) && !empty($meta["route_suffix"])) {
            $rawMatch = (strtolower($meta["route_raw_board"]) === $boardType);
            $suffixMatch = (stripos($curVer, $meta["route_suffix"]) !== false);
            if ($rawMatch && $suffixMatch) {
                $boardType = $bk;
                break;
            }
        }
    }
}

// ================================
// RECORD DEVICE STATS
// ================================
$deviceId = $uuid ?: $macAddr;
if ($deviceId !== "") {
    $statsPath = __DIR__ . "/device_stats.json";
    $lockFile = $statsPath . ".lock";
    $lockHandle = @fopen($lockFile, "w");
    if ($lockHandle) {
        flock($lockHandle, LOCK_EX);
        $stats = [];
        if (file_exists($statsPath)) {
            $stats = json_decode(file_get_contents($statsPath), true);
            if (!is_array($stats)) $stats = [];
        }
        $stats[$deviceId] = [
            "board" => $boardType,
            "mac" => $macAddr,
            "uuid" => $uuid,
            "version" => $curVer,
            "last_seen" => date("Y-m-d H:i:s")
        ];
        file_put_contents($statsPath, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

// ================================
// VERSION COMPARE
// ================================
function parseVersion($v) {
    $parts = explode("-", (string)$v, 2);
    $base = $parts[0];
    $suffix = $parts[1] ?? "";
    
    $baseNums = array_map("intval", explode(".", $base));
    
    $suffixNum = 0;
    if ($suffix !== "") {
        preg_match('/\d+/', $suffix, $m);
        $suffixNum = isset($m[0]) ? (int)$m[0] : 0;
    }
    
    return [
        "nums" => $baseNums,
        "suffix_str" => strtolower($suffix),
        "suffix_num" => $suffixNum
    ];
}

function isNewer($cur, $new) {
    $c = parseVersion($cur);
    $n = parseVersion($new);
    
    $len = min(count($c["nums"]), count($n["nums"]));
    for ($i = 0; $i < $len; $i++) {
        if ($n["nums"][$i] > $c["nums"][$i]) return true;
        if ($n["nums"][$i] < $c["nums"][$i]) return false;
    }
    
    if (count($n["nums"]) > count($c["nums"])) return true;
    if (count($n["nums"]) < count($c["nums"])) return false;
    
    if ($n["suffix_num"] > $c["suffix_num"]) return true;
    if ($n["suffix_num"] < $c["suffix_num"]) return false;
    
    if ($n["suffix_str"] !== $c["suffix_str"]) {
        return strcmp($n["suffix_str"], $c["suffix_str"]) > 0;
    }
    
    return false;
}

function normalizeIdentifier($value) {
    return strtolower(trim((string)$value));
}

function findDeviceOverride($overrides, $boardType, $uuid, $macAddr) {
    if (!is_array($overrides)) return null;

    $candidates = [];
    if ($uuid !== "") {
        $candidates[] = ["type" => "uuid", "value" => normalizeIdentifier($uuid)];
    }
    if ($macAddr !== "") {
        $candidates[] = ["type" => "mac", "value" => normalizeIdentifier($macAddr)];
    }

    foreach ($candidates as $candidate) {
        foreach ($overrides as $key => $override) {
            if (!is_array($override)) continue;
            if ((int)($override["enable"] ?? 0) !== 1) continue;
            if (strtolower((string)($override["board"] ?? "")) !== $boardType) continue;

            $type  = strtolower((string)($override["identifier_type"] ?? ""));
            $value = normalizeIdentifier($override["identifier"] ?? "");
            if ($type === $candidate["type"] && $value === $candidate["value"]) {
                return ["key" => $key, "data" => $override];
            }
        }
    }

    return null;
}

function checksumDiffers($current, $target) {
    $current = strtolower(trim((string)$current));
    $target = strtolower(trim((string)$target));
    return $target !== "" && $current !== $target;
}

// ================================
// DEFAULT RESPONSE – NO UPDATE
// ================================
$response = [
    "firmware" => [
        "name"       => $rawBoardType ?: "unknown",
        "version"    => $curVer,
        "url"        => "",
        "assets_url" => "",
        "size"       => 0,
        "force"      => 0,
        "enable"     => 0
    ],
    "assets" => [
        "version" => "",
        "checksum" => "",
        "url" => "",
        "size" => 0,
        "force" => 0,
        "enable" => 0
    ]
];

// ================================
// OTA DECISION BY CONFIG
// ================================
$deviceOverride = findDeviceOverride($deviceOverrides, $boardType, $uuid, $macAddr);
if ($deviceOverride) {
    $overrideKey  = (string)($deviceOverride["key"] ?? "");
    $overrideData = (array)($deviceOverride["data"] ?? []);
    $targetVer    = (string)($overrideData["version"] ?? "");
    $issuedAt     = trim((string)($overrideData["issued_at"] ?? ""));

    // One-shot cycle behavior:
    // - Enable override => server must issue one forced OTA even if version is the same.
    // - After OTA has been issued in this cycle, when device reports target version, auto-disable.
    if ($overrideKey !== "" && $targetVer !== "" && $issuedAt !== "" && $curVer === $targetVer) {
        if (isset($deviceOverrides[$overrideKey]) && is_array($deviceOverrides[$overrideKey])) {
            $deviceOverrides[$overrideKey]["enable"] = 0;
            $deviceOverrides[$overrideKey]["status"] = "updated";
            $deviceOverrides[$overrideKey]["updated_at"] = date("Y-m-d H:i:s");
            saveDeviceOverrides($deviceOverridePath, $deviceOverrides);
        }
    } else {
        $fileRel = (string)($overrideData["file"] ?? "");
        $fwPath  = $fwBasePath . $fileRel;

        if ($fileRel && file_exists($fwPath)) {
            if ($overrideKey !== "" && isset($deviceOverrides[$overrideKey]) && is_array($deviceOverrides[$overrideKey]) && $issuedAt === "") {
                $deviceOverrides[$overrideKey]["issued_at"] = date("Y-m-d H:i:s");
                $deviceOverrides[$overrideKey]["status"] = "active";
                saveDeviceOverrides($deviceOverridePath, $deviceOverrides);
            }

            $response["firmware"]["name"]    = $rawBoardType;
            $response["firmware"]["version"] = (string)($overrideData["version"] ?? $curVer);
            $response["firmware"]["url"]     = $fwBaseUrl . $fileRel;
            $response["firmware"]["size"]    = filesize($fwPath);
            $response["firmware"]["force"]   = 1;
            $response["firmware"]["enable"]  = 1;
        }
    }
} elseif ($boardType && isset($config[$boardType]) && is_array($config[$boardType])) {

    $cfg = $config[$boardType];

    // admin.php đang lưu: model, version, file, size, date, notes, force, enable
    $newVer = (string)($cfg["version"] ?? ($cfg["latest"] ?? "0.0.0")); // fallback nếu config cũ
    $fileRel = (string)($cfg["file"] ?? "");
    $enable  = (int)($cfg["enable"] ?? 0);
    $force   = (int)($cfg["force"] ?? 0);

    // luôn phản hồi enable/force theo config để thiết bị nhìn thấy trạng thái
    $response["firmware"]["name"]   = $rawBoardType;
    $response["firmware"]["force"]  = $force;
    $response["firmware"]["enable"] = $enable;

    // Nếu bị disable -> trả url rỗng (không update)
    if ($enable === 1) {
        $fwPath = $fwBasePath . $fileRel;

        // Nếu file tồn tại và version mới hơn (hoặc force=1) -> cho update
        if ($fileRel && file_exists($fwPath) && (isNewer($curVer, $newVer) || $force === 1)) {
            $response["firmware"]["version"] = $newVer;
            $response["firmware"]["url"]     = $fwBaseUrl . $fileRel;
            $response["firmware"]["size"]    = filesize($fwPath);
        } else {
            // enable=1 nhưng không có update -> giữ url rỗng
            $response["firmware"]["version"] = $curVer;
            $response["firmware"]["url"]     = "";
            $response["firmware"]["size"]    = 0;
        }
    }
}

$assetOverride = findDeviceOverride($assetOverrides, $boardType, $uuid, $macAddr);
if ($assetOverride) {
    $overrideKey  = (string)($assetOverride["key"] ?? "");
    $overrideData = (array)($assetOverride["data"] ?? []);
    $issuedAt = trim((string)($overrideData["issued_at"] ?? ""));
    $fileRel = (string)($overrideData["file"] ?? "");
    $assetPath = $assetBasePath . $fileRel;
    $targetChecksum = strtolower(trim((string)($overrideData["checksum"] ?? "")));
    if (strlen($targetChecksum) !== 8) {
        $targetChecksum = readAssetPackageChecksum($assetPath);
    }

    if ($overrideKey !== "" && $targetChecksum !== "" && $issuedAt !== "" && $curAssetChecksum === $targetChecksum) {
        if (isset($assetOverrides[$overrideKey]) && is_array($assetOverrides[$overrideKey])) {
            $assetOverrides[$overrideKey]["enable"] = 0;
            $assetOverrides[$overrideKey]["status"] = "updated";
            $assetOverrides[$overrideKey]["updated_at"] = date("Y-m-d H:i:s");
            saveDeviceOverrides($assetOverridePath, $assetOverrides);
        }
    } else {
        if ($fileRel && file_exists($assetPath)) {
            if ($overrideKey !== "" && isset($assetOverrides[$overrideKey]) && is_array($assetOverrides[$overrideKey]) && $issuedAt === "") {
                $assetOverrides[$overrideKey]["issued_at"] = date("Y-m-d H:i:s");
                $assetOverrides[$overrideKey]["status"] = "active";
                saveDeviceOverrides($assetOverridePath, $assetOverrides);
            }

            $response["assets"]["version"] = (string)($overrideData["version"] ?? "");
            $response["assets"]["checksum"] = $targetChecksum;
            $response["assets"]["url"] = $assetBaseUrl . $fileRel;
            $response["assets"]["size"] = filesize($assetPath);
            $response["assets"]["force"] = 1;
            $response["assets"]["enable"] = 1;
            $response["firmware"]["assets_url"] = $response["assets"]["url"];
        }
    }
} elseif ($boardType && isset($assetConfig[$boardType]) && is_array($assetConfig[$boardType])) {
    $cfg = $assetConfig[$boardType];
    $fileRel = (string)($cfg["file"] ?? "");
    $enable = (int)($cfg["enable"] ?? 0);
    $assetPath = $assetBasePath . $fileRel;
    $targetChecksum = strtolower(trim((string)($cfg["checksum"] ?? "")));
    if (strlen($targetChecksum) !== 8) {
        $targetChecksum = readAssetPackageChecksum($assetPath);
    }

    $response["assets"]["version"] = (string)($cfg["version"] ?? "");
    $response["assets"]["checksum"] = $targetChecksum;
    $response["assets"]["enable"] = $enable;

    if ($enable === 1) {
        if ($fileRel && file_exists($assetPath) && checksumDiffers($curAssetChecksum, $targetChecksum)) {
            $response["assets"]["url"] = $assetBaseUrl . $fileRel;
            $response["assets"]["size"] = filesize($assetPath);
            $response["firmware"]["assets_url"] = $response["assets"]["url"];
        } else {
            $response["assets"]["url"] = "";
            $response["assets"]["size"] = 0;
        }
    }
}

$response["debug"] = [
    "macAddr" => $macAddr,
    "uuid" => $uuid,
    "boardType" => $boardType,
    "rawBoardType" => $rawBoardType,
    "curVer" => $curVer,
    "override_matched" => $deviceOverride ? $deviceOverride["key"] : "no",
    "override_file" => $deviceOverride ? ($deviceOverride["data"]["file"] ?? "") : "",
    "override_file_exists" => $deviceOverride ? (file_exists($fwBasePath . ($deviceOverride["data"]["file"] ?? "")) ? "yes" : "no") : "n/a",
    "fwPath_checked" => $deviceOverride ? ($fwBasePath . ($deviceOverride["data"]["file"] ?? "")) : ($fwBasePath . ($config[$boardType]["file"] ?? "")),
    "config_file_exists" => (isset($config[$boardType]) && !empty($config[$boardType]["file"])) ? (file_exists($fwBasePath . $config[$boardType]["file"]) ? "yes" : "no") : "n/a"
];

echo json_encode($response, JSON_UNESCAPED_SLASHES);
