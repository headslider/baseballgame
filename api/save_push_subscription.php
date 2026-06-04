<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/feature_common.php';

function push_json($code, $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') push_json(405, ['ok'=>false,'message'=>'method not allowed']);
$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) push_json(400, ['ok'=>false,'message'=>'invalid json']);

$player_id = normalize_player_id($data['player_id'] ?? '');
$client_token = normalize_client_token($data['client_token'] ?? '');
$subscription = $data['subscription'] ?? null;

if ($player_id === '' || strlen($client_token) < 16 || !is_array($subscription)) {
    push_json(400, ['ok'=>false,'message'=>'プレイヤーID、端末情報、通知購読情報が必要です。']);
}
if (!verify_player_client($player_id, $client_token)) {
    push_json(403, ['ok'=>false,'message'=>'プレイヤーIDまたは端末情報を確認できませんでした。']);
}
$endpoint = $subscription['endpoint'] ?? '';
$keys = $subscription['keys'] ?? [];
if ($endpoint === '' || !is_array($keys) || empty($keys['p256dh']) || empty($keys['auth'])) {
    push_json(400, ['ok'=>false,'message'=>'通知購読情報の形式が正しくありません。']);
}

$dir = feature_scores_dir();
$file = $dir . '/push_subscriptions.json';
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$fp = fopen($file, 'c+');
if (!$fp) push_json(500, ['ok'=>false,'message'=>'通知購読情報を保存できません。']);
if (!flock($fp, LOCK_EX)) { fclose($fp); push_json(500, ['ok'=>false,'message'=>'通知購読情報をロックできません。']); }
$rawExisting = stream_get_contents($fp);
$db = $rawExisting ? json_decode($rawExisting, true) : null;
if (!is_array($db)) $db = ['subscriptions'=>[]];

$client_hash = hash_short($client_token);
$now = date('Y-m-d H:i:s');
$found = false;
foreach ($db['subscriptions'] as &$rec) {
    if (($rec['endpoint'] ?? '') === $endpoint) {
        $rec = [
            'player_id'=>$player_id,
            'client_hash'=>$client_hash,
            'endpoint'=>$endpoint,
            'keys'=>['p256dh'=>$keys['p256dh'], 'auth'=>$keys['auth']],
            'user_agent'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
            'updated_at'=>$now,
            'created_at'=>$rec['created_at'] ?? $now,
            'status'=>'active'
        ];
        $found = true;
        break;
    }
}
unset($rec);
if (!$found) {
    $db['subscriptions'][] = [
        'player_id'=>$player_id,
        'client_hash'=>$client_hash,
        'endpoint'=>$endpoint,
        'keys'=>['p256dh'=>$keys['p256dh'], 'auth'=>$keys['auth']],
        'user_agent'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
        'created_at'=>$now,
        'updated_at'=>$now,
        'status'=>'active'
    ];
}
rewind($fp);
ftruncate($fp, 0);
fwrite($fp, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

access_log_event([
    'event'=>'push_subscribe',
    'page'=>'settings',
    'player_id'=>$player_id,
    'client_hash'=>$client_hash,
    'extra'=>'endpoint=' . substr(hash('sha256', $endpoint), 0, 12)
]);

push_json(200, ['ok'=>true,'message'=>'通知購読情報を保存しました。']);
?>
