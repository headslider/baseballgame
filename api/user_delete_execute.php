<?php
/**
 * プレイヤー削除実行API（管理者用）
 * プレイヤーアカウントとすべての関連データを削除
 *
 * セキュリティ対応:
 * - 管理者キー認証（ADMIN_DELETE_KEY）
 * - 削除対象データの詳細ログ記録
 * - 削除ファイル・レコード数を監査ログに記録
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

// 管理者キー認証
$expected_admin_key = getenv('ADMIN_DELETE_KEY');
if (empty($expected_admin_key)) {
    // 環境変数が未設定の場合、テスト環境での簡易キーを使用
    $expected_admin_key = 'test-admin-key-12345';
}

if (empty($admin_key) || $admin_key !== $expected_admin_key) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized. Valid admin key required.']);
    exit;
}

// 削除実行管理者を記録
$executed_by = 'api_key';

// 削除内容の記録（詳細化）
$deletion_summary = [
    'player_id' => $player_id,
    'deleted_at' => date('c'),
    'executed_by' => $executed_by,
    'deleted_files_and_records' => []
];

// 削除対象 1：quiz_master_scores.json から削除
$scores_file = __DIR__ . '/../scores/quiz_master_scores.json';
if (file_exists($scores_file)) {
    $scores = json_decode(file_get_contents($scores_file), true) ?? [];
    if (isset($scores[$player_id])) {
        unset($scores[$player_id]);
        file_put_contents($scores_file, json_encode($scores, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $deletion_summary['deleted_files_and_records'][] = [
            'file' => 'scores/quiz_master_scores.json',
            'action' => 'removed player score record',
            'count' => 1
        ];
    }
}

// 削除対象 2：player_registry.csv から削除
$player_registry_file = __DIR__ . '/../scores/player_registry.csv';
if (file_exists($player_registry_file)) {
    $registry_rows = [];
    $found = false;
    if (($handle = fopen($player_registry_file, 'r')) !== false) {
        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[0]) && $row[0] === $player_id) {
                $found = true; // このレコードは削除
            } else {
                $registry_rows[] = $row;
            }
        }
        fclose($handle);
    }
    if ($found && ($handle = fopen($player_registry_file, 'w')) !== false) {
        foreach ($registry_rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        $deletion_summary['deleted_files_and_records'][] = [
            'file' => 'scores/player_registry.csv',
            'action' => 'removed player registration',
            'count' => 1
        ];
    }
}

// 削除対象 3：score_log.csv から削除
$score_log_file = __DIR__ . '/../scores/score_log.csv';
if (file_exists($score_log_file)) {
    $log_rows = [];
    $deleted_count = 0;
    if (($handle = fopen($score_log_file, 'r')) !== false) {
        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[0]) && $row[0] === $player_id) {
                $deleted_count++; // このレコードは削除
            } else {
                $log_rows[] = $row;
            }
        }
        fclose($handle);
    }
    if ($deleted_count > 0 && ($handle = fopen($score_log_file, 'w')) !== false) {
        foreach ($log_rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        $deletion_summary['deleted_files_and_records'][] = [
            'file' => 'scores/score_log.csv',
            'action' => 'removed score log records',
            'count' => $deleted_count
        ];
    }
}

// 削除対象 4：player_features.json から削除
$features_file = __DIR__ . '/../scores/player_features.json';
if (file_exists($features_file)) {
    $features = json_decode(file_get_contents($features_file), true) ?? [];
    if (isset($features[$player_id])) {
        unset($features[$player_id]);
        file_put_contents($features_file, json_encode($features, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $deletion_summary['deleted_files_and_records'][] = [
            'file' => 'scores/player_features.json',
            'action' => 'removed feature unlock status',
            'count' => 1
        ];
    }
}

// 削除対象 5：mistake_review.json から削除
$mistake_review_file = __DIR__ . '/../scores/mistake_review.json';
if (file_exists($mistake_review_file)) {
    $mistakes = json_decode(file_get_contents($mistake_review_file), true) ?? [];
    if (isset($mistakes[$player_id])) {
        unset($mistakes[$player_id]);
        file_put_contents($mistake_review_file, json_encode($mistakes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $deletion_summary['deleted_files_and_records'][] = [
            'file' => 'scores/mistake_review.json',
            'action' => 'removed mistake review history',
            'count' => 1
        ];
    }
}

// 削除ログファイル保存（詳細）
$delete_log_dir = __DIR__ . '/../requests/delete_logs/';
if (!is_dir($delete_log_dir)) {
    mkdir($delete_log_dir, 0755, true);
}

$delete_log_file = $delete_log_dir . $player_id . '_deleted_' . date('YmdHis') . '.json';

// 削除ログに詳細情報を記録
$delete_log = array_merge($deletion_summary, [
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
]);

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
    'deleted_at' => $delete_log['deleted_at'],
    'deletion_summary' => $deletion_summary['deleted_files_and_records'],
    'log_file' => basename($delete_log_file)
]);
