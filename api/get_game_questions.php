<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/question_status_common.php';

$questions = question_status_load_questions();
if (!is_array($questions)) $questions = [];

$published = [];
foreach ($questions as $q) {
    if (!is_array($q)) continue;
    $id = trim((string)($q['id'] ?? ''));
    if ($id === '') continue;
    $status = question_status_get($id);
    if ($status === 'draft' || $status === 'disabled') {
        continue;
    }
    $published[] = $q;
}

echo json_encode([
    'ok' => true,
    'questions' => $published,
    'total' => count($questions),
    'published_total' => count($published),
    'filtered_total' => max(0, count($questions) - count($published)),
    'generated_at' => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
?>
