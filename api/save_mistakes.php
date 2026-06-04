<?php
require_once __DIR__ . '/feature_common.php';
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method not allowed'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid payload'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid json'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

function normalize_transfer_id($value) {
    return strtoupper(preg_replace('/[^A-Z0-9\-]/', '', strtoupper($value ?? '')));
}
function new_transfer_id() {
    if (function_exists('random_bytes')) {
        $hex = strtoupper(bin2hex(random_bytes(5)));
    } else {
        $hex = strtoupper(substr(md5(uniqid('', true)), 0, 10));
    }
    return 'YKY-' . substr($hex,0,4) . '-' . substr($hex,4,4) . '-' . substr($hex,8,2);
}
function compact_mistakes($mistakes) {
    if (!is_array($mistakes)) return ['items'=>[]];
    $items = [];
    $src = isset($mistakes['items']) && is_array($mistakes['items']) ? $mistakes['items'] : [];
    $i = 0;
    foreach ($src as $key=>$item) {
        if (!is_array($item)) continue;
        if ($i >= 300) break;
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$key);
        if ($safeKey === '') $safeKey = 'Q' . $i;
        $items[$safeKey] = [
            'questionId' => substr((string)($item['questionId'] ?? $safeKey), 0, 40),
            'type' => substr((string)($item['type'] ?? ''), 0, 20),
            'grade' => intval($item['grade'] ?? 0),
            'position' => substr((string)($item['position'] ?? ''), 0, 10),
            'inning' => substr((string)($item['inning'] ?? ''), 0, 20),
            'outs' => intval($item['outs'] ?? 0),
            'stage' => substr((string)($item['stage'] ?? ''), 0, 20),
            'title' => substr((string)($item['title'] ?? ''), 0, 300),
            'situation' => substr((string)($item['situation'] ?? ''), 0, 700),
            'prompt' => substr((string)($item['prompt'] ?? ''), 0, 500),
            'selectedText' => substr((string)($item['selectedText'] ?? ''), 0, 500),
            'correctText' => substr((string)($item['correctText'] ?? ''), 0, 500),
            'lastScore' => intval($item['lastScore'] ?? 0),
            'missCount' => max(0, intval($item['missCount'] ?? 0)),
            'tryCount' => max(0, intval($item['tryCount'] ?? 0)),
            'mastered' => !empty($item['mastered']),
            'firstMissedAt' => substr((string)($item['firstMissedAt'] ?? ''), 0, 40),
            'lastMissedAt' => substr((string)($item['lastMissedAt'] ?? ''), 0, 40),
            'lastAnsweredAt' => substr((string)($item['lastAnsweredAt'] ?? ''), 0, 40),
            'tags' => array_values(array_slice(array_map('strval', is_array($item['tags'] ?? null) ? $item['tags'] : []), 0, 8)),
            'advice' => substr((string)($item['advice'] ?? ''), 0, 1000),
        ];
        $i++;
    }
    return ['items'=>$items];
}

$player_id = normalize_player_id($data['player_id'] ?? '');
$client_token = normalize_client_token($data['client_token'] ?? '');
$transfer_id = normalize_transfer_id($data['transfer_id'] ?? '');
if ($player_id === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'player_id required'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
if (strlen($client_token) < 16) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'client_token required'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
if (!verify_player_client($player_id, $client_token) || !player_has_feature($player_id, 'mistake_review')) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'feature_locked','message'=>'間違いプレイチェック機能は招待IDで解放すると利用できます。'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
if ($transfer_id === '') $transfer_id = new_transfer_id();

$mistakes = compact_mistakes($data['mistakes'] ?? ['items'=>[]]);
$dir = __DIR__ . '/../scores';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'cannot create score directory'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
$file = $dir . '/mistake_review.json';
$fp = fopen($file, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'cannot open mistake file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'cannot lock mistake file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
rewind($fp);
$existing = stream_get_contents($fp);
$db = $existing ? json_decode($existing, true) : null;
if (!is_array($db)) $db = ['records'=>[]];
if (!isset($db['records']) || !is_array($db['records'])) $db['records'] = [];

$db['records'][$transfer_id] = [
    'transfer_id' => $transfer_id,
    'player_id' => $player_id,
    'client_hash' => hash('sha256', $client_token),
    'updated_at' => date('Y-m-d H:i:s'),
    'mistakes' => $mistakes,
];

$json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
rewind($fp);
ftruncate($fp, 0);
$ok = fwrite($fp, $json) !== false;
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'cannot write mistake file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

echo json_encode(['ok'=>true,'transfer_id'=>$transfer_id,'item_count'=>count($mistakes['items'])], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
