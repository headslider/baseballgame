<?php
require_once __DIR__ . '/feature_common.php';
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method not allowed'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) $raw = '';
$data = [];
if (is_array($_GET) && count($_GET)>0) $data = $_GET;
if (is_array($_POST) && count($_POST)>0) {
    $data = array_merge($data, $_POST);
} else {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $data = array_merge($data, $decoded);
}
function normalize_transfer_id($value) {
    return strtoupper(preg_replace('/[^A-Z0-9\-]/', '', strtoupper($value ?? '')));
}

$transfer_id = normalize_transfer_id($data['transfer_id'] ?? '');
$player_id = normalize_player_id($data['player_id'] ?? '');
$client_token = normalize_client_token($data['client_token'] ?? '');
if (!verify_player_client($player_id, $client_token) || !player_has_feature($player_id, 'mistake_review')) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'feature_locked','message'=>'間違いプレイチェック機能は招待IDで解放すると利用できます。'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
$file = __DIR__ . '/../scores/mistake_review.json';
if (!is_file($file)) {
    echo json_encode(['ok'=>true,'transfer_id'=>$transfer_id,'mistakes'=>['items'=>[]]], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
$fp = fopen($file, 'r');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'cannot open mistake file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
if (!flock($fp, LOCK_SH)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'cannot lock mistake file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
$rawdb = stream_get_contents($fp);
flock($fp, LOCK_UN);
fclose($fp);
$db = $rawdb ? json_decode($rawdb, true) : null;
if (!is_array($db) || !isset($db['records']) || !is_array($db['records'])) {
    echo json_encode(['ok'=>true,'transfer_id'=>$transfer_id,'mistakes'=>['items'=>[]]], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$record = null;
if ($transfer_id !== '' && isset($db['records'][$transfer_id])) {
    $record = $db['records'][$transfer_id];
} elseif ($player_id !== '' && strlen($client_token) >= 16) {
    $hash = hash('sha256', $client_token);
    foreach ($db['records'] as $r) {
        if (($r['player_id'] ?? '') === $player_id && hash_equals($r['client_hash'] ?? '', $hash)) {
            if ($record === null || strcmp($r['updated_at'] ?? '', $record['updated_at'] ?? '') > 0) {
                $record = $r;
            }
        }
    }
}
if ($record === null) {
    echo json_encode(['ok'=>true,'transfer_id'=>$transfer_id,'mistakes'=>['items'=>[]]], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

echo json_encode([
    'ok'=>true,
    'transfer_id'=>$record['transfer_id'] ?? $transfer_id,
    'player_id'=>$record['player_id'] ?? '',
    'updated_at'=>$record['updated_at'] ?? '',
    'mistakes'=>$record['mistakes'] ?? ['items'=>[]],
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
