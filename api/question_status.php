<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/question_status_common.php';
$db = question_status_read_db();
$out = [];
foreach (($db['statuses'] ?? []) as $id=>$status) {
    $status = question_status_valid($status);
    if ($status !== 'published') $out[$id] = $status;
}
echo json_encode(['ok'=>true,'statuses'=>$out], JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
?>
