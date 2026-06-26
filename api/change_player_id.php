<?php
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
require_once __DIR__ . '/feature_common.php';

function respond_change_id($code, $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}
function read_request_json_change_id() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
function change_id_backup_file($file) {
    if (!is_file($file)) return '';
    $dir = feature_scores_dir() . '/player_id_backups';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $base = basename($file);
    $backup = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $base);
    @copy($file, $backup);
    return $backup;
}
function write_json_file_locked_change_id($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    rewind($fp);
    ftruncate($fp, 0);
    $ok = fwrite($fp, $json) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}
function read_json_file_change_id($file, $default) {
    if (!is_file($file)) return $default;
    $raw = file_get_contents($file);
    $json = $raw ? json_decode($raw, true) : null;
    return is_array($json) ? $json : $default;
}
function migrate_json_players_key_change_id($file, $old_id, $new_id) {
    if (!is_file($file)) return 0;
    change_id_backup_file($file);
    $db = read_json_file_change_id($file, []);
    $changed = 0;
    if (isset($db['players']) && is_array($db['players']) && array_key_exists($old_id, $db['players'])) {
        if (!array_key_exists($new_id, $db['players'])) {
            $db['players'][$new_id] = $db['players'][$old_id];
        } elseif (is_array($db['players'][$new_id]) && is_array($db['players'][$old_id])) {
            $db['players'][$new_id] = array_replace_recursive($db['players'][$old_id], $db['players'][$new_id]);
        }
        unset($db['players'][$old_id]);
        $changed++;
    }
    if ($changed > 0) {
        if (!write_json_file_locked_change_id($file, $db)) respond_change_id(500, ['ok'=>false,'message'=>basename($file).' を更新できませんでした。']);
    }
    return $changed;
}
function migrate_mistake_review_change_id($file, $old_id, $new_id) {
    if (!is_file($file)) return 0;
    change_id_backup_file($file);
    $db = read_json_file_change_id($file, ['records'=>[]]);
    if (!isset($db['records']) || !is_array($db['records'])) return 0;
    $changed = 0;
    foreach ($db['records'] as &$r) {
        if (is_array($r) && (($r['player_id'] ?? '') === $old_id)) {
            $r['player_id'] = $new_id;
            $r['updated_at'] = date('Y-m-d H:i:s');
            $changed++;
        }
    }
    unset($r);
    if ($changed > 0) {
        if (!write_json_file_locked_change_id($file, $db)) respond_change_id(500, ['ok'=>false,'message'=>'mistake_review.json を更新できませんでした。']);
    }
    return $changed;
}
function migrate_quiz_master_scores_change_id($file, $old_id, $new_id) {
    if (!is_file($file)) return 0;
    change_id_backup_file($file);
    $db = read_json_file_change_id($file, ['version'=>1,'scores'=>[],'totals'=>[]]);
    if (!isset($db['scores']) || !is_array($db['scores'])) $db['scores'] = [];
    if (!isset($db['totals']) || !is_array($db['totals'])) $db['totals'] = [];

    $changed = 0;
    // scores 配列内の player_id を変更
    foreach ($db['scores'] as &$score) {
        if (is_array($score) && (($score['player_id'] ?? '') === $old_id)) {
            $score['player_id'] = $new_id;
            $changed++;
        }
    }
    unset($score);

    // totals オブジェクトを移行
    if (isset($db['totals'][$old_id])) {
        if (!isset($db['totals'][$new_id])) {
            $db['totals'][$new_id] = $db['totals'][$old_id];
        } elseif (is_array($db['totals'][$new_id]) && is_array($db['totals'][$old_id])) {
            $db['totals'][$new_id] = array_replace_recursive($db['totals'][$old_id], $db['totals'][$new_id]);
        }
        unset($db['totals'][$old_id]);
        $changed++;
    }

    if ($changed > 0) {
        if (!write_json_file_locked_change_id($file, $db)) respond_change_id(500, ['ok'=>false,'message'=>'quiz_master_scores.json を更新できませんでした。']);
    }
    return $changed;
}
function migrate_push_subscriptions_change_id($file, $old_id, $new_id) {
    if (!is_file($file)) return 0;
    change_id_backup_file($file);
    $db = read_json_file_change_id($file, []);
    if (!is_array($db)) $db = [];

    // push_subscriptions は { "PLAYER_ID": {...} } 形式
    if (isset($db[$old_id])) {
        if (!isset($db[$new_id])) {
            $db[$new_id] = $db[$old_id];
        } elseif (is_array($db[$new_id]) && is_array($db[$old_id])) {
            $db[$new_id] = array_replace_recursive($db[$old_id], $db[$new_id]);
        }
        unset($db[$old_id]);
        if (!write_json_file_locked_change_id($file, $db)) respond_change_id(500, ['ok'=>false,'message'=>'push_subscriptions.json を更新できませんでした。']);
        return 1;
    }
    return 0;
}
function migrate_csv_player_id_change_id($file, $old_id, $new_id, $columns) {
    if (!is_file($file)) return 0;
    change_id_backup_file($file);
    $fp = fopen($file, 'c+');
    if (!$fp) respond_change_id(500, ['ok'=>false,'message'=>basename($file).' を開けませんでした。']);
    if (!flock($fp, LOCK_EX)) { fclose($fp); respond_change_id(500, ['ok'=>false,'message'=>basename($file).' をロックできませんでした。']); }
    rewind($fp);
    $header = fgetcsv($fp);
    if (!is_array($header)) { flock($fp, LOCK_UN); fclose($fp); return 0; }
    $indexes = [];
    foreach ($header as $i=>$h) {
        if (in_array($h, $columns, true)) $indexes[] = $i;
    }
    $rows = [];
    $changed = 0;
    while (($row = fgetcsv($fp)) !== false) {
        foreach ($indexes as $idx) {
            if (($row[$idx] ?? '') === $old_id) {
                $row[$idx] = $new_id;
                $changed++;
            }
        }
        $rows[] = $row;
    }
    if ($changed > 0) {
        rewind($fp);
        ftruncate($fp, 0);
        fputcsv($fp, $header);
        foreach ($rows as $row) fputcsv($fp, $row);
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $changed;
}
function update_player_registry_change_id($file, $old_id, $new_id, $client_token) {
    $hash = hash('sha256', $client_token);
    $now = date('Y-m-d H:i:s');
    if (!is_file($file)) respond_change_id(404, ['ok'=>false,'message'=>'プレイヤー登録データが見つかりません。']);
    change_id_backup_file($file);
    $fp = fopen($file, 'c+');
    if (!$fp) respond_change_id(500, ['ok'=>false,'message'=>'プレイヤー登録データを開けませんでした。']);
    if (!flock($fp, LOCK_EX)) { fclose($fp); respond_change_id(500, ['ok'=>false,'message'=>'プレイヤー登録データをロックできませんでした。']); }
    rewind($fp);
    $header = fgetcsv($fp);
    $rows = [];
    $oldIndex = -1;
    $newExists = false;
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < 4) continue;
        $pid = $row[0] ?? '';
        if (canonical_player_id($pid) === canonical_player_id($new_id)) $newExists = true;
        if (canonical_player_id($pid) === canonical_player_id($old_id)) $oldIndex = count($rows);
        $rows[] = $row;
    }
    if ($newExists) {
        flock($fp, LOCK_UN); fclose($fp);
        respond_change_id(409, ['ok'=>false,'error'=>'duplicate_player_id','message'=>'このプレイヤーIDは既に使われています。別のプレイヤーIDを入力してください。']);
    }
    if ($oldIndex < 0) {
        flock($fp, LOCK_UN); fclose($fp);
        respond_change_id(404, ['ok'=>false,'message'=>'現在のプレイヤーIDが見つかりません。再ログインしてからお試しください。']);
    }
    if (!hash_equals($rows[$oldIndex][1] ?? '', $hash)) {
        flock($fp, LOCK_UN); fclose($fp);
        respond_change_id(403, ['ok'=>false,'message'=>'端末確認に失敗しました。現在ログインしている端末からのみプレイヤーID変更できます。']);
    }
    $rows[$oldIndex][0] = $new_id;
    $rows[$oldIndex][3] = $now;
    rewind($fp);
    ftruncate($fp, 0);
    fputcsv($fp, ['player_id','client_hash','created_at','last_login_at']);
    foreach ($rows as $row) {
        fputcsv($fp, [
            safe_csv_cell_feature($row[0] ?? ''),
            $row[1] ?? '',
            $row[2] ?? $now,
            $row[3] ?? $now
        ]);
    }
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return 1;
}


