<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/feature_common.php';

function public_notices_json($code, $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}

$file = feature_scores_dir() . '/push_notice_history.json';
$history = [];
if (is_file($file)) {
    $db = json_decode(file_get_contents($file), true);
    if (is_array($db) && is_array($db['history'] ?? null)) {
        $history = $db['history'];
    }
}

$out = [];
foreach ($history as $i => $row) {
    if (!is_array($row)) continue;
    $title = trim((string)($row['title'] ?? ''));
    $body = trim((string)($row['body'] ?? ''));
    if ($title === '' && $body === '') continue;

    // ユーザー側には管理者からのお知らせとして必要な内容だけを公開する。
    $out[] = [
        'id' => substr(hash('sha256', ($row['sent_at'] ?? '') . '|' . $title . '|' . $i), 0, 16),
        'title' => $title !== '' ? $title : 'お知らせ',
        'body' => $body,
        'sent_at' => (string)($row['sent_at'] ?? ''),
        'url' => (string)($row['url'] ?? './')
    ];
    if (count($out) >= 50) break;
}

public_notices_json(200, ['ok'=>true, 'notices'=>$out]);
?>
