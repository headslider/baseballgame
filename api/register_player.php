<?php
require_once __DIR__ . '/feature_common.php';
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 1024 * 64) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid json'], JSON_UNESCAPED_UNICODE);
    exit;
}

function respond($code, $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}


$player_id = normalize_player_id($data['player_id'] ?? '');
$id_error = validate_player_id_format($player_id);
if ($id_error !== '') respond(400, ['ok'=>false,'message'=>$id_error]);
$client_token = normalize_client_token($data['client_token'] ?? '');

if ($player_id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'player_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (strlen($client_token) < 16) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'client_token required'], JSON_UNESCAPED_UNICODE);
    exit;
}


function read_json_file_safe_local($file, $default) {
    if (!is_file($file)) return $default;
    $raw = file_get_contents($file);
    $json = $raw ? json_decode($raw, true) : null;
    return is_array($json) ? $json : $default;
}
function is_player_suspended_local($dir, $player_id) {
    $db = read_json_file_safe_local($dir . '/player_suspensions.json', ['players'=>[]]);
    return isset($db['players'][$player_id]) && (($db['players'][$player_id]['status'] ?? '') === 'suspended');
}

$token_hash = hash('sha256', $client_token);
$dir = __DIR__ . '/../scores';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot create score directory'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $dir . '/player_registry.csv';
$is_new = !file_exists($file);
$fp = fopen($file, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot open registry'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot lock registry'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = [];
rewind($fp);
$header = fgetcsv($fp);
while (($row = fgetcsv($fp)) !== false) {
    if (count($row) < 4) continue;
    $rows[] = [
        'player_id' => $row[0] ?? '',
        'client_hash' => $row[1] ?? '',
        'created_at' => $row[2] ?? '',
        'last_login_at' => $row[3] ?? '',
    ];
}

$now = date('Y-m-d H:i:s');
$found = false;
$conflict = false;

foreach ($rows as &$row) {
    if (canonical_player_id($row['player_id']) === canonical_player_id($player_id)) {
        $found = true;
        if (!hash_equals($row['client_hash'], $token_hash)) {
            $conflict = true;
        } else {
            $row['last_login_at'] = $now;
        }
        break;
    }
}
unset($row);

if ($conflict) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(409);
    echo json_encode([
        'ok' => false,
        'error' => 'duplicate_player_id',
        'message' => 'このプレイヤーIDは別の端末で既に使われています。別のプレイヤーIDを入力してください。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$found) {
    $rows[] = [
        'player_id' => $player_id,
        'client_hash' => $token_hash,
        'created_at' => $now,
        'last_login_at' => $now,
    ];
}

rewind($fp);
ftruncate($fp, 0);
fputcsv($fp, ['player_id','client_hash','created_at','last_login_at']);
foreach ($rows as $row) {
    fputcsv($fp, [
        safe_csv_cell($row['player_id']),
        $row['client_hash'],
        $row['created_at'],
        $row['last_login_at'],
    ]);
}
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode([
    'ok' => true,
    'player_id' => $player_id,
    'registered' => !$found,
], JSON_UNESCAPED_UNICODE);
?>