function player_id_change_history_file() {
    return feature_scores_dir() . '/player_id_change_history.csv';
}
function player_id_change_history_rows() {
    $file = player_id_change_history_file();
    if (!is_file($file)) return [];
    $rows = [];
    $fp = fopen($file, 'r');
    if (!$fp) return [];
    $header = fgetcsv($fp);
    while (($row = fgetcsv($fp)) !== false) {
        $rows[] = [
            'changed_at'=>$row[0] ?? '',
            'old_player_id'=>$row[1] ?? '',
            'new_player_id'=>$row[2] ?? '',
            'client_hash'=>$row[3] ?? '',
            'result'=>$row[4] ?? '',
            'message'=>$row[5] ?? ''
        ];
    }
    fclose($fp);
    return $rows;
}
function count_successful_changes_today($current_id) {
    $today = date('Y-m-d');
    $count = 0;
    foreach (player_id_change_history_rows() as $r) {
        if (($r['result'] ?? '') !== 'success') continue;
        if (substr($r['changed_at'] ?? '', 0, 10) !== $today) continue;
        if (($r['old_player_id'] ?? '') === $current_id || ($r['new_player_id'] ?? '') === $current_id) $count++;
    }
    return $count;
}
function append_player_id_change_history($old_id, $new_id, $client_hash, $result, $message) {
    $file = player_id_change_history_file();
    $is_new = !is_file($file);
    $fp = fopen($file, 'a');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    if ($is_new) fputcsv($fp, ['changed_at','old_player_id','new_player_id','client_hash','result','message']);
    fputcsv($fp, [
        date('Y-m-d H:i:s'),
        safe_csv_cell_feature($old_id),
        safe_csv_cell_feature($new_id),
        safe_csv_cell_feature($client_hash),
        safe_csv_cell_feature($result),
        safe_csv_cell_feature($message)
    ]);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond_change_id(405, ['ok'=>false,'error'=>'method not allowed']);
$data = read_request_json_change_id();
$old_id = normalize_player_id($data['old_player_id'] ?? ($data['player_id'] ?? ''));
$new_id = normalize_player_id($data['new_player_id'] ?? '');
$client_token = normalize_client_token($data['client_token'] ?? '');

if ($old_id === '' || $new_id === '' || strlen($client_token) < 16) {
    respond_change_id(400, ['ok'=>false,'message'=>'現在のプレイヤーID、新しいプレイヤーID、端末情報が必要です。']);
}
if ($old_id === $new_id) {
    respond_change_id(400, ['ok'=>false,'message'=>'現在と同じプレイヤーIDです。別のプレイヤーIDを入力してください。']);
}
if (is_player_suspended($old_id)) {
    respond_change_id(403, ['ok'=>false,'message'=>'このプレイヤーIDは現在ゲーム利用停止中のため変更できません。']);
}

$client_hash_for_history = hash_short($client_token);
if (count_successful_changes_today($old_id) >= 1) {
    append_player_id_change_history($old_id, $new_id, $client_hash_for_history, 'blocked_daily_limit', 'プレイヤーID変更は1日1回までです。');
    respond_change_id(429, ['ok'=>false,'error'=>'daily_limit','message'=>'プレイヤーIDの変更は1日1回までです。明日以降に再度お試しください。']);
}

$dir = feature_scores_dir();
$changed = [];
$changed['player_registry'] = update_player_registry_change_id($dir . '/player_registry.csv', $old_id, $new_id, $client_token);
$changed['score_log'] = migrate_csv_player_id_change_id($dir . '/score_log.csv', $old_id, $new_id, ['player_id','player','id','name']);
$changed['access_log'] = migrate_csv_player_id_change_id($dir . '/access_log.csv', $old_id, $new_id, ['player_id']);
$changed['id_audit_log'] = migrate_csv_player_id_change_id($dir . '/id_audit_log.csv', $old_id, $new_id, ['player_id']);
$changed['request_log'] = migrate_csv_player_id_change_id($dir . '/request_log.csv', $old_id, $new_id, ['player_id']);
$changed['player_id_change_history'] = migrate_csv_player_id_change_id($dir . '/player_id_change_history.csv', $old_id, $new_id, ['old_player_id','new_player_id']);
$changed['player_features'] = migrate_json_players_key_change_id($dir . '/player_features.json', $old_id, $new_id);
$changed['player_suspensions'] = migrate_json_players_key_change_id($dir . '/player_suspensions.json', $old_id, $new_id);
$changed['mistake_review'] = migrate_mistake_review_change_id($dir . '/mistake_review.json', $old_id, $new_id);
$changed['quiz_master_scores'] = migrate_quiz_master_scores_change_id($dir . '/quiz_master_scores.json', $old_id, $new_id);
$changed['push_subscriptions'] = migrate_push_subscriptions_change_id($dir . '/push_subscriptions.json', $old_id, $new_id);

access_log_event([
    'event'=>'change_player_id',
    'page'=>'settings',
    'player_id'=>$new_id,
    'client_hash'=>hash_short($client_token),
    'extra'=>'from=' . $old_id
]);
audit_log_id_event([
    'action'=>'change_player_id',
    'result'=>'success',
    'player_id'=>$new_id,
    'id_type'=>'player',
    'client_hash'=>hash_short($client_token),
    'message'=>'changed from ' . $old_id . ' to ' . $new_id
]);
append_player_id_change_history($old_id, $new_id, hash_short($client_token), 'success', 'changed from ' . $old_id . ' to ' . $new_id);

respond_change_id(200, [
    'ok'=>true,
    'old_player_id'=>$old_id,
    'new_player_id'=>$new_id,
    'changed'=>$changed,
    'message'=>'プレイヤーIDを変更しました。成績・ランキング・設定データは新しいプレイヤーIDへ引き継がれます。'
]);
?>
