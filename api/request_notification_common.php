<?php
// 新規要望メール通知 共通処理
if (!function_exists('feature_scores_dir')) {
    require_once __DIR__ . '/feature_common.php';
}

if (!function_exists('request_notification_settings_file')) {
function request_notification_settings_file() {
    return feature_scores_dir() . '/request_notification_settings.json';
}
}

if (!function_exists('default_request_notification_settings')) {
function default_request_notification_settings() {
    return [
        'enabled' => false,
        'emails' => [],
        'from_email' => 'no-reply@realemotionfactory.com',
        'subject' => '新規要望メール通知',
        'updated_at' => '',
        'updated_by' => ''
    ];
}
}

if (!function_exists('normalize_request_notification_emails')) {
function normalize_request_notification_emails($value) {
    if (is_array($value)) {
        $parts = $value;
    } else {
        $raw = str_replace(["\r\n", "\r"], "\n", (string)$value);
        $parts = preg_split('/[\s,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    }
    $out = [];
    foreach ($parts as $part) {
        $email = trim((string)$part);
        if ($email === '') continue;
        if (function_exists('mb_substr')) $email = mb_substr($email, 0, 254, 'UTF-8');
        else $email = substr($email, 0, 254);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $out, true)) {
            $out[] = $email;
        }
        if (count($out) >= 10) break;
    }
    return $out;
}
}

if (!function_exists('normalize_request_notification_from')) {
function normalize_request_notification_from($value) {
    $email = trim((string)$value);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'no-reply@realemotionfactory.com';
    }
    return $email;
}
}

if (!function_exists('normalize_request_notification_subject')) {
function normalize_request_notification_subject($value) {
    $subject = trim((string)$value);
    $subject = str_replace(["\r", "\n"], '', $subject);
    if ($subject === '') $subject = '新規要望メール通知';
    if (function_exists('mb_substr')) return mb_substr($subject, 0, 120, 'UTF-8');
    return substr($subject, 0, 120);
}
}

if (!function_exists('load_request_notification_settings')) {
function load_request_notification_settings() {
    $default = default_request_notification_settings();
    $data = read_json_file_safe(request_notification_settings_file(), $default);
    if (!is_array($data)) $data = [];
    $settings = array_merge($default, $data);
    $settings['enabled'] = !empty($settings['enabled']);
    $settings['emails'] = normalize_request_notification_emails($settings['emails'] ?? []);
    $settings['from_email'] = normalize_request_notification_from($settings['from_email'] ?? '');
    $settings['subject'] = normalize_request_notification_subject($settings['subject'] ?? '');
    return $settings;
}
}

if (!function_exists('save_request_notification_settings')) {
function save_request_notification_settings($settings) {
    $default = default_request_notification_settings();
    $data = array_merge($default, is_array($settings) ? $settings : []);
    $data['enabled'] = !empty($data['enabled']);
    $data['emails'] = normalize_request_notification_emails($data['emails'] ?? []);
    $data['from_email'] = normalize_request_notification_from($data['from_email'] ?? '');
    $data['subject'] = normalize_request_notification_subject($data['subject'] ?? '');
    $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');
    return write_json_file_locked(request_notification_settings_file(), $data);
}
}

if (!function_exists('request_notification_mime_subject')) {
function request_notification_mime_subject($subject) {
    // v717: iOSメールで正常表示されていたv707相当の件名エンコードへ戻します。
    $subject = normalize_request_notification_subject($subject ?: '新規要望メール通知');
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}
}

if (!function_exists('send_request_notification_email')) {
function send_request_notification_email($subject, $body, $settings = null) {
    $settings = is_array($settings) ? $settings : load_request_notification_settings();
    $emails = normalize_request_notification_emails($settings['emails'] ?? []);
    $enabled = !empty($settings['enabled']);
    if (!$enabled) {
        return ['attempted'=>false,'sent'=>0,'failed'=>0,'message'=>'新規要望メール通知はOFFです。'];
    }
    if (!count($emails)) {
        return ['attempted'=>false,'sent'=>0,'failed'=>0,'message'=>'通知先メールアドレスが未設定です。'];
    }
    if (!function_exists('mail')) {
        return ['attempted'=>true,'sent'=>0,'failed'=>count($emails),'message'=>'PHP mail 関数が利用できません。'];
    }

    // v717: 17:51のiOSメールで本文・件名が正常表示されたv707相当の送信形式へ戻します。
    // 後続版で追加したbase64/quoted-printable/ISO-2022-JP/-f指定/ASCII From等は、
    // iOSメールで「本文なし」「フォーマット問題」と表示される原因になったため撤回します。
    $from = normalize_request_notification_from($settings['from_email'] ?? '');
    $headers = [];
    $headers[] = 'From: 野球やろうぜ！ <' . $from . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $header_text = implode("\r\n", $headers);
    $encoded_subject = request_notification_mime_subject($subject ?: ($settings['subject'] ?? '新規要望メール通知'));

    $sent = 0;
    $failed = 0;
    foreach ($emails as $email) {
        $ok = @mail($email, $encoded_subject, (string)$body, $header_text);
        if ($ok) $sent++; else $failed++;
    }
    return [
        'attempted'=>true,
        'sent'=>$sent,
        'failed'=>$failed,
        'message'=>'送信成功 ' . $sent . '件 / 失敗 ' . $failed . '件'
    ];
}
}
?>
