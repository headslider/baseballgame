<?php
require_once __DIR__ . '/quiz_master_titles_common.php';
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method not allowed'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$payload = quiz_master_load_titles_payload();
echo json_encode([
    'ok'=>true,
    'titles'=>$payload['titles'] ?? quiz_master_default_titles(),
    'updated_at'=>(string)($payload['updated_at'] ?? '')
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
