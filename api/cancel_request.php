<?php
require_once __DIR__ . '/feature_common.php';
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) $raw = '';
$data = [];
$content_type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

if (is_array($_GET) && count($_GET) > 0) {
    $data = $_GET;
}
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

function clean_id($value) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $value ?? '');
}


$request_id = clean_id($data['id'] ?? ($data['request_id'] ?? ''));
$player_id = normalize_player_id($data['player_id'] ?? '');

if ($request_id === '' || $player_id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'id and player_id required'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
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

if ($file === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'request file not found'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$fp = fopen($file, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot open request file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot lock request file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

rewind($fp);
$header = fgetcsv($fp);
if (!$header) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'invalid request file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$rows = [];
$found = false;
$owner_mismatch = false;
$already_closed = false;
$now = date('Y-m-d H:i:s');

while (($row = fgetcsv($fp)) !== false) {
    while (count($row) < count($header)) $row[] = '';
    if (($row[0] ?? '') === $request_id) {
        $found = true;
        $row_player = preg_replace('/^\'/', '', $row[2] ?? '');
        if ($row_player !== $player_id) {
            $owner_mismatch = true;
        } elseif (!in_array(($row[8] ?? '検討中'), ['検討中', '未対応'], true)) {
            $already_closed = true;
        } else {
            $row[8] = '取消済み';
            $row[9] = $now;
            $row[10] = '送信者により取り消し';
        }
    }
    $rows[] = $row;
}

if (!$found) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'request not found'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

if ($owner_mismatch) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'not owner', 'message' => 'この要望は送信者本人のみ取り消せます。'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

if ($already_closed) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'already closed', 'message' => '検討中以外の要望は取り消せません。'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

rewind($fp);
ftruncate($fp, 0);
fputcsv($fp, $header);
foreach ($rows as $row) {
    if (isset($row[2])) $row[2] = safe_csv_cell($row[2]);
    if (isset($row[6])) $row[6] = safe_csv_cell($row[6]);
    if (isset($row[7])) $row[7] = safe_csv_cell($row[7]);
    fputcsv($fp, $row);
}
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok' => true, 'message' => '要望を取り消しました。'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
