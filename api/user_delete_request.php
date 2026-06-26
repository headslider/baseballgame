<?php
/**
 * プレイヤー削除API（1段階）
 * ユーザーの削除要求を受け付け、即座に削除を実行
 *
 * セキュリティ対応:
 * - セッション中のプレイヤーID確認（必須）
 * - POST player_id とセッション ID の一致確認（必須）
 * - 5つのデータファイルから完全削除
 * - 削除ログを監査証跡として記録
 */

header('Content-Type: application/json; charset=utf-8');

// リクエスト方法チェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

// セッション開始（既に開始されている場合はスキップ）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ログイン状態確認（必須）
if (!isset($_SESSION['baseballPlayerId']) || empty($_SESSION['baseballPlayerId'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$session_player_id = $_SESSION['baseballPlayerId'];

// 【フェーズ1】トークン生成リクエスト
// method が GET または POST に token がない場合 → トークン生成のみ
$delete_token = isset($_POST['token']) ? trim($_POST['token']) : '';

if (empty($delete_token)) {
    // トークン生成フェーズ
    $new_token = bin2hex(random_bytes(16)); // 32文字のランダムトークン
    $token_hash = hash('sha256', $new_token); // サーバー側ではハッシュを保存
    $token_expires_at = date('c', time() + 30 * 24 * 60 * 60); // 30日有効

    // トークン保存ディレクトリ
    $token_dir = __DIR__ . '/../requests/delete_tokens/';
    if (!is_dir($token_dir)) {
        mkdir($token_dir, 0755, true);
    }

    // トークンをサーバー側に保存（ハッシュ + 有効期限）
    $token_file = $token_dir . $session_player_id . '.json';
    $token_data = [
        'player_id' => $session_player_id,
        'token_hash' => $token_hash,
        'created_at' => date('c'),
        'expires_at' => $token_expires_at
    ];
    file_put_contents($token_file, json_encode($token_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Delete token generated',
        'token' => $new_token,
        'token_expires_at' => $token_expires_at
    ]);
    exit;
}

// 【フェーズ2】削除実行リクエスト
// token がある場合 → 削除実行

// パラメータ取得
$player_id = isset($_POST['player_id']) ? trim($_POST['player_id']) : '';
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

// バリデーション
if (empty($player_id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'player_id required']);
    exit;
}

// 本人確認：POST player_id とセッション player_id の一致確認（必須）
if ($player_id !== $session_player_id) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized: player_id mismatch']);
    exit;
}

// トークン検証：サーバー側で保存されたトークンと照合
$token_dir = __DIR__ . '/../requests/delete_tokens/';
$token_file = $token_dir . $player_id . '.json';

if (!file_exists($token_file)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid or expired token: no token on server']);
    exit;
}

$token_data = json_decode(file_get_contents($token_file), true);
if (!is_array($token_data)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid token file']);
    exit;
}

// トークンハッシュ比較（タイミング攻撃対策）
$provided_token_hash = hash('sha256', $delete_token);
if (!hash_equals($token_data['token_hash'] ?? '', $provided_token_hash)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid token: hash mismatch']);
    exit;
}

// トークン有効期限確認
if (strtotime($token_data['expires_at']) < time()) {
    http_response_code(401);
    unlink($token_file); // 期限切れトークンを削除
    echo json_encode(['ok' => false, 'error' => 'Token expired']);
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
    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Cannot lock player registry']);
        exit;
    }
    while (($row = fgetcsv($handle, 0, ',', '"')) !== false) {
        if (isset($row[0]) && $row[0] === $player_id) {
            $player_exists = true;
            break;
        }
    }
    flock($handle, LOCK_UN);
    fclose($handle);
}

if (!$player_exists) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Player not found']);
    exit;
}

