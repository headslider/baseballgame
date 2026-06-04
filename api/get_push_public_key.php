<?php
require_once __DIR__ . '/feature_common.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/push_config.php';
if (!defined('PUSH_VAPID_PUBLIC_KEY') || PUSH_VAPID_PUBLIC_KEY === '' || strpos(PUSH_VAPID_PUBLIC_KEY, 'REPLACE_') === 0) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'プッシュ通知用の公開鍵が設定されていません。'], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['ok'=>true,'publicKey'=>PUSH_VAPID_PUBLIC_KEY], JSON_UNESCAPED_UNICODE);
?>
