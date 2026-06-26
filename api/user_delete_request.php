<?php
/**
 * プレイヤー削除要求API
 * ユーザーがアカウント削除を要求する際に使用
 *
 * セキュリティ対応:
 * - 削除トークンを生成し、クライアント端末に保存させる
 * - トークンベースの本人確認
 * - プレイヤーID存在確認のみ行う
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

// バリデーション
if (empty($player_id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'player_id required']);
    exit;
}

// プレイヤー存在確認：プレイヤーレジストリから player_id の存在を確認
$player_registry_file = __DIR__ . '/../scores/player_registry.csv';
if (!file_exists($player_registry_file)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Player registry not found']);
    exit;
}

$player_exists = false;
if (($handle = fopen($player_registry_file, 'r')) !== false) {
    while (($row = fgetcsv($handle, 0, ',', '"')) !== false) {
        if (isset($row[0]) && $row[0] === $player_id) {
            $player_exists = true;
            break;
        }
    }
    fclose($handle);
}

if (!$player_exists) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Player not found']);
    exit;
}

// 削除トークン生成（32文字のランダム文字列）
$delete_token = bin2hex(random_bytes(16)); // 32文字

// 削除要求ディレクトリ
$request_dir = __DIR__ . '/../requests/delete_requests/';
if (!is_dir($request_dir)) {
    mkdir($request_dir, 0755, true);
}

// 削除要求ファイル名：player_id のみ（トークンはクライアント保存）
$request_file = $request_dir . $player_id . '.json';

// 削除対象データの明確化
$deletion_targets = [
    'quiz_master_scores.json から該当プレイヤーのスコアレコード',
    '管理者による削除実行時に以下を削除予定：',
    '- player_registry.csv からのプレイヤー登録情報',
    '- score_log.csv からの全スコアログ',
    '- player_features.json からの機能解放状態',
    '- mistake_review.json からの間違いプレイ履歴'
];

// トークン有効期限：30日
$token_expires_at = date('c', time() + 30 * 24 * 60 * 60);

$request_data = [
    'player_id' => $player_id,
    'token' => $delete_token,
    'token_expires_at' => $token_expires_at,
    'reason' => $reason,
    'requested_at' => date('c'),
    'status' => 'pending',
    'deletion_targets' => $deletion_targets,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
];

if (file_put_contents($request_file, json_encode($request_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Delete request submitted successfully',
        'token' => $delete_token,
        'token_expires_at' => $token_expires_at,
        'instruction' => 'Save this token on your device to execute the deletion. The token will be valid for 30 days.'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save request']);
}
