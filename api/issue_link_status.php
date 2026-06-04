<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/feature_common.php';

function issue_link_status_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}
function issue_link_status_settings_file() {
    return feature_scores_dir() . '/issue_link_settings.json';
}
function issue_link_status_load_settings() {
    $db = read_json_file_safe(issue_link_status_settings_file(), ['invite_key'=>'','admin_key'=>'','reuse_policy'=>'valid_until_key_changed']);
    return is_array($db) ? $db : ['invite_key'=>'','admin_key'=>'','reuse_policy'=>'valid_until_key_changed'];
}
function issue_link_status_normalize_key($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $v)) return '';
    return $v;
}
$type = ($_GET['type'] ?? '') === 'admin' ? 'admin' : 'invite';
$key = issue_link_status_normalize_key($_GET['key'] ?? '');
$settings = issue_link_status_load_settings();
$field = $type === 'admin' ? 'admin_key' : 'invite_key';
$expected = issue_link_status_normalize_key($settings[$field] ?? '');
$valid = ($expected !== '' && $key !== '' && hash_equals($expected, $key));
issue_link_status_json(200, [
    'ok' => true,
    'type' => $type,
    'valid' => $valid,
    'configured' => $expected !== '',
    'reuse_policy' => (string)($settings['reuse_policy'] ?? 'valid_until_key_changed')
]);
