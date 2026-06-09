<?php
/**
 * Release version management endpoint for 野球やろうぜ！ 管理画面.
 *
 * Purpose:
 * - Fix version history edit/delete operations without changing the large admin_api.php.
 * - Uses the same super admin session token as admin.html.
 * - Reads/writes scores/release_versions.json with file locking.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/feature_common.php';

function rv_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}

function rv_request_data() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function rv_require_admin($data) {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower((string)($data['token'] ?? '')));
    $session = require_super_admin_session($token);
    if (!$session) {
        rv_json(403, ['ok'=>false, 'error'=>'forbidden', 'message'=>'最高位管理者ログインが必要です。']);
    }
    return $session;
}

function rv_scores_dir() {
    if (function_exists('feature_scores_dir')) return feature_scores_dir();
    return dirname(__DIR__) . '/scores';
}

function rv_release_file() {
    return rv_scores_dir() . '/release_versions.json';
}

function rv_version_json() {
    $file = dirname(__DIR__) . '/version.json';
    $raw = is_file($file) ? file_get_contents($file) : '{}';
    $json = json_decode($raw ?: '{}', true);
    return is_array($json) ? $json : [];
}

function rv_default_db() {
    return [
        'current_public_version'=>'v1.0.0',
        'internal'=>[],
        'rules'=>[
            ['type'=>'major','label'=>'メジャー','example'=>'v2.0.0','description'=>'システム構造・ゲーム仕様・画面構成・管理方式など大きな変更'],
            ['type'=>'minor','label'=>'マイナー','example'=>'v1.1.0','description'=>'機能追加、管理画面追加、ランキング/通知/UI改善、修正'],
            ['type'=>'patch','label'=>'パッチ','example'=>'v1.0.1','description'=>'問題追加、文言修正、選択肢修正、軽微な表示修正、不具合修正']
        ],
        'versions'=>[]
    ];
}

function rv_load_db() {
    $file = rv_release_file();
    $db = rv_default_db();
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $json = json_decode($raw ?: '{}', true);
        if (is_array($json)) $db = array_replace_recursive($db, $json);
    }
    if (!isset($db['versions']) || !is_array($db['versions'])) $db['versions'] = [];
    if (!isset($db['rules']) || !is_array($db['rules']) || !count($db['rules'])) $db['rules'] = rv_default_db()['rules'];
    if (!isset($db['current_public_version']) || $db['current_public_version'] === '') $db['current_public_version'] = 'v1.0.0';
    return $db;
}

function rv_write_json_locked($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    rewind($fp);
    ftruncate($fp, 0);
    $ok = fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0))) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function rv_save_db($db) {
    return rv_write_json_locked(rv_release_file(), $db);
}

function rv_parts($v) {
    if (preg_match('/^v(\d+)\.(\d+)\.(\d+)$/', (string)$v, $m)) {
        return [intval($m[1]), intval($m[2]), intval($m[3])];
    }
    return [0,0,0];
}

function rv_compare_desc($a, $b) {
    $av = rv_parts($a['public_version'] ?? '');
    $bv = rv_parts($b['public_version'] ?? '');
    for ($i=0; $i<3; $i++) {
        if ($av[$i] !== $bv[$i]) return $bv[$i] <=> $av[$i];
    }
    $ad = (string)($a['released_at'] ?? '');
    $bd = (string)($b['released_at'] ?? '');
    if ($ad !== $bd) return strcmp($bd, $ad);
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
}

function rv_normalize_rows($rows) {
    if (!is_array($rows)) return [];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if (($row['id'] ?? '') === '') {
            $pv = preg_replace('/[^v0-9\.]/', '', (string)($row['public_version'] ?? 'v0.0.0'));
            $date = preg_replace('/[^0-9\-]/', '', (string)($row['released_at'] ?? date('Y-m-d')));
            $iv = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($row['internal_version'] ?? 'release'));
            $row['id'] = $pv . '-' . $date . '-' . $iv;
        }
        $row['visible'] = !empty($row['visible']);
        $row['is_current'] = !empty($row['is_current']);
        $out[] = $row;
    }
    usort($out, 'rv_compare_desc');
    return array_values($out);
}

function rv_summary($message='') {
    $db = rv_load_db();
    $version = rv_version_json();
    $rows = rv_normalize_rows($db['versions'] ?? []);
    return [
        'ok'=>true,
        'message'=>$message,
        'current_public_version'=>$db['current_public_version'] ?? 'v1.0.0',
        'internal'=>[
            'app_version'=>$version['app_version'] ?? ($db['internal']['app_version'] ?? ''),
            'cache_version'=>$version['cache_version'] ?? ($db['internal']['cache_version'] ?? ''),
            'updated_at'=>$version['updated_at'] ?? ($db['internal']['updated_at'] ?? ''),
            'environment'=>$version['environment'] ?? '',
            'start_url'=>$version['start_url'] ?? '',
            'scope'=>$version['scope'] ?? '',
            'change_summary'=>$version['change_summary'] ?? ''
        ],
        'versions'=>$rows,
        'rules'=>$db['rules'] ?? rv_default_db()['rules']
    ];
}

function rv_clean_date($v) {
    $v = trim((string)$v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
    return date('Y-m-d');
}

function rv_clean_public_version($v) {
    $v = trim((string)$v);
    return preg_match('/^v\d+\.\d+\.\d+$/', $v) ? $v : '';
}

function rv_clean_release_type($v) {
    $v = trim((string)$v);
    return in_array($v, ['major','minor','patch'], true) ? $v : 'patch';
}

function rv_clean_text($v, $max=6000) {
    $v = trim((string)$v);
    if (mb_strlen($v, 'UTF-8') > $max) $v = mb_substr($v, 0, $max, 'UTF-8');
    return $v;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rv_json(405, ['ok'=>false, 'error'=>'method_not_allowed', 'message'=>'POSTで実行してください。']);
}

$data = rv_request_data();
rv_require_admin($data);
$action = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['action'] ?? ''));

if ($action === 'release_versions') {
    rv_json(200, rv_summary());
}

if ($action === 'release_version_save') {
    $public = rv_clean_public_version($data['public_version'] ?? '');
    if ($public === '') rv_json(400, ['ok'=>false, 'message'=>'ユーザー向けバージョンは v1.0.3 の形式で入力してください。']);

    $db = rv_load_db();
    $version = rv_version_json();
    $id = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', (string)($data['id'] ?? ''));

    $row = [
        'id'=>$id,
        'public_version'=>$public,
        'release_type'=>rv_clean_release_type($data['release_type'] ?? 'patch'),
        'released_at'=>rv_clean_date($data['released_at'] ?? ''),
        'title'=>rv_clean_text($data['title'] ?? '', 120),
        'public_summary'=>rv_clean_text($data['public_summary'] ?? '', 2000),
        'admin_note'=>rv_clean_text($data['admin_note'] ?? '', 6000),
        'internal_version'=>rv_clean_text($data['internal_version'] ?? ($version['app_version'] ?? ''), 120),
        'cache_version'=>rv_clean_text($data['cache_version'] ?? ($version['cache_version'] ?? ''), 160),
        'visible'=>!empty($data['visible']),
        'is_current'=>!empty($data['is_current']),
        'updated_at'=>date('Y-m-d H:i:s')
    ];

    if ($row['id'] === '') {
        $row['id'] = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $row['public_version'] . '-' . $row['released_at'] . '-' . ($row['internal_version'] ?: 'release'));
    }

    $found = false;
    $rows = [];
    foreach (($db['versions'] ?? []) as $existing) {
        if (!is_array($existing)) continue;
        if (($existing['id'] ?? '') === $row['id']) {
            $row['created_at'] = $existing['created_at'] ?? date('Y-m-d H:i:s');
            $rows[] = $row;
            $found = true;
        } else {
            $rows[] = $existing;
        }
    }
    if (!$found) {
        $row['created_at'] = date('Y-m-d H:i:s');
        $rows[] = $row;
    }

    if ($row['is_current']) {
        foreach ($rows as &$r) {
            if (is_array($r)) $r['is_current'] = (($r['id'] ?? '') === $row['id']);
        }
        unset($r);
        $db['current_public_version'] = $row['public_version'];
    }

    $db['versions'] = rv_normalize_rows($rows);
    $db['internal'] = [
        'app_version'=>$version['app_version'] ?? '',
        'cache_version'=>$version['cache_version'] ?? '',
        'updated_at'=>$version['updated_at'] ?? ''
    ];

    if (!rv_save_db($db)) {
        rv_json(500, ['ok'=>false, 'message'=>'scores/release_versions.json を保存できません。scoresフォルダの書き込み権限を確認してください。']);
    }

    rv_json(200, rv_summary($found ? '更新履歴を保存しました。' : '更新履歴を追加しました。'));
}

if ($action === 'release_version_delete') {
    $id = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', (string)($data['id'] ?? ''));
    if ($id === '') rv_json(400, ['ok'=>false, 'message'=>'削除対象IDがありません。']);

    $db = rv_load_db();
    $rows = [];
    $deleted = false;
    $deleted_current = false;

    foreach (($db['versions'] ?? []) as $row) {
        if (!is_array($row)) continue;
        if (($row['id'] ?? '') === $id) {
            $deleted = true;
            $deleted_current = !empty($row['is_current']);
            continue;
        }
        $rows[] = $row;
    }

    if (!$deleted) rv_json(404, ['ok'=>false, 'message'=>'削除対象の更新履歴が見つかりません。']);

    $rows = rv_normalize_rows($rows);
    if ($deleted_current && count($rows)) {
        $rows[0]['is_current'] = true;
        $db['current_public_version'] = $rows[0]['public_version'] ?? ($db['current_public_version'] ?? 'v1.0.0');
    }
    if (!count($rows)) $db['current_public_version'] = 'v1.0.0';

    $db['versions'] = $rows;

    if (!rv_save_db($db)) {
        rv_json(500, ['ok'=>false, 'message'=>'scores/release_versions.json を保存できません。scoresフォルダの書き込み権限を確認してください。']);
    }

    rv_json(200, rv_summary('更新履歴を削除しました。'));
}

rv_json(400, ['ok'=>false, 'error'=>'unknown_action', 'message'=>'不明な操作です。']);