// 削除内容の記録（詳細化）
$deletion_summary = [
    'player_id' => $player_id,
    'deleted_at' => date('c'),
    'executed_by' => 'user_request',
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
    if (($handle = fopen($player_registry_file, 'r+')) !== false) {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Cannot lock player registry']);
            exit;
        }
        rewind($handle);
        while (($row = fgetcsv($handle, 0, ',', '"')) !== false) {
            if (isset($row[0]) && $row[0] === $player_id) {
                $found = true;
            } else {
                $registry_rows[] = $row;
            }
        }
        if ($found) {
            rewind($handle);
            ftruncate($handle, 0);
            fputcsv($handle, ['player_id','client_hash','created_at','last_login_at']);
            foreach ($registry_rows as $row) {
                fputcsv($handle, $row);
            }
            fflush($handle);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        if ($found) {
            $deletion_summary['deleted_files_and_records'][] = [
                'file' => 'scores/player_registry.csv',
                'action' => 'removed player registration',
                'count' => 1
            ];
        }
    }
}

// 削除対象 3：score_log.csv から削除
$score_log_file = __DIR__ . '/../scores/score_log.csv';
if (file_exists($score_log_file)) {
    $log_rows = [];
    $deleted_count = 0;
    if (($handle = fopen($score_log_file, 'r+')) !== false) {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Cannot lock score log']);
            exit;
        }
        rewind($handle);
        while (($row = fgetcsv($handle, 0, ',', '"')) !== false) {
            if (isset($row[0]) && $row[0] === $player_id) {
                $deleted_count++;
            } else {
                $log_rows[] = $row;
            }
        }
        if ($deleted_count > 0) {
            rewind($handle);
            ftruncate($handle, 0);
            foreach ($log_rows as $row) {
                fputcsv($handle, $row);
            }
            fflush($handle);
            $deletion_summary['deleted_files_and_records'][] = [
                'file' => 'scores/score_log.csv',
                'action' => 'removed score log records',
                'count' => $deleted_count
            ];
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// 削除対象 4：player_features.json から削除
$features_file = __DIR__ . '/../scores/player_features.json';
if (file_exists($features_file)) {
    if (($handle = fopen($features_file, 'r+')) !== false) {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Cannot lock player features']);
            exit;
        }
        $content = file_get_contents($features_file);
        $features = $content ? json_decode($content, true) : [];
        if (!is_array($features)) $features = [];
        if (isset($features[$player_id])) {
            unset($features[$player_id]);
            file_put_contents($features_file, json_encode($features, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $deletion_summary['deleted_files_and_records'][] = [
                'file' => 'scores/player_features.json',
                'action' => 'removed feature unlock status',
                'count' => 1
            ];
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// 削除対象 5：mistake_review.json から削除
$mistake_review_file = __DIR__ . '/../scores/mistake_review.json';
if (file_exists($mistake_review_file)) {
    if (($handle = fopen($mistake_review_file, 'r+')) !== false) {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Cannot lock mistake review']);
            exit;
        }
        $content = file_get_contents($mistake_review_file);
        $mistakes = $content ? json_decode($content, true) : [];
        if (!is_array($mistakes)) $mistakes = [];
        if (isset($mistakes[$player_id])) {
            unset($mistakes[$player_id]);
            file_put_contents($mistake_review_file, json_encode($mistakes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $deletion_summary['deleted_files_and_records'][] = [
                'file' => 'scores/mistake_review.json',
                'action' => 'removed mistake review history',
                'count' => 1
            ];
        }
        flock($handle, LOCK_UN);
        fclose($handle);
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
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'reason' => $reason
]);

$log_handle = fopen($delete_log_file, 'w');
if ($log_handle && flock($log_handle, LOCK_EX)) {
    fwrite($log_handle, json_encode($delete_log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($log_handle);
    flock($log_handle, LOCK_UN);
    fclose($log_handle);
} else if ($log_handle) {
    fclose($log_handle);
}

// 削除トークンをクリア
if (file_exists($token_file)) {
    unlink($token_file);
}

// レスポンス
http_response_code(200);
echo json_encode([
    'ok' => true,
    'message' => 'Player account and all related data deleted successfully',
    'player_id' => $player_id,
    'deleted_at' => $delete_log['deleted_at'],
    'deletion_summary' => $deletion_summary['deleted_files_and_records']
]);
