<?php
/**
 * プレイヤー削除要求API
 * ユーザーがアカウント削除を要求する際に使用
 *
 * セキュリティ対応:
 * - パスハッシュ確認（本人確認用）
 * - プレイヤーレジストリから登録済みハッシュと照合
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
$password_hash = isset($_POST['password_hash']) ? trim($_POST['password_hash']) : '';
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
$confirm = isset($_POST['confirm']) ? intval($_POST['confirm']) : 0;

// バリデーション
if (empty($player_id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'player_id required']);
    exit;
}

if (empty($password_hash)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'password_hash required']);
    exit;
}

if ($confirm !== 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'confirmation required']);
    exit;
}

// パスハッシュ確認：プレイヤーレジストリから登録済みハッシュを取得して照合
$player_registry_file = __DIR__ . '/../scores/player_registry.csv';
if (!file_exists($player_registry_file)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Player registry not found']);
    exit;
}

$registry_data = [];
if (($handle = fopen($player_registry_file, 'r')) !== false) {
    while (($row = fgetcsv($handle, 0, ',', '"')) !== false) {
        if (isset($row[0]) && $row[0] === $player_id) {
            $registry_data = $row;
            break;
        }
    }
    fclose($handle);
}

if (empty($registry_data)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Player not found']);
    exit;
}

// パスハッシュの検証（CSV フォーマット: [0]player_id, [1]client_hash, [2]created_at, [3]last_login_at）
$stored_hash = isset($registry_data[1]) ? $registry_data[1] : ''; // 位置 1: client_hash
if (empty($stored_hash) || !hash_equals($stored_hash, $password_hash)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid password. Deletion cancelled.']);
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

// 削除対象データの明確化：以下のデータが削除されることを申請に記載
$deletion_targets = [
    'quiz_master_scores.json から該当プレイヤーのスコアレコード',
    '本人確認完了後、管理者が以下を追加削除予定：',
    '- player_registry.csv からのプレイヤー登録情報',
    '- score_log.csv からの全スコアログ',
    '- player_features.json からの機能解放状態',
    '- mistake_review.json からの間違いプレイ履歴'
];

$request_data = [
    'request_id' => $request_id,
    'player_id' => $player_id,
    'reason' => $reason,
    'requested_at' => date('c'),
    'status' => 'pending',
    'deletion_targets' => $deletion_targets,
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
