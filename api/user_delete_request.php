<?php
/**
 * プレイヤー削除要求API
 * ユーザーがアカウント削除を要求する際に使用
 */

header('Content-Type: application/json; charset=utf-8');

// リクエスト方法チェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

// パラメータ取得
$player_id = isset($_POST['player_id']) ? trim($_POST['player_id']) : '';
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
$confirm = isset($_POST['confirm']) ? intval($_POST['confirm']) : 0;

// バリデーション
if (empty($player_id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'player_id required']);
    exit;
}

if ($confirm !== 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'confirmation required']);
    exit;
}

// 削除要求ディレクトリ
$request_dir = __DIR__ . '/../requests/delete_requests/';
if (!is_dir($request_dir)) {
    mkdir($request_dir, 0755, true);
}

// 削除要求ファイル作成
$timestamp = date('YmdHis');
$request_id = $player_id . '_' . $timestamp;
$request_file = $request_dir . $request_id . '.json';

$request_data = [
    'request_id' => $request_id,
    'player_id' => $player_id,
    'reason' => $reason,
    'requested_at' => date('c'),
    'status' => 'pending',
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
];

if (file_put_contents($request_file, json_encode($request_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
    // メール送信（オプション）
    $player_email = isset($_POST['email']) ? trim($_POST['email']) : '';
    if ($player_email) {
        require_once __DIR__ . '/send_deletion_confirmation_email.php';
        send_deletion_confirmation_email($player_id, $request_id, $player_email);
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Delete request submitted successfully',
        'request_id' => $request_id,
        'next_step' => 'Management team will review your request within 3 business days'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save request']);
}
