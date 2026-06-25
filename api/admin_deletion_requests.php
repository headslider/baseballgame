<?php
/**
 * 管理画面用：削除要求管理API
 */

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$admin_key = $_GET['admin_key'] ?? $_POST['admin_key'] ?? '';

// 管理者キー確認
$expected_admin_key = getenv('ADMIN_KEY') ?: '';
if (empty($expected_admin_key) || $admin_key !== $expected_admin_key) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$request_dir = __DIR__ . '/../requests/delete_requests/';

switch ($action) {
    case 'list':
        // 削除要求一覧取得
        list_deletion_requests();
        break;

    case 'get':
        // 削除要求詳細取得
        get_deletion_request();
        break;

    case 'approve':
        // 削除要求を承認・実行
        approve_deletion_request();
        break;

    case 'reject':
        // 削除要求を却下
        reject_deletion_request();
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        break;
}

function list_deletion_requests() {
    global $request_dir;

    if (!is_dir($request_dir)) {
        echo json_encode(['ok' => true, 'requests' => []]);
        return;
    }

    $requests = [];
    $files = glob($request_dir . '*.json');

    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $requests[] = $data;
        }
    }

    // 降順でソート（新しい順）
    usort($requests, function($a, $b) {
        return strtotime($b['requested_at']) - strtotime($a['requested_at']);
    });

    echo json_encode([
        'ok' => true,
        'total' => count($requests),
        'requests' => $requests
    ]);
}

function get_deletion_request() {
    global $request_dir;

    $request_id = $_GET['request_id'] ?? '';
    if (empty($request_id)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'request_id required']);
        return;
    }

    $file = $request_dir . $request_id . '.json';
    if (!file_exists($file)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Request not found']);
        return;
    }

    $data = json_decode(file_get_contents($file), true);
    echo json_encode(['ok' => true, 'request' => $data]);
}

function approve_deletion_request() {
    global $request_dir;

    $request_id = $_POST['request_id'] ?? '';
    $player_id = $_POST['player_id'] ?? '';

    if (empty($request_id) || empty($player_id)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'request_id and player_id required']);
        return;
    }

    // 削除実行
    execute_player_deletion($player_id);

    // 削除要求ファイルを完了状態に更新
    $file = $request_dir . $request_id . '.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $data['status'] = 'completed';
            $data['completed_at'] = date('c');
            file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Player account deleted successfully',
        'player_id' => $player_id
    ]);
}

function reject_deletion_request() {
    global $request_dir;

    $request_id = $_POST['request_id'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if (empty($request_id)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'request_id required']);
        return;
    }

    $file = $request_dir . $request_id . '.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $data['status'] = 'rejected';
            $data['rejected_at'] = date('c');
            $data['rejection_reason'] = $reason;
            file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Deletion request rejected'
    ]);
}

function execute_player_deletion($player_id) {
    // スコアファイルから削除
    $scores_file = __DIR__ . '/../scores/quiz_master_scores.json';
    if (file_exists($scores_file)) {
        $scores = json_decode(file_get_contents($scores_file), true) ?? [];
        if (isset($scores[$player_id])) {
            unset($scores[$player_id]);
            file_put_contents($scores_file, json_encode($scores, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    // 削除ログ保存
    $delete_log_dir = __DIR__ . '/../requests/delete_logs/';
    if (!is_dir($delete_log_dir)) {
        mkdir($delete_log_dir, 0755, true);
    }

    $delete_log = [
        'player_id' => $player_id,
        'deleted_at' => date('c'),
        'deleted_by_admin' => true,
        'admin_ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];

    $delete_log_file = $delete_log_dir . $player_id . '_deleted_' . date('YmdHis') . '.json';
    file_put_contents($delete_log_file, json_encode($delete_log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
