<?php
/**
 * プレイヤー削除API（2段階認証）
 * セッション不要、client_token ベース認証で ID 変更対応
 *
 * セキュリティ対応:
 * - client_token で認証（register_player.php と同じ検証方式）
 * - POST player_id を削除対象（セッション不依存で ID 変更後も削除可能）
 * - トークンハッシュ検証（hash_equals タイミング攻撃対策）
 * - 8つのデータファイルから完全削除（quiz_master, push登録含む）
 * - 全ファイル操作を排他ロック（flock）
 * - 削除ログを監査証跡として記録
 */

require_once __DIR__ . '/feature_common.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$delete_token = isset($_POST['token']) ? trim($_POST['token']) : '';
$player_id = isset($_POST['player_id']) ? trim($_POST['player_id']) : '';
$client_token = isset($_POST['client_token']) ? trim($_POST['client_token']) : '';
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

// 【フェーズ1】トークン生成（token なし）
if (empty($delete_token)) {
    if (empty($player_id) || empty($client_token)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'player_id and client_token required']);
        exit;
    }

    // client_token で認証（register_player と同じ方式）
    if (!verify_player_client($player_id, $client_token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid player_id or client_token']);
        exit;
    }

    // プレイヤー存在確認
    $player_registry_file = __DIR__ . '/../scores/player_registry.csv';
    $player_exists = false;
    if (file_exists($player_registry_file)) {
        if (($handle = fopen($player_registry_file, 'r')) !== false) {
            if (flock($handle, LOCK_SH)) {
                while (($row = fgetcsv($handle)) !== false) {
                    if (isset($row[0]) && $row[0] === $player_id) {
                        $player_exists = true;
                        break;
                    }
                }
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    if (!$player_exists) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Player not found']);
        exit;
    }

    // トークン生成
    $new_token = bin2hex(random_bytes(16));
    $token_hash = hash('sha256', $new_token);
    $token_expires_at = date('c', time() + 30 * 24 * 60 * 60);

    $token_dir = __DIR__ . '/../requests/delete_tokens/';
    if (!is_dir($token_dir)) {
        mkdir($token_dir, 0755, true);
    }

    $token_file = $token_dir . $player_id . '.json';
    $token_data = [
        'player_id' => $player_id,
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

// 【フェーズ2】削除実行（token あり）
if (empty($player_id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'player_id required']);
    exit;
}

// トークン検証
$token_dir = __DIR__ . '/../requests/delete_tokens/';
$token_file = $token_dir . $player_id . '.json';

if (!file_exists($token_file)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid or expired token']);
    exit;
}

$token_data = json_decode(file_get_contents($token_file), true);
if (!is_array($token_data)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid token file']);
    exit;
}

// トークンハッシュ検証
$provided_token_hash = hash('sha256', $delete_token);
if (!hash_equals($token_data['token_hash'] ?? '', $provided_token_hash)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

// 有効期限確認
if (strtotime($token_data['expires_at']) < time()) {
    http_response_code(401);
    unlink($token_file);
    echo json_encode(['ok' => false, 'error' => 'Token expired']);
    exit;
}

// 削除内容の記録
$deletion_summary = [
    'player_id' => $player_id,
    'deleted_at' => date('c'),
    'executed_by' => 'user_request',
    'deleted_files_and_records' => []
];

// 【削除対象1】quiz_master_scores.json（scores配列をフィルタリング、totals削除）
$quiz_master_scores_file = __DIR__ . '/../scores/quiz_master_scores.json';
if (file_exists($quiz_master_scores_file)) {
    if (($handle = fopen($quiz_master_scores_file, 'r+')) !== false) {
        if (flock($handle, LOCK_EX)) {
            $content = stream_get_contents($handle);
            $db = $content ? json_decode($content, true) : [];
            if (!is_array($db)) $db = [];

            $deleted_from_scores = 0;
            if (isset($db['scores']) && is_array($db['scores'])) {
                $db['scores'] = array_values(array_filter($db['scores'], function($score) use ($player_id, &$deleted_from_scores) {
                    if (isset($score['player_id']) && $score['player_id'] === $player_id) {
                        $deleted_from_scores++;
                        return false;
                    }
                    return true;
                }));
            }

            if (isset($db['totals'][$player_id])) {
                unset($db['totals'][$player_id]);
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($handle);
            flock($handle, LOCK_UN);

            if ($deleted_from_scores > 0 || isset($db['totals'])) {
                $deletion_summary['deleted_files_and_records'][] = [
                    'file' => 'scores/quiz_master_scores.json',
                    'action' => 'removed quiz master scores and totals',
                    'scores_removed' => $deleted_from_scores
                ];
            }
        }
        fclose($handle);
    }
}

// 【削除対象2】player_registry.csv（CSVヘッダーを正しく処理）
$player_registry_file = __DIR__ . '/../scores/player_registry.csv';
if (file_exists($player_registry_file)) {
    if (($handle = fopen($player_registry_file, 'r+')) !== false) {
        if (flock($handle, LOCK_EX)) {
            $rows = [];
            $header = null;
            $found = false;

            rewind($handle);
            while (($row = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = $row;
                } elseif (isset($row[0]) && $row[0] === $player_id) {
                    $found = true;
                } else {
                    $rows[] = $row;
                }
            }

            if ($found) {
                rewind($handle);
                ftruncate($handle, 0);
                if ($header) fputcsv($handle, $header);
                foreach ($rows as $row) {
                    fputcsv($handle, $row);
                }
                fflush($handle);

                $deletion_summary['deleted_files_and_records'][] = [
                    'file' => 'scores/player_registry.csv',
                    'action' => 'removed player registration',
                    'count' => 1
                ];
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}

// 【削除対象3】score_log.csv
$score_log_file = __DIR__ . '/../scores/score_log.csv';
if (file_exists($score_log_file)) {
    if (($handle = fopen($score_log_file, 'r+')) !== false) {
        if (flock($handle, LOCK_EX)) {
            $rows = [];
            $header = null;
            $deleted_count = 0;

            rewind($handle);
            while (($row = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = $row;
                } elseif (isset($row[0]) && $row[0] === $player_id) {
                    $deleted_count++;
                } else {
                    $rows[] = $row;
                }
            }

            if ($deleted_count > 0) {
                rewind($handle);
                ftruncate($handle, 0);
                if ($header) fputcsv($handle, $header);
                foreach ($rows as $row) {
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
        }
        fclose($handle);
    }
}

// 【削除対象4】player_features.json（{"players": {"ID": {...}}} 形式）
$features_file = __DIR__ . '/../scores/player_features.json';
if (file_exists($features_file)) {
    if (($handle = fopen($features_file, 'r+')) !== false) {
        if (flock($handle, LOCK_EX)) {
            $content = stream_get_contents($handle);
            $db = $content ? json_decode($content, true) : [];
            if (!is_array($db)) $db = [];
            if (!isset($db['players'])) $db['players'] = [];

            if (isset($db['players'][$player_id])) {
                unset($db['players'][$player_id]);
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                fflush($handle);

                $deletion_summary['deleted_files_and_records'][] = [
                    'file' => 'scores/player_features.json',
                    'action' => 'removed feature unlock status',
                    'count' => 1
                ];
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}

// 【削除対象5】mistake_review.json（{"records": {"TRANSFER-ID": {player_id, ...}}} 形式）
// ⚠️ キーは転送IDのため array_values で再採番してはいけない（他ユーザーのキーが壊れる）
$mistake_review_file = __DIR__ . '/../scores/mistake_review.json';
if (file_exists($mistake_review_file)) {
    if (($handle = fopen($mistake_review_file, 'r+')) !== false) {
        if (flock($handle, LOCK_EX)) {
            $content = stream_get_contents($handle);
            $db = $content ? json_decode($content, true) : [];
            if (!is_array($db)) $db = [];
            if (!isset($db['records'])) $db['records'] = [];

            $deleted_count = 0;
            if (is_array($db['records'])) {
                // 転送IDをキーとする連想配列なので、該当レコードをアンセット（キー名は保持）
                foreach ($db['records'] as $transfer_id => &$record) {
                    if (is_array($record) && (($record['player_id'] ?? '') === $player_id)) {
                        unset($db['records'][$transfer_id]);
                        $deleted_count++;
                    }
                }
                unset($record);
            }

            if ($deleted_count > 0) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                fflush($handle);

                $deletion_summary['deleted_files_and_records'][] = [
                    'file' => 'scores/mistake_review.json',
                    'action' => 'removed mistake review history',
                    'count' => $deleted_count
                ];
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}

// 【削除対象6】push_subscriptions.json（{"PLAYER_ID": {...}} 形式）
$push_file = __DIR__ . '/../scores/push_subscriptions.json';
if (file_exists($push_file)) {
    if (($handle = fopen($push_file, 'r+')) !== false) {
        if (flock($handle, LOCK_EX)) {
            $content = stream_get_contents($handle);
            $subs = $content ? json_decode($content, true) : [];
            if (!is_array($subs)) $subs = [];

            if (isset($subs[$player_id])) {
                unset($subs[$player_id]);
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($subs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                fflush($handle);

                $deletion_summary['deleted_files_and_records'][] = [
                    'file' => 'scores/push_subscriptions.json',
                    'action' => 'removed push notification registration',
                    'count' => 1
                ];
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}

// 【削除対象7】access_log.csv（アクセス履歴、CSV形式）
$access_log_file = __DIR__ . '/../scores/access_log.csv';
if (file_exists($access_log_file)) {
    if (($handle = fopen($access_log_file, 'r+')) !== false) {
        if (flock($handle, LOCK_EX)) {
            $rows = [];
            $header = null;
            $deleted_count = 0;
            $player_id_index = -1;

            rewind($handle);
            // ヘッダーを読み、player_id カラムのインデックスを取得
            if (($header = fgetcsv($handle)) !== false && is_array($header)) {
                foreach ($header as $idx => $col) {
                    if ($col === 'player_id') {
                        $player_id_index = $idx;
                        break;
                    }
                }
            }

            // レコードをフィルタリング
            while (($row = fgetcsv($handle)) !== false) {
                if ($player_id_index >= 0 && isset($row[$player_id_index]) && $row[$player_id_index] === $player_id) {
                    $deleted_count++;
                } else {
                    $rows[] = $row;
                }
            }

            if ($deleted_count > 0) {
                rewind($handle);
                ftruncate($handle, 0);
                if ($header) fputcsv($handle, $header);
                foreach ($rows as $row) {
                    fputcsv($handle, $row);
                }
                fflush($handle);

                $deletion_summary['deleted_files_and_records'][] = [
                    'file' => 'scores/access_log.csv',
                    'action' => 'removed access log records',
                    'count' => $deleted_count
                ];
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}

// 【削除対象8】削除ログファイル保存
$delete_log_dir = __DIR__ . '/../requests/delete_logs/';
if (!is_dir($delete_log_dir)) {
    mkdir($delete_log_dir, 0755, true);
}

$delete_log_file = $delete_log_dir . $player_id . '_deleted_' . date('YmdHis') . '.json';
$delete_log = array_merge($deletion_summary, [
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'reason' => $reason
]);

if (($handle = fopen($delete_log_file, 'w')) !== false) {
    if (flock($handle, LOCK_EX)) {
        fwrite($handle, json_encode($delete_log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    fclose($handle);
}

// 削除トークンクリア
if (file_exists($token_file)) {
    unlink($token_file);
}

// セッション更新（存在する場合）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['baseballPlayerId']) && $_SESSION['baseballPlayerId'] === $player_id) {
    unset($_SESSION['baseballPlayerId']);
    session_destroy();
}

// 成功レスポンス
http_response_code(200);
echo json_encode([
    'ok' => true,
    'message' => 'Player account and all related data deleted successfully',
    'player_id' => $player_id,
    'deleted_at' => $delete_log['deleted_at'],
    'deletion_summary' => $deletion_summary['deleted_files_and_records']
], JSON_UNESCAPED_UNICODE);
