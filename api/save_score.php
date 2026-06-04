<?php
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
require_once __DIR__ . '/feature_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 1024 * 1024) {
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

function clamp_int($value, $min, $max) {
    $n = intval($value);
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}


$player_id = normalize_player_id($data['player_id'] ?? '');
$client_token = normalize_client_token($data['client_token'] ?? '');
if ($player_id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'player_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!verify_player_client($player_id, $client_token)) {
    audit_log_id_event([
        'action'=>'save_score',
        'result'=>'forbidden',
        'player_id'=>$player_id,
        'id_type'=>'score',
        'client_hash'=>hash_short($client_token),
        'message'=>'player client verification failed for score save'
    ]);
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden','message'=>'プレイヤーIDの確認ができませんでした。トップ画面で一度ログアウトして、同じプレイヤーIDで再ログインしてからお試しください。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (is_player_suspended($player_id)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'player_suspended','message'=>'このプレイヤーIDは現在ゲーム利用停止中です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (player_has_feature($player_id, 'admin_mode')) {
    audit_log_id_event([
        'action'=>'save_score',
        'result'=>'rejected_admin',
        'player_id'=>$player_id,
        'id_type'=>'admin',
        'message'=>'admin_mode user score rejected server-side'
    ]);
    echo json_encode(['ok' => false, 'adminMode' => true, 'error' => 'admin score rejected'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$allowed_positions = ['P','C','1B','2B','SS','3B','LF','CF','RF','BASIC'];
$position = preg_replace('/[^A-Z0-9]/', '', $data['position'] ?? '');
if (!in_array($position, $allowed_positions, true)) {
    $position = '';
}

$grade = clamp_int($data['grade'] ?? 0, 0, 6);
$total_score = clamp_int($data['total_score'] ?? 0, 0, 54);
$attack_score = clamp_int($data['attack_score'] ?? 0, 0, 27);
$defense_score = clamp_int($data['defense_score'] ?? 0, 0, 27);
$max_score = clamp_int($data['max_score'] ?? 54, 0, 54);

$logs = $data['logs'] ?? [];
if (!is_array($logs)) {
    $logs = [];
}
if (count($logs) > 18) {
    $logs = array_slice($logs, 0, 18);
}
$logs_json = json_encode($logs, JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
if ($logs_json === false || strlen($logs_json) > 200000) {
    $logs_json = '[]';
}

$dir = __DIR__ . '/../scores';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot create score directory'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $dir . '/score_log.csv';
$is_new = !file_exists($file);
$fp = fopen($file, 'a');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot open score file'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot lock score file'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ok = true;
if ($is_new) {
    $ok = $ok && fputcsv($fp, ['played_at','player_id','grade','position','total_score','attack_score','defense_score','max_score','logs_json']) !== false;
}
$ok = $ok && fputcsv($fp, [
    date('Y-m-d H:i:s'),
    safe_csv_cell($player_id),
    $grade,
    $position,
    $total_score,
    $attack_score,
    $defense_score,
    $max_score,
    $logs_json
]) !== false;

flock($fp, LOCK_UN);
fclose($fp);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot write score file'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
?>
