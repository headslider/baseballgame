<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/feature_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}
$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) $data = [];

$event = preg_replace('/[^a-zA-Z0-9_\-]/', '', $data['event'] ?? 'page_view');
$page = substr(preg_replace('/[^a-zA-Z0-9_\-\/\.]/', '', $data['page'] ?? ''), 0, 80);
$player_id = normalize_player_id($data['player_id'] ?? '');
$client_token = normalize_client_token($data['client_token'] ?? '');
$grade = preg_replace('/[^0-9]/', '', (string)($data['grade'] ?? ''));
$position = preg_replace('/[^A-Z0-9]/', '', (string)($data['position'] ?? ''));
$extra = substr((string)($data['extra'] ?? ''), 0, 120);
$display_mode = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['display_mode'] ?? '')), 0, 24);
$device_type = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['device_type'] ?? '')), 0, 24);
$os = substr(preg_replace('/[^a-zA-Z0-9_\-\. ]/', '', (string)($data['os'] ?? '')), 0, 40);
$browser = substr(preg_replace('/[^a-zA-Z0-9_\-\. ]/', '', (string)($data['browser'] ?? '')), 0, 40);
$platform = substr(preg_replace('/[^a-zA-Z0-9_\-\. ]/', '', (string)($data['platform'] ?? '')), 0, 80);
$user_agent = substr((string)($data['user_agent'] ?? ''), 0, 240);

access_log_event([
    'event'=>$event ?: 'page_view',
    'page'=>$page,
    'player_id'=>$player_id,
    'grade'=>$grade,
    'position'=>$position,
    'client_hash'=>hash_short($client_token),
    'display_mode'=>$display_mode,
    'device_type'=>$device_type,
    'os'=>$os,
    'browser'=>$browser,
    'platform'=>$platform,
    'user_agent'=>$user_agent,
    'extra'=>$extra
]);

echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
?>
