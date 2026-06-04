<?php
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
require_once __DIR__ . '/feature_common.php';
require_once __DIR__ . '/request_notification_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) {
    $raw = '';
}
if (strlen($raw) > 1024 * 128) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid payload'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$content_type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$data = [];

// GETクエリはPOST本文がサーバー側で失われる環境へのフォールバックとして保持
if (is_array($_GET) && count($_GET) > 0) {
    $data = $_GET;
}

// fetch + FormData の場合は $_POST に入るため、GETより優先して統合
if (is_array($_POST) && count($_POST) > 0) {
    $data = array_merge($data, $_POST);
} elseif (stripos($content_type, 'application/json') !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $data = array_merge($data, $decoded);
} else {
    parse_str($raw, $parsed);
    if (is_array($parsed) && count($parsed) > 0) {
        $data = array_merge($data, $parsed);
    } else {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $data = array_merge($data, $decoded);
    }
}

if (!is_array($data)) {
    $data = [];
}

function clean_text($value, $max_len) {
    $s = trim((string)($value ?? ''));
    $s = preg_replace("/[\x00-\x08\x0B\x0C\x0E-\x1F]/u", '', $s);
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max_len, 'UTF-8');
    return substr($s, 0, $max_len * 3);
}


$allowed_grades = ['2','3','4','5','6','all'];
$allowed_positions = ['BASIC','P','C','1B','2B','SS','3B','LF','CF','RF','ALL'];
$allowed_types = ['add','delete'];

$player_id = normalize_player_id($data['player_id'] ?? '');
$grade = clean_text($data['grade'] ?? '', 12);
$position = clean_text($data['position'] ?? '', 12);
$type = clean_text($data['request_type'] ?? '', 12);
$title = clean_text($data['title'] ?? ($_POST['title'] ?? ($_GET['title'] ?? ($data['request_title'] ?? ($data['problem_title'] ?? '')))), 80);
$detail = clean_text($data['detail'] ?? ($_POST['detail'] ?? ($_GET['detail'] ?? ($data['request_detail'] ?? ($data['body'] ?? '')))), 3000);

if ($player_id === '') $player_id = 'ADMIN';
if (!in_array($grade, $allowed_grades, true)) $grade = 'all';
if (!in_array($position, $allowed_positions, true)) $position = 'ALL';
if (!in_array($type, $allowed_types, true)) $type = 'add';

if ($title === '' || $detail === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'title_and_detail_required',
        'message' => 'タイトルと詳細内容がサーバーに届いていません。ブラウザのキャッシュを削除するか、最新版app.js?v=218の読み込みとPHPのPOST/GET受信設定を確認してください。',
        'received_keys' => array_keys($data),
        'content_type' => $content_type
    ], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$candidate_dirs = [
    __DIR__ . '/../scores',
    __DIR__ . '/../requests',
    sys_get_temp_dir()
];

$dir = null;
$last_error = '';
foreach ($candidate_dirs as $candidate) {
    if (!is_dir($candidate)) {
        @mkdir($candidate, 0755, true);
    }
    if (is_dir($candidate) && is_writable($candidate)) {
        $dir = $candidate;
        break;
    }
    $last_error = $candidate . ' is not writable';
}

if ($dir === null) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'request_storage_not_writable',
        'message' => '要望データの保存先に書き込みできません。scores フォルダの所有者と書き込み権限を確認してください。',
        'detail' => $last_error
    ], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$file = $dir . '/request_log.csv';
$is_new = !file_exists($file);
$fp = @fopen($file, 'a');
if (!$fp) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'cannot_open_request_file',
        'message' => '要望データファイルを開けません。request_log.csv の書き込み権限を確認してください。',
        'detail' => $file
    ], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot lock request file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

if ($is_new || filesize($file) === 0) {
    fputcsv($fp, ['id','submitted_at','player_id','grade','position','request_type','title','detail','status','handled_at','handled_note']);
}

$rand = function_exists('random_bytes') ? substr(bin2hex(random_bytes(4)), 0, 8) : substr(md5(uniqid('', true)), 0, 8);
$id = date('YmdHis') . '-' . $rand;
$now = date('Y-m-d H:i:s');

fputcsv($fp, [
    $id,
    $now,
    safe_csv_cell($player_id),
    $grade,
    $position,
    $type,
    safe_csv_cell($title),
    safe_csv_cell($detail),
    '検討中',
    '',
    ''
]);

fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);



// 新規要望メール通知：保存成功後に通知する。通知失敗で要望投稿自体は失敗させない。
$email_settings = load_request_notification_settings();
$email_subject = normalize_request_notification_subject($email_settings['subject'] ?? '新規要望メール通知');
// v718: 実投稿時に「タイトルのみ」本文だとiOSメールで本文なしになる場合があったため、
// 17:51/19:43に正常表示されたv707相当の複数行プレインテキスト形式へ戻します。
$email_body = "新しい要望が登録されました。\n\n"
    . "要望ID: " . $id . "\n"
    . "登録日時: " . $now . "\n"
    . "送信プレイヤーID: " . $player_id . "\n"
    . "学年: " . $grade . "\n"
    . "ポジション: " . $position . "\n"
    . "分類: " . $type . "\n"
    . "タイトル: " . $title . "\n\n"
    . "詳細:\n" . $detail . "\n\n"
    . "最高位管理者画面の「要望管理」タブで内容を確認してください。\n";
$email_result = send_request_notification_email($email_subject, $email_body, $email_settings);
if (!empty($email_result['attempted'])) {
    audit_log_admin_event([
        'admin_label'=>$player_id,
        'action'=>'request_email_notice',
        'result'=>($email_result['failed'] ?? 0) > 0 ? 'partial_or_failed' : 'success',
        'target_type'=>'request',
        'message'=>'request_id=' . $id . ' / ' . ($email_result['message'] ?? '')
    ]);
}


audit_log_admin_event([
    'admin_label'=>$player_id,
    'action'=>'submit_request',
    'result'=>'success',
    'target_type'=>$type,
    'message'=>'request_id=' . $id . ' / grade=' . $grade . ' / position=' . $position
]);

echo json_encode([
    'ok' => true,
    'message' => '対応までしばしお待ちください',
    'request_id' => $id,
    'email_notice' => $email_result ?? ['attempted'=>false]
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
