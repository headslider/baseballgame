<?php
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
require_once __DIR__ . '/feature_common.php';

function respond_redeem($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_redeem(405, ['ok'=>false,'error'=>'method not allowed']);
}
$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) $data = [];

$player_id = normalize_player_id($data['player_id'] ?? '');
$client_token = normalize_client_token($data['client_token'] ?? '');
$input_id = normalize_invite_id($data['invite_id'] ?? '');
$client_hash = hash_short($client_token);

if (!verify_player_client($player_id, $client_token)) {
    audit_log_id_event([
        'action'=>'redeem',
        'result'=>'forbidden',
        'player_id'=>$player_id,
        'id_type'=>'unknown',
        'code_hash_prefix'=>'',
        'client_hash'=>$client_hash,
        'message'=>'player client verification failed'
    ]);
    respond_redeem(403, ['ok'=>false,'error'=>'forbidden','message'=>'プレイヤーIDの確認ができませんでした。トップ画面で一度ログアウトして、同じプレイヤーIDで再ログインしてからお試しください。']);
}
if ($input_id === '') {
    audit_log_id_event([
        'action'=>'redeem',
        'result'=>'empty',
        'player_id'=>$player_id,
        'id_type'=>'unknown',
        'client_hash'=>$client_hash,
        'message'=>'empty id'
    ]);
    respond_redeem(400, ['ok'=>false,'error'=>'id required','message'=>'招待IDまたは管理者IDを入力してください。']);
}

$attempt = id_attempt_status($player_id, $input_id);
if (!empty($attempt['blocked'])) {
    audit_log_id_event([
        'action'=>'redeem',
        'result'=>'blocked',
        'player_id'=>$player_id,
        'id_type'=>'unknown',
        'code_hash_prefix'=>'',
        'client_hash'=>$client_hash,
        'message'=>'too many failed attempts'
    ]);
    respond_redeem(429, ['ok'=>false,'error'=>'too_many_attempts','message'=>'ID入力の失敗が続いたため、一時的に利用を停止しています。10分ほど待ってから再度お試しください。']);
}

$now = date('Y-m-d H:i:s');
$code_type = 'invite';
$features = [];
$code_db_file = '';
$code_db = null;
$code_key = '';

$admin_db = load_admin_db();
$admin_key = admin_hash_for_code($input_id);
if (isset($admin_db['codes'][$admin_key]) && !empty($admin_db['codes'][$admin_key]['enabled'])) {
    $code_type = 'admin';
    $code_db = $admin_db;
    $code_db_file = feature_scores_dir() . '/admin_codes.json';
    $code_key = $admin_key;
} else {
    $invite_db = load_invite_db();
    $invite_key = invite_hash_for_code($input_id);
    if (!isset($invite_db['codes'][$invite_key]) || empty($invite_db['codes'][$invite_key]['enabled'])) {
        record_id_attempt($player_id, $input_id, false);
        audit_log_id_event([
            'action'=>'redeem',
            'result'=>'invalid',
            'player_id'=>$player_id,
            'id_type'=>'unknown',
            'code_hash_prefix'=>substr(hash('sha256', $input_id),0,12),
            'client_hash'=>$client_hash,
            'message'=>'id not found or disabled'
        ]);
        respond_redeem(404, ['ok'=>false,'error'=>'invalid_id','message'=>'招待IDまたは管理者IDが見つかりません。']);
    }
    $code_type = 'invite';
    $code_db = $invite_db;
    $code_db_file = feature_scores_dir() . '/invite_codes.json';
    $code_key = $invite_key;
}

$code = $code_db['codes'][$code_key];
$features = isset($code['features']) && is_array($code['features']) ? $code['features'] : [];
$used_by = isset($code['used_by']) && is_array($code['used_by']) ? $code['used_by'] : [];
$max_uses = intval($code['max_uses'] ?? 0);
if ($max_uses > 0 && !isset($used_by[$player_id]) && count($used_by) >= $max_uses) {
    record_id_attempt($player_id, $input_id, false);
    audit_log_id_event([
        'action'=>'redeem',
        'result'=>'limit',
        'player_id'=>$player_id,
        'id_type'=>$code_type,
        'code_hash_prefix'=>substr($code_key,0,12),
        'features'=>$features,
        'client_hash'=>$client_hash,
        'message'=>'id usage limit reached'
    ]);
    respond_redeem(409, ['ok'=>false,'error'=>'id_limit','message'=>'このプレイヤーIDは利用上限に達しています。']);
}

$feature_db = load_player_feature_db();
if (!isset($feature_db['players'][$player_id])) {
    $feature_db['players'][$player_id] = ['flags'=>[], 'sources'=>[], 'updated_at'=>''];
}
if (!isset($feature_db['players'][$player_id]['flags']) || !is_array($feature_db['players'][$player_id]['flags'])) {
    $feature_db['players'][$player_id]['flags'] = [];
}
if (!isset($feature_db['players'][$player_id]['sources']) || !is_array($feature_db['players'][$player_id]['sources'])) {
    $feature_db['players'][$player_id]['sources'] = [];
}
foreach ($features as $feature) {
    $feature = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$feature);
    if ($feature === '') continue;
    $feature_db['players'][$player_id]['flags'][$feature] = true;
    $feature_db['players'][$player_id]['sources'][$feature] = [
        'type'=>$code_type,
        'id_hash_prefix'=>substr($code_key,0,12),
        'unlocked_at'=>$now
    ];
}
$feature_db['players'][$player_id]['updated_at'] = $now;
if (!save_player_feature_db($feature_db)) {
    audit_log_id_event([
        'action'=>'redeem',
        'result'=>'save_failed',
        'player_id'=>$player_id,
        'id_type'=>$code_type,
        'code_hash_prefix'=>substr($code_key,0,12),
        'features'=>$features,
        'client_hash'=>$client_hash,
        'message'=>'cannot save feature flags'
    ]);
    respond_redeem(500, ['ok'=>false,'error'=>'cannot save feature flags','message'=>'機能解放フラグを保存できませんでした。']);
}

$used_by[$player_id] = $now;
$code_db['codes'][$code_key]['used_by'] = $used_by;
write_json_file_locked($code_db_file, $code_db);
record_id_attempt($player_id, $input_id, true);
audit_log_id_event([
    'action'=>'redeem',
    'result'=>'success',
    'player_id'=>$player_id,
    'id_type'=>$code_type,
    'code_hash_prefix'=>substr($code_key,0,12),
    'features'=>$features,
    'client_hash'=>$client_hash,
    'message'=>'redeemed successfully'
]);

$flags = feature_flags_for_player($player_id);
echo json_encode([
    'ok'=>true,
    'player_id'=>$player_id,
    'id_type'=>$code_type,
    'unlocked_features'=>$features,
    'flags'=>$flags,
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
