<?php
/**
 * Admin JSON export endpoint for 野球やろうぜ！ question data.
 *
 * IMPORTANT:
 * - Place this endpoint under the same Basic認証 / 管理者認証 scope as admin.html.
 * - It exports the canonical master JSON including stopped questions and semantic signatures.
 * - CSV export is still useful for spreadsheets, but JSON is the canonical format for future ChatGPT修正.
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);
$masterPath = $root . '/data/questions_admin_master.json';
$activePath = $root . '/data/questions.json';

// Optional lightweight shared-secret guard. If scores/admin_json_export_token.txt exists,
// require ?token=... or X-Admin-Export-Token header to match. This prevents accidental public export.
$tokenPath = $root . '/scores/admin_json_export_token.txt';
if (is_file($tokenPath)) {
    $expected = trim((string)file_get_contents($tokenPath));
    $given = isset($_GET['token']) ? (string)$_GET['token'] : '';
    if ($given === '' && isset($_SERVER['HTTP_X_ADMIN_EXPORT_TOKEN'])) {
        $given = (string)$_SERVER['HTTP_X_ADMIN_EXPORT_TOKEN'];
    }
    if ($expected !== '' && !hash_equals($expected, $given)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok'=>false,'error'=>'forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$mode = isset($_GET['mode']) ? (string)$_GET['mode'] : 'master';
$download = !isset($_GET['inline']);

if ($mode === 'active') {
    $path = $activePath;
    $filename = 'questions_active_' . date('Ymd_His') . '.json';
} else {
    $path = is_file($masterPath) ? $masterPath : $activePath;
    $filename = 'questions_admin_master_' . date('Ymd_His') . '.json';
}

if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>'export source not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
if ($download) {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}
readfile($path);
