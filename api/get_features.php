<?php
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
require_once __DIR__ . '/feature_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}
$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) $data = [];
$player_id = normalize_player_id($data['player_id'] ?? '');
$client_token = normalize_client_token($data['client_token'] ?? '');

if (!verify_player_client($player_id, $client_token)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden','message'=>'プレイヤーIDの確認ができませんでした。トップ画面で一度ログアウトして、同じプレイヤーIDで再ログインしてからお試しください。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$flags = feature_flags_for_player($player_id);
$sources = function_exists('feature_sources_for_player') ? feature_sources_for_player($player_id) : [];
if (function_exists('player_has_quiz_master_access') && player_has_quiz_master_access($player_id)) {
    $flags['quiz_master'] = true;
}
echo json_encode([
    'ok'=>true,
    'player_id'=>$player_id,
    'flags'=>$flags,
    'sources'=>$sources,
    'restricted_features'=>all_restricted_features(),
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
