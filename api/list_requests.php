<?php
require_once __DIR__ . '/feature_common.php';
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$candidate_files = [
    __DIR__ . '/../scores/request_log.csv',
    __DIR__ . '/../requests/request_log.csv',
    sys_get_temp_dir() . '/request_log.csv'
];

$file = null;
foreach ($candidate_files as $candidate) {
    if (is_file($candidate)) {
        $file = $candidate;
        break;
    }
}

function normalize_request_status_public($status) {
    $status = trim((string)$status);
    if ($status === '' || $status === '未対応') return '検討中';
    if ($status === '対応済み') return '修正反映';
    $allowed = ['検討中','修正反映','対応不可','取消済み'];
    return in_array($status, $allowed, true) ? $status : '検討中';
}

$items = [];

if ($file !== null && is_file($file)) {
    $fp = fopen($file, 'r');
    if (!$fp) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'cannot open request file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
        exit;
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'cannot lock request file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
        exit;
    }

    $header = fgetcsv($fp);
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < 9) continue;
        $status = normalize_request_status_public($row[8] ?? '検討中');
        // 送信者が取り消した要望は、ユーザー側の要望一覧には表示しない。
        // 最高位管理者画面では監査・削除のため引き続き表示する。
        if ($status === '取消済み') {
            continue;
        }
        $items[] = [
            'id' => $row[0] ?? '',
            'submitted_at' => $row[1] ?? '',
            'player_id' => $row[2] ?? '',
            'grade' => $row[3] ?? '',
            'position' => $row[4] ?? '',
            'request_type' => $row[5] ?? '',
            'title' => $row[6] ?? '',
            'detail' => $row[7] ?? '',
            'status' => $status,
            'handled_at' => $row[9] ?? '',
            'handled_note' => $row[10] ?? '',
        ];
    }

    flock($fp, LOCK_UN);
    fclose($fp);
}

$items = array_reverse($items);
$items = array_slice($items, 0, 30);

echo json_encode([
    'ok' => true,
    'requests' => $items
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
