<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/feature_common.php';

function self_issue_code_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}
function self_issue_code_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function issue_link_settings_file_self() {
    return feature_scores_dir() . '/issue_link_settings.json';
}
function load_issue_link_settings_self() {
    $db = read_json_file_safe(issue_link_settings_file_self(), ['invite_key'=>'','admin_key'=>'','reuse_policy'=>'valid_until_key_changed']);
    return is_array($db) ? $db : ['invite_key'=>'','admin_key'=>'','reuse_policy'=>'valid_until_key_changed'];
}
function normalize_issue_link_key_self($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $v)) return '';
    return $v;
}
function verify_issue_link_key_self($type, $provided) {
    $settings = load_issue_link_settings_self();
    $field = $type === 'admin' ? 'admin_key' : 'invite_key';
    $expected = normalize_issue_link_key_self($settings[$field] ?? '');
    $provided = normalize_issue_link_key_self($provided);
    if ($expected === '') {
        self_issue_code_json(403, ['ok'=>false,'error'=>'issue_key_not_configured','message'=>'このID取得URLはまだ管理画面で有効化されていません。管理者に確認してください。']);
    }
    if ($provided === '' || !hash_equals($expected, $provided)) {
        self_issue_code_json(403, ['ok'=>false,'error'=>'issue_key_invalid','message'=>'ID取得用のキーが一致しません。管理者から案内されたURLを開き直してください。']);
    }
}

function self_issue_make_code_for_type($type, $db) {
    $prefix = $type === 'admin' ? 'ADMIN' : 'INVITE';
    for ($i = 0; $i < 20; $i++) {
        $plain = $prefix . '-' . strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(bin2hex(random_bytes(3)));
        $hash = code_hash_for_type($type, $plain);
        if (!isset($db['codes'][$hash])) return [$plain, $hash];
    }
    return ['', ''];
}

$data = self_issue_code_input();
$type = ($data['type'] ?? '') === 'admin' ? 'admin' : 'invite';
$player_id = normalize_player_id($data['player_id'] ?? '');
$client_token = normalize_client_token($data['client_token'] ?? '');
$ack_admin_name_rule = !empty($data['ack_admin_name_rule']);
$issue_link_key = $data['issue_link_key'] ?? ($data['invite_key'] ?? ($data['admin_key'] ?? ''));
verify_issue_link_key_self($type, $issue_link_key);

if ($player_id === '' || strlen($client_token) < 16) {
    self_issue_code_json(400, ['ok'=>false,'error'=>'invalid_request','message'=>'プレイヤーIDの確認情報が不足しています。']);
}
if ($type === 'admin' && !$ack_admin_name_rule) {
    self_issue_code_json(400, ['ok'=>false,'error'=>'admin_name_rule_required','message'=>'管理者ID取得前に、名前をプレイヤーIDに反映する注意事項を確認してください。登録例) toriyabe-G']);
}
if (!verify_player_client($player_id, $client_token)) {
    audit_log_id_event([
        'action'=>'self_issue_' . $type,
        'result'=>'failed_verify',
        'player_id'=>$player_id,
        'id_type'=>$type,
        'message'=>'self issue code failed: verify_player_client'
    ]);
    self_issue_code_json(403, ['ok'=>false,'error'=>'player_verify_failed','message'=>'現在この端末でログインしている本人確認ができませんでした。']);
}

$db = load_code_db_by_type($type);
if (!isset($db['codes']) || !is_array($db['codes'])) $db['codes'] = [];
$canonical = canonical_player_id($player_id);
$now = date('Y-m-d H:i:s');
$id_label = $type === 'admin' ? '管理者ID' : '招待ID';

foreach ($db['codes'] as $hash=>$row) {
    if (!empty($row['self_issued']) && canonical_player_id($row['owner_player_id'] ?? '') === $canonical) {
        $code = (string)($row['plain_code'] ?? '');
        audit_log_id_event([
            'action'=>'self_issue_' . $type,
            'result'=>'already_issued',
            'player_id'=>$player_id,
            'id_type'=>$type,
            'code_hash_prefix'=>substr($hash, 0, 12),
            'message'=>'self issue code already exists'
        ]);
        self_issue_code_json(200, [
            'ok'=>true,
            'type'=>$type,
            'already_issued'=>true,
            'code'=>$code,
            'id_label'=>$id_label,
            'hash_prefix'=>substr($hash, 0, 12),
            'message'=>$code !== '' ? '取得済みの' . $id_label . 'を表示しました。' : '取得済みの' . $id_label . 'があります。管理者に確認してください。'
        ]);
    }
}

list($plain, $hash) = self_issue_make_code_for_type($type, $db);
if ($plain === '' || $hash === '') {
    self_issue_code_json(500, ['ok'=>false,'error'=>'code_generation_failed','message'=>$id_label . 'を生成できませんでした。']);
}
$db['codes'][$hash] = [
    'enabled'=>true,
    'features'=>default_features_for_code_type($type),
    'label'=>($type === 'admin' ? '本人取得管理者: ' : '本人取得招待: ') . $player_id,
    'max_uses'=>1,
    'used_by'=>[],
    'created_at'=>$now,
    'created_by'=>'self:' . $player_id,
    'self_issued'=>true,
    'self_issue_type'=>$type,
    'owner_player_id'=>$player_id,
    'issued_to_player_id'=>$player_id,
    'issued_at'=>$now,
    'plain_code'=>$plain
];
if ($type === 'admin') {
    $db['codes'][$hash]['admin_name_rule_acknowledged'] = true;
    $db['codes'][$hash]['admin_name_rule_notice'] = '管理者ID取得者は、自分の名前をプレイヤーIDに反映する必要があります。登録例) toriyabe-G。後日、名前がコーチ・監督として確認できない場合は機能を停止する場合があります。';
}
if (!write_json_file_locked(code_db_file_by_type($type), $db)) {
    self_issue_code_json(500, ['ok'=>false,'error'=>'save_failed','message'=>$id_label . 'を保存できませんでした。']);
}
audit_log_id_event([
    'action'=>'self_issue_' . $type,
    'result'=>'success',
    'player_id'=>$player_id,
    'id_type'=>$type,
    'code_hash_prefix'=>substr($hash, 0, 12),
    'features'=>default_features_for_code_type($type),
    'message'=>'self issued ' . $type . ' id'
]);
self_issue_code_json(200, [
    'ok'=>true,
    'type'=>$type,
    'already_issued'=>false,
    'code'=>$plain,
    'id_label'=>$id_label,
    'hash_prefix'=>substr($hash, 0, 12),
    'message'=>$id_label . 'を取得しました。'
]);
