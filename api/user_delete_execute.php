<?php
/**
 * プレイヤー削除実行API（管理者用）
 * プレイヤーアカウントとすべての関連データを削除
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
$admin_key = isset($_POST['admin_key']) ? trim($_POST['admin_key']) : '';

// バリデーション
if (empty($player_id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'player_id required']);
    exit;
}

// 管理者キー確認（環境変数から取得想定）
$expected_admin_key = getenv('ADMIN_DELETE_KEY') ?: '';
if (empty($expected_admin_key) || $admin_key !== $expected_admin_key) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// 削除内容の記録
$delete_log = [
    'player_id' => $player_id,
    'deleted_at' => date('c'),
    'deleted_by_admin' => true,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
];

// スコアファイルから削除
$scores_file = __DIR__ . '/../scores/quiz_master_scores.json';
if (file_exists($scores_file)) {
    $scores = json_decode(file_get_contents($scores_file), true) ?? [];

    // プレイヤーデータを削除
    if (isset($scores[$player_id])) {
        unset($scores[$player_id]);
        file_put_contents($scores_file, json_encode($scores, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

// 削除ログファイル保存
$delete_log_dir = __DIR__ . '/../requests/delete_logs/';
if (!is_dir($delete_log_dir)) {
    mkdir($delete_log_dir, 0755, true);
}

$delete_log_file = $delete_log_dir . $player_id . '_deleted_' . date('YmdHis') . '.json';
file_put_contents($delete_log_file, json_encode($delete_log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// 削除要求ファイルを完了状態に更新
$request_dir = __DIR__ . '/../requests/delete_requests/';
if (is_dir($request_dir)) {
    $files = glob($request_dir . $player_id . '_*.json');
    foreach ($files as $file) {
        $request_data = json_decode(file_get_contents($file), true);
        if ($request_data) {
            $request_data['status'] = 'completed';
            $request_data['completed_at'] = date('c');
            file_put_contents($file, json_encode($request_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }
}

echo json_encode([
    'ok' => true,
    'message' => 'Player account and all related data deleted successfully',
    'player_id' => $player_id,
    'deleted_at' => $delete_log['deleted_at']
]);
