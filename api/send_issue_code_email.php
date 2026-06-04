<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/feature_common.php';

function issue_email_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}
function issue_email_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
function issue_email_clean_subject($subject) {
    $subject = trim((string)$subject);
    $subject = str_replace(["\r", "\n"], '', $subject);
    if ($subject === '') $subject = '野球やろうぜ！ ID保管メール';
    if (function_exists('mb_substr')) return mb_substr($subject, 0, 120, 'UTF-8');
    return substr($subject, 0, 120);
}
function issue_email_mime_subject($subject) {
    // CORESERVER/qmail/iOS Mail互換: 件名はv707系と同じUTF-8 MIMEエンコード。
    // 改行はPHP mail()に渡す前提でLFを使い、CRCRLF化による本文なし表示を避ける。
    $subject = issue_email_clean_subject($subject);
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\n");
    }
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}
function issue_email_normalize_email($email) {
    $email = trim((string)$email);
    $email = str_replace(["\r", "\n"], '', $email);
    if (function_exists('mb_substr')) $email = mb_substr($email, 0, 254, 'UTF-8');
    else $email = substr($email, 0, 254);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}
function issue_email_normalize_code($code) {
    $code = trim((string)$code);
    $code = str_replace(["\r", "\n", "\t"], '', $code);
    if (!preg_match('/^[A-Za-z0-9_-]{6,80}$/', $code)) return '';
    return $code;
}

$data = issue_email_input();
$type = ($data['type'] ?? '') === 'admin' ? 'admin' : 'invite';
$email = issue_email_normalize_email($data['email'] ?? '');
$code = issue_email_normalize_code($data['code'] ?? '');
$player_id = normalize_player_id($data['player_id'] ?? '');
$label = $type === 'admin' ? '管理者ID' : '招待ID';

if ($email === '') {
    issue_email_json(400, ['ok'=>false, 'error'=>'invalid_email', 'message'=>'メールアドレスの形式が正しくありません。']);
}
if ($code === '') {
    issue_email_json(400, ['ok'=>false, 'error'=>'invalid_code', 'message'=>$label . 'を確認できませんでした。先にIDを取得してください。']);
}
if (!function_exists('mail')) {
    issue_email_json(500, ['ok'=>false, 'error'=>'mail_unavailable', 'message'=>'サーバーでメール送信機能を利用できません。']);
}

$subject = issue_email_mime_subject('野球やろうぜ！ ' . $label . '保管メール');
$body_lines = [];
$body_lines[] = '野球やろうぜ！の' . $label . '保管メールです。';
$body_lines[] = '';
$body_lines[] = $label . ': ' . $code;
if ($player_id !== '') $body_lines[] = 'プレイヤーID: ' . $player_id;
$body_lines[] = '';
$body_lines[] = 'このメールアドレスはID送信のみに使用し、サーバーには保存しません。';
$body_lines[] = 'IDを無くさないように、このメール、スクリーンショット、またはコピーで保管してください。';
$body_lines[] = '';
$body_lines[] = 'このメールは送信専用です。';
$body_lines[] = '野球やろうぜ！';

// v785: CORESERVER/qmailで「このメッセージには本文がありません」と表示される問題への対策。
// 過去の要望メール通知修正と同じく、短い1行本文ではなく複数行プレーンテキストにし、
// PHP mail()へ渡すヘッダー・本文の改行はLFに統一する。メールアドレスは保存しない。
$body = implode("\n", $body_lines) . "\n";

$headers = [];
$headers[] = 'From: 野球やろうぜ！ <no-reply@realemotionfactory.com>';
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'Content-Transfer-Encoding: 8bit';
$header_text = implode("\n", $headers);

$ok = @mail($email, $subject, $body, $header_text);
if (!$ok) {
    audit_log_id_event([
        'action'=>'send_issue_code_email',
        'result'=>'failed',
        'player_id'=>$player_id,
        'id_type'=>$type,
        'message'=>'issue code email send failed; recipient email is not stored'
    ]);
    issue_email_json(500, ['ok'=>false, 'error'=>'mail_failed', 'message'=>'メール送信に失敗しました。スクリーンショットまたはコピーで保管してください。']);
}
audit_log_id_event([
    'action'=>'send_issue_code_email',
    'result'=>'success',
    'player_id'=>$player_id,
    'id_type'=>$type,
    'message'=>'issue code email sent; recipient email is not stored'
]);
issue_email_json(200, ['ok'=>true, 'message'=>'メールを送信しました。メールアドレスはこちらで一切保管しません。']);
?>
