<?php
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
require_once __DIR__ . '/feature_common.php';

require_once __DIR__ . '/weekly_ranking_notice.php';
require_once __DIR__ . '/request_notification_common.php';
require_once __DIR__ . '/scheduled_notifications.php';
require_once __DIR__ . '/notice_duplicate_guard.php';
require_once __DIR__ . '/question_status_common.php';
require_once __DIR__ . '/quiz_master_titles_common.php';
function admin_json($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}
function request_data_admin() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
function require_admin_for_action($data) {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($data['token'] ?? ''));
    $session = require_super_admin_session($token);
    if (!$session) {
        admin_json(403, ['ok'=>false,'error'=>'forbidden','message'=>'最高位管理者ログインが必要です。']);
    }
    return $session;
}




function issue_link_settings_file_admin() {
    return feature_scores_dir() . '/issue_link_settings.json';
}
function default_issue_link_settings_admin() {
    return [
        'invite_key'=>'',
        'admin_key'=>'',
        'updated_at'=>'',
        'updated_by'=>'',
        'reuse_policy'=>'valid_until_key_changed',
    ];
}
function load_issue_link_settings_admin() {
    $db = read_json_file_safe(issue_link_settings_file_admin(), default_issue_link_settings_admin());
    if (!is_array($db)) $db = default_issue_link_settings_admin();
    foreach (['invite_key','admin_key','updated_at','updated_by','reuse_policy'] as $k) {
        if (!isset($db[$k])) $db[$k] = '';
    }
    return $db;
}
function save_issue_link_settings_admin($db) {
    $base = default_issue_link_settings_admin();
    $db = array_merge($base, is_array($db) ? $db : []);
    return write_json_file_locked(issue_link_settings_file_admin(), $db);
}
function sanitize_issue_link_key_admin($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $v)) return null;
    return $v;
}
function summarize_issue_link_settings_admin() {
    $db = load_issue_link_settings_admin();
    return [
        'invite_key'=>(string)($db['invite_key'] ?? ''),
        'admin_key'=>(string)($db['admin_key'] ?? ''),
        'updated_at'=>(string)($db['updated_at'] ?? ''),
        'updated_by'=>(string)($db['updated_by'] ?? ''),
        'reuse_policy'=>(string)($db['reuse_policy'] ?? 'valid_until_key_changed'),
    ];
}

function release_versions_file_admin() {
    return feature_scores_dir() . '/release_versions.json';
}
function load_release_versions_admin() {
    $fallback = ['current_public_version'=>'v1.0.0','versions'=>[]];
    $db = read_json_file_safe(release_versions_file_admin(), $fallback);
    if (!isset($db['versions']) || !is_array($db['versions'])) $db['versions'] = [];
    if (!isset($db['current_public_version'])) $db['current_public_version'] = 'v1.0.0';
    return $db;
}
function save_release_versions_admin($db) {
    if (!isset($db['versions']) || !is_array($db['versions'])) $db['versions'] = [];
    if (!isset($db['current_public_version'])) $db['current_public_version'] = 'v1.0.0';
    return write_json_file_locked(release_versions_file_admin(), $db);
}
function version_json_admin() {
    $file = __DIR__ . '/../version.json';
    $raw = is_file($file) ? file_get_contents($file) : '{}';
    $json = json_decode($raw ?: '{}', true);
    return is_array($json) ? $json : [];
}
function sanitize_public_version_admin($v) {
    $v = trim((string)$v);
    if (!preg_match('/^v\d+\.\d+\.\d+$/', $v)) return '';
    return $v;
}
function sanitize_release_type_admin($v) {
    $v = trim((string)$v);
    return in_array($v, ['major','minor','patch'], true) ? $v : 'patch';
}
function release_type_label_admin($v) {
    if ($v === 'major') return 'メジャー';
    if ($v === 'minor') return 'マイナー';
    return 'パッチ';
}
function release_version_parts_admin($v) {
    $v = trim((string)$v);
    if (preg_match('/^v(\d+)\.(\d+)\.(\d+)$/', $v, $m)) {
        return [intval($m[1]), intval($m[2]), intval($m[3])];
    }
    return [0, 0, 0];
}
function compare_release_versions_desc_admin($a, $b) {
    $av = release_version_parts_admin($a['public_version'] ?? '');
    $bv = release_version_parts_admin($b['public_version'] ?? '');
    for ($i = 0; $i < 3; $i++) {
        if ($av[$i] !== $bv[$i]) return $bv[$i] <=> $av[$i];
    }
    $ad = (string)($a['released_at'] ?? '');
    $bd = (string)($b['released_at'] ?? '');
    if ($ad !== $bd) return strcmp($bd, $ad);
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
}
function sort_release_versions_desc_admin($rows) {
    if (!is_array($rows)) return [];
    usort($rows, 'compare_release_versions_desc_admin');
    return $rows;
}
function summarize_release_versions_admin() {
    $db = load_release_versions_admin();
    $version = version_json_admin();
    $rows = sort_release_versions_desc_admin($db['versions']);
    return [
        'ok'=>true,
        'current_public_version'=>$db['current_public_version'] ?? 'v1.0.0',
        'internal'=>[
            'app_version'=>$version['app_version'] ?? '',
            'cache_version'=>$version['cache_version'] ?? '',
            'updated_at'=>$version['updated_at'] ?? '',
            'environment'=>$version['environment'] ?? '',
            'start_url'=>$version['start_url'] ?? '',
            'scope'=>$version['scope'] ?? '',
            'change_summary'=>$version['change_summary'] ?? ''
        ],
        'versions'=>array_values($rows),
        'rules'=>[
            ['type'=>'major','label'=>'メジャー','example'=>'v2.0.0','description'=>'システム構造・ゲーム仕様・画面構成・管理方式など大きな変更'],
            ['type'=>'minor','label'=>'マイナー','example'=>'v1.1.0','description'=>'機能追加、管理画面追加、ランキング/通知/UI改善、修正'],
            ['type'=>'patch','label'=>'パッチ','example'=>'v1.0.1','description'=>'問題追加、文言修正、選択肢修正、軽微な表示修正、不具合修正。新規問題追加通知の配信時は自動で次のパッチ版として履歴に追加']
        ]
    ];
}

function questions_file_path_admin() {
    return __DIR__ . '/../data/questions.json';
}
function load_questions_admin() {
    $file = questions_file_path_admin();
    $raw = is_file($file) ? file_get_contents($file) : '[]';
    $json = json_decode($raw ?: '[]', true);
    return is_array($json) ? $json : [];
}
function backup_questions_admin($reason='') {
    $src = questions_file_path_admin();
    $dir = feature_scores_dir() . '/question_backups';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $id = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $safe_reason = preg_replace('/[^a-zA-Z0-9_\-]/','', (string)$reason);
    $dst = $dir . '/questions_' . $id . ($safe_reason !== '' ? '_' . $safe_reason : '') . '.json';
    if (is_file($src)) @copy($src, $dst);
    return ['id'=>$id,'path'=>$dst,'file'=>basename($dst),'reason'=>$safe_reason];
}
function save_questions_admin($questions) {
    return write_json_file_locked(questions_file_path_admin(), $questions);
}
function quiz_master_questions_file_admin() {
    return __DIR__ . '/../data/quiz_master_questions.json';
}
function quiz_master_questions_js_file_admin() {
    return __DIR__ . '/../assets/quiz_master_questions.js';
}
function load_quiz_master_payload_admin() {
    $file = quiz_master_questions_file_admin();
    $raw = is_file($file) ? file_get_contents($file) : '{}';
    $json = json_decode($raw ?: '{}', true);
    if (!is_array($json)) $json = [];
    if (!isset($json['questions']) || !is_array($json['questions'])) {
        $json = ['meta'=>['title'=>'野球博士チャレンジ'],'questions'=>[]];
    }
    if (!isset($json['meta']) || !is_array($json['meta'])) $json['meta'] = [];
    return $json;
}
function backup_quiz_master_questions_admin($reason='') {
    $src = quiz_master_questions_file_admin();
    $dir = feature_scores_dir() . '/question_backups';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $id = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $safe_reason = preg_replace('/[^a-zA-Z0-9_\-]/','', (string)$reason);
    $dst = $dir . '/quiz_master_questions_' . $id . ($safe_reason !== '' ? '_' . $safe_reason : '') . '.json';
    if (is_file($src)) @copy($src, $dst);
    return ['id'=>$id,'path'=>$dst,'file'=>basename($dst),'reason'=>$safe_reason];
}
function write_text_file_locked_admin($file, $text) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, $text) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}
function save_quiz_master_payload_admin($payload) {
    if (!isset($payload['meta']) || !is_array($payload['meta'])) $payload['meta'] = [];
    if (!isset($payload['questions']) || !is_array($payload['questions'])) $payload['questions'] = [];
    $payload['meta']['question_count'] = count($payload['questions']);
    $payload['meta']['updated_at'] = date('Y-m-d H:i:s');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    if ($json === false) return false;
    $js = 'window.QUIZ_MASTER_QUESTIONS = ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) . ";\n";
    if (!write_text_file_locked_admin(quiz_master_questions_file_admin(), $json . "\n")) return false;
    return write_text_file_locked_admin(quiz_master_questions_js_file_admin(), $js);
}
function normalize_quiz_master_question_id_admin($id) {
    return strtoupper(preg_replace('/[^A-Z0-9_-]/', '', (string)$id));
}
function normalize_quiz_master_question_admin($q) {
    $choices = [];
    if (isset($q['choices']) && is_array($q['choices'])) {
        foreach ($q['choices'] as $choice) $choices[] = trim((string)$choice);
    }
    return [
        'id'=>normalize_quiz_master_question_id_admin($q['id'] ?? ''),
        'level'=>intval($q['level'] ?? 0),
        'level_name'=>trim((string)($q['level_name'] ?? '')),
        'category'=>trim((string)($q['category'] ?? '')),
        'question'=>trim((string)($q['question'] ?? '')),
        'choices'=>$choices,
        'answer'=>intval($q['answer'] ?? -1),
        'explanation'=>trim((string)($q['explanation'] ?? '')),
        'source_note'=>trim((string)($q['source_note'] ?? '')),
        'overlap_check'=>trim((string)($q['overlap_check'] ?? '')),
        'quality_note'=>trim((string)($q['quality_note'] ?? ''))
    ];
}
function validate_quiz_master_question_admin($q, $existing_ids, $original_id='') {
    if (!preg_match('/^BQ\d+$/', $q['id'] ?? '')) return 'IDは BQ + 数字で入力してください。例：BQ601';
    if (($original_id === '' || $original_id !== ($q['id'] ?? '')) && !empty($existing_ids[$q['id'] ?? ''])) return '同じIDの問題がすでにあります。';
    if (intval($q['level'] ?? 0) < 1 || intval($q['level'] ?? 0) > 20) return 'level は 1〜20 で入力してください。';
    if (($q['category'] ?? '') === '') return 'category を入力してください。';
    if (($q['question'] ?? '') === '') return 'question を入力してください。';
    if (!isset($q['choices']) || !is_array($q['choices']) || count($q['choices']) !== 3) return 'choices は3個必要です。';
    foreach ($q['choices'] as $choice) {
        if (trim((string)$choice) === '') return '選択肢に空欄があります。';
    }
    if (intval($q['answer'] ?? -1) < 0 || intval($q['answer'] ?? -1) > 2) return 'answer は 0=A、1=B、2=C のいずれかです。';
    if (($q['explanation'] ?? '') === '') return 'explanation は必須です。';
    return '';
}
function quiz_master_question_public_row_admin($q) {
    return [
        'id'=>$q['id'] ?? '',
        'level'=>intval($q['level'] ?? 0),
        'level_name'=>$q['level_name'] ?? '',
        'category'=>$q['category'] ?? '',
        'question'=>$q['question'] ?? '',
        'answer'=>intval($q['answer'] ?? -1),
        'explanation'=>$q['explanation'] ?? ''
    ];
}
function question_versions_file_admin() {
    return feature_scores_dir() . '/question_versions.json';
}
function load_question_versions_admin() {
    $db = read_json_file_safe(question_versions_file_admin(), ['versions'=>[]]);
    if (!isset($db['versions']) || !is_array($db['versions'])) $db['versions'] = [];
    return $db;
}
function save_question_versions_admin($db) {
    if (!isset($db['versions']) || !is_array($db['versions'])) $db['versions'] = [];
    return write_json_file_locked(question_versions_file_admin(), $db);
}
function question_by_id_admin($questions, $id) {
    foreach ($questions as $q) {
        if (($q['id'] ?? '') === $id) return $q;
    }
    return null;
}
function question_change_summary_admin($before, $after) {
    if ($before === null && $after !== null) return ['kind'=>'added','fields'=>array_keys($after)];
    if ($before !== null && $after === null) return ['kind'=>'deleted','fields'=>array_keys($before)];
    $fields = [];
    $keys = array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? [])));
    foreach ($keys as $k) {
        if (($before[$k] ?? null) != ($after[$k] ?? null)) $fields[] = $k;
    }
    return ['kind'=>'updated','fields'=>$fields];
}
function append_question_version_admin($entry) {
    $db = load_question_versions_admin();
    $db['versions'][] = $entry;
    if (count($db['versions']) > 500) $db['versions'] = array_slice($db['versions'], -500);
    return save_question_versions_admin($db);
}
function summarize_question_versions_admin($limit=200) {
    $db = load_question_versions_admin();
    $rows = array_reverse($db['versions']);
    $rows = array_slice($rows, 0, $limit);
    return array_map(function($v){
        return [
            'version_id'=>$v['version_id'] ?? '',
            'at'=>$v['at'] ?? '',
            'admin_label'=>$v['admin_label'] ?? '',
            'action'=>$v['action'] ?? '',
            'question_id'=>$v['question_id'] ?? '',
            'result_question_count'=>$v['result_question_count'] ?? '',
            'backup_file'=>$v['backup_file'] ?? '',
            'changed_fields'=>$v['changed_fields'] ?? [],
            'message'=>$v['message'] ?? ''
        ];
    }, $rows);
}
function find_question_version_admin($version_id) {
    $db = load_question_versions_admin();
    foreach ($db['versions'] as $v) {
        if (($v['version_id'] ?? '') === $version_id) return $v;
    }
    return null;
}
function question_summary_admin($q) {
    $positions = [];
    if (isset($q['positions']) && is_array($q['positions'])) $positions = $q['positions'];
    elseif (isset($q['choices_by_position']) && is_array($q['choices_by_position'])) $positions = array_keys($q['choices_by_position']);
    return [
        'id'=>$q['id'] ?? '',
        'status'=>question_status_get($q['id'] ?? ''),
        'type'=>$q['type'] ?? '',
        'theme'=>$q['theme'] ?? '',
        'grade'=>$q['grade'] ?? '',
        'min_grade'=>$q['min_grade'] ?? '',
        'max_grade'=>$q['max_grade'] ?? '',
        'stage'=>$q['stage'] ?? '',
        'outs'=>$q['outs'] ?? '',
        'outs_scope'=>$q['outs_scope'] ?? '',
        'ball_tag'=>$q['ball_tag'] ?? '',
        'situation'=>$q['situation'] ?? '',
        'prompt'=>$q['prompt'] ?? '',
        'positions'=>$positions
    ];
}

function question_status_label_admin($status) {
    $status = question_status_valid($status);
    if ($status === 'draft') return '下書き';
    if ($status === 'disabled') return '停止';
    return '公開';
}
function question_export_choice_admin($q, $idx) {
    $choices = [];
    if (($q['type'] ?? '') === 'defense' && isset($q['choices_by_position']) && is_array($q['choices_by_position'])) {
        $keys = array_keys($q['choices_by_position']);
        $first = $keys[0] ?? '';
        if ($first !== '' && isset($q['choices_by_position'][$first]) && is_array($q['choices_by_position'][$first])) $choices = $q['choices_by_position'][$first];
    } elseif (isset($q['choices']) && is_array($q['choices'])) {
        $choices = $q['choices'];
    }
    $c = $choices[$idx] ?? [];
    return [
        'text'=>is_array($c) ? (string)($c['text'] ?? '') : '',
        'score'=>is_array($c) ? (string)($c['score'] ?? '') : '',
        'explain'=>is_array($c) ? (string)($c['explain'] ?? '') : ''
    ];
}
function question_export_csv_admin($rows) {
    $fp = fopen('php://temp', 'r+');
    $headers = ['id','status','status_label','type','grade','position','theme','stage','outs','outs_scope','ball_tag','situation','prompt','visual_batter_runner','visual_ball_path','choice_1','score_1','explain_1','choice_2','score_2','explain_2','choice_3','score_3','explain_3','raw_json'];
    fputcsv($fp, $headers);
    foreach ($rows as $q) {
        if (!is_array($q)) continue;
        $id = (string)($q['id'] ?? '');
        $status = question_status_get($id);
        $positions = [];
        if (isset($q['positions']) && is_array($q['positions'])) $positions = $q['positions'];
        elseif (isset($q['choices_by_position']) && is_array($q['choices_by_position'])) $positions = array_keys($q['choices_by_position']);
        $c1 = question_export_choice_admin($q, 0);
        $c2 = question_export_choice_admin($q, 1);
        $c3 = question_export_choice_admin($q, 2);
        fputcsv($fp, [
            $id,
            $status,
            question_status_label_admin($status),
            (string)($q['type'] ?? ''),
            (string)($q['grade'] ?? ''),
            implode('|', array_map('strval', $positions)),
            (string)($q['theme'] ?? ''),
            (string)($q['stage'] ?? ''),
            (string)($q['outs'] ?? ''),
            (string)($q['outs_scope'] ?? ''),
            (string)($q['ball_tag'] ?? ''),
            (string)($q['situation'] ?? ''),
            (string)($q['prompt'] ?? ''),
            (isset($q['visual']) && is_array($q['visual']) && array_key_exists('batter_runner', $q['visual'])) ? ($q['visual']['batter_runner'] ? 'true' : 'false') : '',
            (isset($q['visual']) && is_array($q['visual'])) ? (string)($q['visual']['ball_path'] ?? '') : '',
            $c1['text'], $c1['score'], $c1['explain'],
            $c2['text'], $c2['score'], $c2['explain'],
            $c3['text'], $c3['score'], $c3['explain'],
            json_encode($q, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0))
        ]);
    }
    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);
    return $csv;
}
function question_filter_rows_admin($questions, $query='', $type_filter='', $position_filter='') {
    $out = [];
    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        $sum = question_summary_admin($q);
        if ($type_filter !== '' && $sum['type'] !== $type_filter) continue;
        if ($position_filter !== '') {
            $positions = $sum['positions'] ?? [];
            if (!is_array($positions) || !in_array($position_filter, $positions, true)) continue;
        }
        if ($query !== '') {
            $hay = json_encode($sum, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
            if (mb_stripos($hay, $query, 0, 'UTF-8') === false) continue;
        }
        $out[] = $q;
    }
    return $out;
}

function game_config_file_path_admin() {
    return __DIR__ . '/../data/game_config.json';
}
function load_game_config_admin() {
    $file = game_config_file_path_admin();
    $raw = is_file($file) ? file_get_contents($file) : '{}';
    $json = json_decode($raw ?: '{}', true);
    return is_array($json) ? $json : [];
}
function save_game_config_admin($config) {
    return write_json_file_locked(game_config_file_path_admin(), $config);
}
function backup_game_config_admin($backup_id, $reason='') {
    $src = game_config_file_path_admin();
    $dir = feature_scores_dir() . '/question_backups';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $safe_reason = preg_replace('/[^a-zA-Z0-9_\-]/','', (string)$reason);
    $dst = $dir . '/game_config_' . $backup_id . ($safe_reason !== '' ? '_' . $safe_reason : '') . '.json';
    if (is_file($src)) @copy($src, $dst);
    return ['id'=>$backup_id,'path'=>$dst,'file'=>basename($dst),'reason'=>$safe_reason];
}
function question_backup_list_admin() {
    $dir = feature_scores_dir() . '/question_backups';
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/questions_*.json') ?: [];
    $rows = [];
    foreach ($files as $file) {
        $base = basename($file);
        $id = preg_replace('/^questions_/', '', preg_replace('/\.json$/', '', $base));
        $rows[] = ['id'=>$id, 'file'=>$base, 'size'=>filesize($file), 'mtime'=>date('Y-m-d H:i:s', filemtime($file)), 'has_game_config'=>is_file($dir . '/game_config_' . $id . '.json')];
    }
    usort($rows, function($a,$b){ return strcmp($b['mtime'] ?? '', $a['mtime'] ?? ''); });
    return $rows;
}
function question_backup_path_from_id_admin($id) {
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$id);
    $dir = feature_scores_dir() . '/question_backups';
    $direct = $dir . '/questions_' . $id . '.json';
    if (is_file($direct)) return $direct;
    $matches = glob($dir . '/questions_' . $id . '*.json') ?: [];
    return $matches[0] ?? '';
}
function question_backup_restore_admin($id, $admin_label='') {
    $path = question_backup_path_from_id_admin($id);
    if ($path === '' || !is_file($path)) return ['ok'=>false,'message'=>'バックアップファイルが見つかりません。'];
    $raw = file_get_contents($path);
    $json = json_decode($raw ?: '[]', true);
    if (!is_array($json)) return ['ok'=>false,'message'=>'バックアップJSONを読み込めません。'];
    $current = backup_questions_admin('before_backup_restore');
    backup_game_config_admin($current['id'], 'before_backup_restore');
    if (!save_questions_admin($json)) return ['ok'=>false,'message'=>'questions.json を復元できませんでした。'];
    $dir = feature_scores_dir() . '/question_backups';
    $baseId = preg_replace('/^questions_/', '', preg_replace('/\.json$/', '', basename($path)));
    $configPath = $dir . '/game_config_' . $baseId . '.json';
    $restoredConfig = false;
    if (is_file($configPath)) {
        $cfg = json_decode(file_get_contents($configPath), true);
        if (is_array($cfg)) $restoredConfig = save_game_config_admin($cfg);
    }
    $version_id = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    append_question_version_admin([
        'version_id'=>$version_id,
        'at'=>date('Y-m-d H:i:s'),
        'admin_label'=>$admin_label,
        'action'=>'backup_restore',
        'question_id'=>'ALL',
        'backup_id'=>$current['id'],
        'backup_file'=>$current['file'],
        'backup_path'=>$current['path'],
        'before'=>null,
        'after'=>null,
        'changed_fields'=>['questions.json','game_config.json'],
        'result_question_count'=>count($json),
        'message'=>'restored backup ' . basename($path)
    ]);
    return ['ok'=>true,'message'=>'バックアップから復元しました。' . ($restoredConfig ? ' game_config.json も復元しました。' : ''),'version_id'=>$version_id,'current_backup'=>$current['file']];
}

function question_backup_delete_admin($id, $admin_label='') {
    $path = question_backup_path_from_id_admin($id);
    if ($path === '' || !is_file($path)) return ['ok'=>false,'message'=>'バックアップファイルが見つかりません。'];
    $baseId = preg_replace('/^questions_/', '', preg_replace('/\.json$/', '', basename($path)));
    $dir = feature_scores_dir() . '/question_backups';
    $files = [$path];
    foreach (glob($dir . '/game_config_' . $baseId . '*.json') ?: [] as $cfgFile) {
        if (is_file($cfgFile)) $files[] = $cfgFile;
    }
    $deleted = [];
    $failed = [];
    foreach (array_unique($files) as $file) {
        $base = basename($file);
        if (@unlink($file)) $deleted[] = $base;
        else $failed[] = $base;
    }
    if ($failed) return ['ok'=>false,'message'=>'削除できないバックアップがあります: ' . implode(', ', $failed), 'deleted'=>$deleted, 'failed'=>$failed];
    append_question_version_admin([
        'version_id'=>date('Ymd_His') . '_' . bin2hex(random_bytes(3)),
        'at'=>date('Y-m-d H:i:s'),
        'admin_label'=>$admin_label,
        'action'=>'backup_delete',
        'question_id'=>'ALL',
        'backup_id'=>$baseId,
        'backup_file'=>basename($path),
        'backup_path'=>$path,
        'before'=>null,
        'after'=>null,
        'changed_fields'=>['question_backups'],
        'result_question_count'=>count(load_questions_admin()),
        'message'=>'deleted backup ' . implode(', ', $deleted)
    ]);
    return ['ok'=>true,'message'=>'バックアップを削除しました。','deleted'=>$deleted,'backup_id'=>$baseId];
}

function question_csv_normalize_for_hash_admin($csv_text) {
    $csv_text = (string)$csv_text;
    $csv_text = preg_replace('/^\xEF\xBB\xBF/', '', $csv_text);
    $csv_text = str_replace(["\r\n", "\r"], "\n", $csv_text);
    return rtrim($csv_text, "\n") . "\n";
}
function question_csv_hash_admin($csv_text) {
    return hash('sha256', question_csv_normalize_for_hash_admin($csv_text));
}
function question_csv_history_path_admin() {
    return feature_scores_dir() . '/question_csv_import_history.json';
}
function read_question_csv_history_admin() {
    $path = question_csv_history_path_admin();
    if (!is_file($path)) return ['items'=>[]];
    $json = json_decode((string)file_get_contents($path), true);
    if (!is_array($json)) return ['items'=>[]];
    if (!isset($json['items']) || !is_array($json['items'])) $json['items'] = [];
    return $json;
}
function write_question_csv_history_admin($history) {
    $path = question_csv_history_path_admin();
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return file_put_contents($path, json_encode($history, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
function find_question_csv_history_admin($hash) {
    $history = read_question_csv_history_admin();
    foreach (($history['items'] ?? []) as $item) {
        if (($item['hash'] ?? '') === $hash) return $item;
    }
    return null;
}
function record_question_csv_history_admin($csv_text, $admin_label, $summary=[], $file_name='') {
    $hash = question_csv_hash_admin($csv_text);
    $history = read_question_csv_history_admin();
    $items = $history['items'] ?? [];
    foreach ($items as $item) {
        if (($item['hash'] ?? '') === $hash) return $item;
    }
    $record = [
        'hash'=>$hash,
        'imported_at'=>date('Y-m-d H:i:s'),
        'admin_label'=>(string)$admin_label,
        'file_name'=>(string)$file_name,
        'summary'=>$summary,
        'note'=>'同じCSVデータの重複インポート防止用レコード'
    ];
    array_unshift($items, $record);
    $history['items'] = array_slice($items, 0, 200);
    write_question_csv_history_admin($history);
    return $record;
}

function question_csv_preview_has_actionable_changes_admin($preview) {
    if (!is_array($preview)) return false;
    foreach (($preview['items'] ?? []) as $it) {
        $kind = (string)($it['change_kind'] ?? '');
        if ($kind === 'new' || $kind === 'update') return true;
    }
    return false;
}

function duplicate_question_csv_message_admin($record) {
    $at = (string)($record['imported_at'] ?? '不明');
    $name = (string)($record['file_name'] ?? '');
    $namePart = $name !== '' ? '（ファイル名: ' . $name . '）' : '';
    return 'このCSVデータは既に読み込み・反映済みです。重複反映を防ぐため読み込めません。読み込み日時: ' . $at . $namePart;
}

function parse_question_csv_admin($csv_text) {
    $csv_text = (string)$csv_text;
    $csv_text = preg_replace('/^\xEF\xBB\xBF/', '', $csv_text);
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csv_text);
    rewind($fp);
    $headers = fgetcsv($fp);
    if (!$headers || !is_array($headers)) return ['headers'=>[], 'rows'=>[], 'errors'=>[['line'=>1,'message'=>'CSVヘッダーを読み込めません。','hint'=>'UTF-8のCSVか、1行目に列名があるか確認してください。']]];
    $headers = array_map(function($h){ return trim((string)$h); }, $headers);
    $rows = [];
    $errors = [];
    $line = 1;
    while (($cols = fgetcsv($fp)) !== false) {
        $line++;
        if (count($cols) === 1 && trim((string)$cols[0]) === '') continue;
        $row = [];
        foreach ($headers as $i=>$h) $row[$h] = isset($cols[$i]) ? $cols[$i] : '';
        $row['_line'] = $line;
        $rows[] = $row;
        if (count($cols) !== count($headers)) {
            $errors[] = ['line'=>$line,'message'=>'列数がヘッダーと一致しません。','hint'=>'セル内のカンマや改行が正しくクォートされているか確認してください。'];
        }
    }
    fclose($fp);
    return ['headers'=>$headers,'rows'=>$rows,'errors'=>$errors];
}
function next_question_id_admin($type, &$counters) {
    $prefix = $type === 'attack' ? 'A' : ($type === 'basic' ? 'B' : 'D');
    if (!isset($counters[$prefix])) $counters[$prefix] = 0;
    $counters[$prefix]++;
    return $prefix . str_pad((string)$counters[$prefix], 3, '0', STR_PAD_LEFT);
}
function build_question_id_counters_admin($questions) {
    $counters = ['A'=>0,'B'=>0,'D'=>0];
    foreach ($questions as $q) {
        $id = (string)($q['id'] ?? '');
        if (preg_match('/^([ABD])(\d+)$/', $id, $m)) {
            $n = intval($m[2]);
            if ($n > ($counters[$m[1]] ?? 0)) $counters[$m[1]] = $n;
        }
    }
    return $counters;
}
function normalize_csv_status_admin($raw, $is_new, $current_status='published') {
    $s = strtolower(trim((string)$raw));
    if ($s === '') return $is_new ? 'draft' : question_status_valid($current_status);
    if (in_array($s, ['draft','published','disabled'], true)) return $s;
    if ($s === '下書き') return 'draft';
    if ($s === '公開') return 'published';
    if ($s === '停止') return 'disabled';
    return '__invalid__';
}

function csv_header_exists_admin($row, $key) {
    return array_key_exists($key, $row);
}
function parse_csv_bool_admin($value, &$ok) {
    $v = strtolower(trim((string)$value));
    $ok = true;
    if (in_array($v, ['1','true','yes','y','on','あり','有','表示','する'], true)) return true;
    if (in_array($v, ['0','false','no','n','off','なし','無','非表示','しない'], true)) return false;
    $ok = false;
    return false;
}
function apply_question_csv_editable_columns_admin(&$q, $row, &$errors) {
    $line = intval($row['_line'] ?? 0);
    if (!is_array($q)) return;
    foreach (['type','theme','stage','ball_tag','situation','prompt'] as $k) {
        if (csv_header_exists_admin($row, $k) && trim((string)$row[$k]) !== '') $q[$k] = (string)$row[$k];
    }
    if (csv_header_exists_admin($row, 'grade') && trim((string)$row['grade']) !== '') $q['grade'] = intval($row['grade']);

    // アウト条件はCSV列を優先する。空欄なら validate_question_admin で必須エラーにする。
    $has_outs_col = csv_header_exists_admin($row, 'outs');
    $has_scope_col = csv_header_exists_admin($row, 'outs_scope');
    if ($has_outs_col || $has_scope_col) {
        $outs_raw = $has_outs_col ? trim((string)$row['outs']) : '';
        $scope_raw = $has_scope_col ? strtolower(trim((string)$row['outs_scope'])) : '';
        if ($outs_raw !== '') {
            if (in_array(strtolower($outs_raw), ['common','アウト共通','共通'], true)) {
                unset($q['outs']);
                $q['outs_scope'] = 'common';
            } elseif (preg_match('/^[012]$/', $outs_raw)) {
                $q['outs'] = intval($outs_raw);
                unset($q['outs_scope']);
            } else {
                $q['outs'] = $outs_raw;
                unset($q['outs_scope']);
            }
        } elseif ($scope_raw !== '') {
            unset($q['outs']);
            $q['outs_scope'] = $scope_raw;
        } else {
            unset($q['outs'], $q['outs_scope']);
        }
    }

    if (csv_header_exists_admin($row, 'visual_batter_runner')) {
        if (!isset($q['visual']) || !is_array($q['visual'])) $q['visual'] = [];
        $raw = trim((string)$row['visual_batter_runner']);
        if ($raw === '') {
            unset($q['visual']['batter_runner']);
        } else {
            $ok = false;
            $q['visual']['batter_runner'] = parse_csv_bool_admin($raw, $ok);
            if (!$ok) $errors[] = ['line'=>$line,'field'=>'visual_batter_runner','message'=>'visual_batter_runner は true / false、1 / 0、あり / なし のいずれかで指定してください。','hint'=>'打者走者を画面に表示する問題は true、表示しない問題は false にしてください。'];
        }
    }
    if (csv_header_exists_admin($row, 'visual_ball_path')) {
        if (!isset($q['visual']) || !is_array($q['visual'])) $q['visual'] = [];
        $raw = trim((string)$row['visual_ball_path']);
        if ($raw === '') unset($q['visual']['ball_path']);
        else $q['visual']['ball_path'] = (string)$row['visual_ball_path'];
    }
}
function question_csv_header_validation_admin($headers) {
    $errors = [];
    $has = array_fill_keys($headers, true);
    foreach (['id','type','grade','stage','outs','outs_scope','ball_tag','situation','prompt','visual_batter_runner','visual_ball_path','raw_json'] as $h) {
        if (!isset($has[$h])) {
            $errors[] = ['line'=>1,'field'=>$h,'message'=>'CSVヘッダーに必須確認列 '.$h.' がありません。','hint'=>'管理画面の最新CSVエクスポートを使い、outs / outs_scope / visual_batter_runner / visual_ball_path を含めてください。'];
        }
    }
    return $errors;
}
function parse_question_from_csv_row_admin($row, $existing_question, &$counters, $existing_ids, &$errors) {
    $line = intval($row['_line'] ?? 0);
    $id = normalize_question_id_admin($row['id'] ?? '');
    $raw = trim((string)($row['raw_json'] ?? ''));
    $q = null;
    if ($raw !== '') {
        $q = json_decode($raw, true);
        if (!is_array($q)) {
            $errors[] = ['line'=>$line,'field'=>'raw_json','message'=>'raw_json のJSON解析に失敗しました。','hint'=>'ダブルクォート、末尾カンマ、閉じ括弧、セル内改行の崩れを確認してください。'];
            return null;
        }
        if ($id !== '') $q['id'] = $id;
    } elseif ($existing_question !== null) {
        $q = $existing_question;
        foreach (['type','grade','theme','stage','outs','ball_tag','situation','prompt'] as $k) {
            if (array_key_exists($k, $row) && trim((string)$row[$k]) !== '') {
                $q[$k] = ($k === 'grade' || $k === 'outs') ? intval($row[$k]) : (string)$row[$k];
            }
        }
    } else {
        $errors[] = ['line'=>$line,'field'=>'raw_json','message'=>'新規問題の raw_json が空です。','hint'=>'新規問題は構造が複雑なため、raw_json列に1問分のJSONを入れてください。'];
        return null;
    }
    if (!is_array($q)) return null;
    if ($id === '') {
        $type = (string)($q['type'] ?? ($row['type'] ?? ''));
        if (!in_array($type, ['attack','defense','basic'], true)) {
            $errors[] = ['line'=>$line,'field'=>'type','message'=>'問題IDが空ですが、種別typeを判定できません。','hint'=>'typeに attack / defense / basic のいずれかを指定するか、raw_json内にtypeを入れてください。'];
            return null;
        }
        $id = next_question_id_admin($type, $counters);
        while (isset($existing_ids[$id])) $id = next_question_id_admin($type, $counters);
        $q['id'] = $id;
    }
    $q['id'] = normalize_question_id_admin($q['id'] ?? $id);
    apply_question_csv_editable_columns_admin($q, $row, $errors);
    return $q;
}
function question_csv_preview_admin($csv_text) {
    $questions = load_questions_admin();
    $by_id = [];
    foreach ($questions as $q) if (isset($q['id'])) $by_id[$q['id']] = $q;
    $existing_ids = array_fill_keys(array_keys($by_id), true);
    $counters = build_question_id_counters_admin($questions);
    $parsed = parse_question_csv_admin($csv_text);
    $items = [];
    $errors = array_merge($parsed['errors'], question_csv_header_validation_admin($parsed['headers'] ?? []));
    $seen_csv_ids = [];
    foreach ($parsed['rows'] as $idx=>$row) {
        $line = intval($row['_line'] ?? ($idx+2));
        $input_id = normalize_question_id_admin($row['id'] ?? '');
        $existing = $input_id !== '' && isset($by_id[$input_id]) ? $by_id[$input_id] : null;
        $rowErrors = [];
        $q = parse_question_from_csv_row_admin($row, $existing, $counters, $existing_ids, $rowErrors);
        $errors = array_merge($errors, $rowErrors);
        if (!$q) {
            $items[] = ['row_key'=>'row_'.$line,'line'=>$line,'id'=>$input_id,'proposed_id'=>'','status'=>'error','status_label'=>'エラー','change_kind'=>'error','message'=>'読み込みできません。','errors'=>$rowErrors];
            continue;
        }
        $id = (string)($q['id'] ?? '');
        if ($id === '') {
            $rowErrors[] = ['line'=>$line,'field'=>'id','message'=>'問題IDが空です。','hint'=>'ID空欄の場合でもtypeから候補IDを自動割り振りできる必要があります。'];
        }
        if (isset($seen_csv_ids[$id])) {
            $rowErrors[] = ['line'=>$line,'field'=>'id','message'=>'CSV内で問題IDが重複しています：'.$id,'hint'=>'同じIDの行を1つにまとめるか、片方に別IDを設定してください。'];
        }
        $seen_csv_ids[$id] = true;
        $current = $by_id[$id] ?? null;
        $is_new = $current === null;
        $status = normalize_csv_status_admin($row['status'] ?? '', $is_new, $is_new ? 'draft' : question_status_get($id));
        if ($status === '__invalid__') {
            $rowErrors[] = ['line'=>$line,'field'=>'status','message'=>'statusの値が不正です。','hint'=>'published / draft / disabled のいずれか、または 公開 / 下書き / 停止 を指定してください。'];
            $status = $is_new ? 'draft' : question_status_get($id);
        }
        $ids = $existing_ids;
        if (!$is_new) unset($ids[$id]);
        $vErr = validate_question_admin($q, $ids, $is_new ? '' : $id);
        if ($vErr !== '') $rowErrors[] = ['line'=>$line,'field'=>'question','message'=>$vErr,'hint'=>'CSVのouts/outs_scope/visual_batter_runner/visual_ball_path列、またはraw_jsonの必須項目、選択肢数、scoreの組み合わせを確認してください。'];
        $change = 'unchanged';
        if ($is_new) $change = 'new';
        elseif ($current != $q || question_status_get($id) !== $status) $change = 'update';
        if ($rowErrors) $change = 'error';
        $items[] = [
            'row_key'=>'row_'.$line,
            'line'=>$line,
            'id'=>$input_id,
            'proposed_id'=>$id,
            'title'=>question_title_for_notice($q),
            'type'=>(string)($q['type'] ?? ''),
            'grade'=>$q['grade'] ?? '',
            'status'=>$status,
            'status_label'=>question_status_label_admin($status),
            'change_kind'=>$change,
            'change_label'=>($change==='new'?'新規追加':($change==='update'?'更新候補':($change==='unchanged'?'変更なし':'エラー'))),
            'message'=>$input_id === '' ? 'ID空欄のため候補IDを自動割り振りしました。' : '',
            'question'=>$q,
            'errors'=>$rowErrors
        ];
        if ($change !== 'error') $existing_ids[$id] = true;
        $errors = array_merge($errors, $rowErrors);
    }
    $preview_questions = $questions;
    foreach ($items as $it) {
        if (($it['change_kind'] ?? '') === 'error' || ($it['change_kind'] ?? '') === 'unchanged') continue;
        $qid = $it['proposed_id'];
        $replaced = false;
        foreach ($preview_questions as $i=>$q) {
            if (($q['id'] ?? '') === $qid) { $preview_questions[$i] = $it['question']; $replaced = true; break; }
        }
        if (!$replaced) $preview_questions[] = $it['question'];
    }
    $status_overrides = [];
    foreach ($items as $it) {
        if (($it['change_kind'] ?? '') !== 'error' && !empty($it['proposed_id'])) $status_overrides[$it['proposed_id']] = $it['status'] ?? 'published';
    }
    // プレビューでは確認しやすいように、追加された問題を先頭に表示する。
    // 表示順: 新規追加 → 更新候補 → エラー → 変更なし。
    // 同じ判定内ではCSV上の行番号順を維持する。
    $order = ['new'=>0, 'update'=>1, 'error'=>2, 'unchanged'=>3];
    usort($items, function($a, $b) use ($order) {
        $ak = $a['change_kind'] ?? 'error';
        $bk = $b['change_kind'] ?? 'error';
        $ao = $order[$ak] ?? 9;
        $bo = $order[$bk] ?? 9;
        if ($ao !== $bo) return $ao <=> $bo;
        return intval($a['line'] ?? 0) <=> intval($b['line'] ?? 0);
    });
    $mismatches = game_config_mismatches_admin($preview_questions, load_game_config_admin(), $status_overrides);
    return ['headers'=>$parsed['headers'],'items'=>$items,'errors'=>$errors,'summary'=>csv_preview_summary_admin($items),'game_config_mismatches'=>$mismatches];
}
function csv_preview_summary_admin($items) {
    $s = ['new'=>0,'update'=>0,'unchanged'=>0,'error'=>0];
    foreach ($items as $it) {
        $k = $it['change_kind'] ?? 'error';
        if (!isset($s[$k])) $s[$k] = 0;
        $s[$k]++;
    }
    return $s;
}
function game_config_counts_from_questions_admin($questions, $status_overrides=[]) {
    $total = 0; $a=0; $d=0; $b=0;
    foreach ($questions as $q) {
        $id = (string)($q['id'] ?? '');
        $st = isset($status_overrides[$id]) ? question_status_valid($status_overrides[$id]) : question_status_get($id);
        if ($st === 'draft' || $st === 'disabled') continue;
        $total++;
        $t = $q['type'] ?? '';
        if ($t === 'attack') $a++;
        elseif ($t === 'basic') $b++;
        elseif ($t === 'defense') $d++;
    }
    return ['questionPoolTotal'=>$total,'attackQuestionPool'=>$a,'defenseQuestionPool'=>$d,'basicQuestionPool'=>$b];
}
function game_config_mismatches_admin($questions, $config, $status_overrides=[]) {
    $actual = game_config_counts_from_questions_admin($questions, $status_overrides);
    $m = [];
    foreach ($actual as $key=>$val) {
        $top = $config[$key] ?? null;
        $game = $config['game'][$key] ?? null;
        if ($top !== null && intval($top) !== intval($val)) $m[] = ['field'=>$key,'location'=>'root','current'=>intval($top),'actual'=>$val,'message'=>$key.' が実データと一致しません。'];
        if ($game !== null && intval($game) !== intval($val)) $m[] = ['field'=>'game.'.$key,'location'=>'game','current'=>intval($game),'actual'=>$val,'message'=>'game.'.$key.' が実データと一致しません。'];
    }
    return $m;
}
function update_game_config_counts_admin($questions) {
    $config = load_game_config_admin();
    $counts = game_config_counts_from_questions_admin($questions);
    foreach ($counts as $k=>$v) {
        $config[$k] = $v;
        if (!isset($config['game']) || !is_array($config['game'])) $config['game'] = [];
        $config['game'][$k] = $v;
    }
    return save_game_config_admin($config);
}

function question_required_score_set_admin($choices) {
    if (!is_array($choices) || count($choices) !== 3) return false;
    $scores = [];
    foreach ($choices as $c) {
        if (!is_array($c)) return false;
        if (trim((string)($c['text'] ?? '')) === '') return false;
        if (trim((string)($c['explain'] ?? '')) === '') return false;
        if (!isset($c['score'])) return false;
        $scores[] = intval($c['score']);
    }
    sort($scores);
    return $scores === [0,1,3];
}

function runners_from_stage_admin($stage) {
    $stage = (string)$stage;
    if ($stage === '1B') return ['1B'];
    if ($stage === '2B') return ['2B'];
    if ($stage === '3B') return ['3B'];
    if ($stage === '1B2B') return ['1B','2B'];
    if ($stage === '1B3B') return ['1B','3B'];
    if ($stage === '2B3B') return ['2B','3B'];
    if ($stage === '1B2B3B') return ['1B','2B','3B'];
    return [];
}
function normalize_question_stage_visual_admin($q) {
    if (!is_array($q)) return $q;
    if (!isset($q['visual']) || !is_array($q['visual'])) $q['visual'] = [];
    $q['visual']['runners'] = runners_from_stage_admin($q['stage'] ?? '');
    return $q;
}
function validate_question_admin($q, $existing_ids=[], $original_id='') {
    if (!is_array($q)) return '問題データはJSONオブジェクトである必要があります。';
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($q['id'] ?? ''));
    if ($id === '') return 'id は必須です。';
    if ($id !== (string)($q['id'] ?? '')) return 'id に使用できない文字が含まれています。';
    if (isset($existing_ids[$id]) && $id !== $original_id) return '同じプレイヤーIDの問題がすでに存在します。';
    $type = $q['type'] ?? '';
    if (!in_array($type, ['attack','defense','basic'], true)) return 'type は attack / defense / basic のいずれかにしてください。';
    if (!isset($q['grade']) || intval($q['grade']) < 2 || intval($q['grade']) > 6) return 'grade は2〜6の範囲で必須です。';
    if (!isset($q['stage']) || trim((string)$q['stage']) === '') return 'stage（判断対象・出題分類）は必須です。';
    $has_outs = array_key_exists('outs', $q) && $q['outs'] !== '' && $q['outs'] !== null;
    $outs_scope = strtolower(trim((string)($q['outs_scope'] ?? '')));
    $is_common_outs_scope = ($outs_scope === 'common');
    $type_for_outs = (string)($q['type'] ?? '');
    $requires_outs = in_array($type_for_outs, ['attack','defense'], true);
    if ($outs_scope !== '' && !$is_common_outs_scope) return 'outs_scope は common のみ指定できます。';
    if ($has_outs && $is_common_outs_scope) return 'outs と outs_scope: common は同時に指定できません。';
    if ($requires_outs && !$has_outs && !$is_common_outs_scope) return '攻撃・守備問題ではアウト条件が必須です。0・1・2、またはアウト共通を指定してください。';
    if ($has_outs && !in_array(intval($q['outs']), [0,1,2], true)) return 'outs は0・1・2のいずれかにしてください。アウト数に依存しない問題は outs_scope: common を指定してください。';
    if (!isset($q['ball_tag']) || trim((string)$q['ball_tag']) === '') return 'ball_tag は必須です。';
    if (!isset($q['situation']) || trim((string)$q['situation']) === '') return 'situation（問題文）は必須です。';
    if (!isset($q['prompt']) || trim((string)$q['prompt']) === '') return 'prompt は必須です。';
    if (strpos((string)$q['situation'], '0アウト') !== false || strpos((string)$q['prompt'], '0アウト') !== false) return '表記ルール：0アウトではなく「ノーアウト」を使用してください。';
    if (strpos((string)$q['situation'], '送球しよう') !== false || strpos((string)$q['prompt'], '送球しよう') !== false) return '表現ルール：「送球しよう」は避け、自然な行動表現にしてください。';
    if (!isset($q['visual']) || !is_array($q['visual'])) return 'visual は必須です。';
    if (!array_key_exists('runners', $q['visual']) || !is_array($q['visual']['runners'])) return 'visual.runners は配列で必須です。';
    if (!array_key_exists('batter_runner', $q['visual']) || !is_bool($q['visual']['batter_runner'])) return 'visual.batter_runner は必須です。true または false を指定してください。';
    if (!isset($q['visual']['ball_path']) || trim((string)$q['visual']['ball_path']) === '') return 'visual.ball_path は必須です。';
    $allowed_positions = ['P','C','1B','2B','SS','3B','LF','CF','RF','BASIC'];
    if ($type === 'defense') {
        if (!isset($q['choices_by_position']) || !is_array($q['choices_by_position']) || !count($q['choices_by_position'])) return '守備問題は choices_by_position が必要です。';
        foreach ($q['choices_by_position'] as $pos=>$choices) {
            if (!in_array($pos, $allowed_positions, true)) return 'choices_by_position に不正な守備位置があります：' . $pos;
            if (!question_required_score_set_admin($choices)) return '各守備位置の選択肢は必ず3個、点数は3点・1点・0点を1つずつ、text/explain必須にしてください：' . $pos;
        }
        // positions は表示・分類用に choices_by_position より広い対象を持つ既存問題があるため、
        // ここでは値の妥当性だけを確認し、全positionsにchoicesがあることは必須にしない。
        if (isset($q['positions']) && is_array($q['positions'])) {
            foreach ($q['positions'] as $pos) {
                if (!in_array($pos, $allowed_positions, true)) return 'positions に不正な守備位置があります：' . $pos;
            }
        }
    } else {
        if (!isset($q['choices']) || !is_array($q['choices'])) return '攻撃/基本問題は choices が必要です。';
        if (!question_required_score_set_admin($q['choices'])) return '選択肢は必ず3個、点数は3点・1点・0点を1つずつ、text/explain必須にしてください。';
    }
    if ($type === 'attack' && !isset($q['runner']) && !isset($q['role'])) return '攻撃問題は runner または role を入力してください。';
    return '';
}
function delete_block_reason_admin($questions, $target_id) {
    $target = null;
    foreach ($questions as $q) {
        if (($q['id'] ?? '') === $target_id) { $target = $q; break; }
    }
    if (!$target) return '問題が見つかりません。';
    if (!empty($target['protected']) || !empty($target['must_keep'])) return 'この問題は protected/must_keep 指定のため削除できません。';
    $must_keep_ids = ['D411','D412','D413','D414'];
    if (in_array($target_id, $must_keep_ids, true)) return 'この問題は牽制時のタッチプレイ基本問題として必須追加されたため削除できません。';
    if (($target['theme'] ?? '') === 'pickoff_tag_play_basic') return 'タッチプレイ基本問題はカリキュラム上必須のため削除できません。';
    if (($target['type'] ?? '') === 'basic') return '基本動作問題は低学年モードの土台になるため、管理画面からは削除できません。修正で対応してください。';
    $type = $target['type'] ?? '';
    $stage = $target['stage'] ?? '';
    $outs = strval($target['outs'] ?? '');
    $grade = intval($target['grade'] ?? 0);
    $positions = [];
    if ($type === 'defense') {
        if (isset($target['positions']) && is_array($target['positions'])) $positions = $target['positions'];
        elseif (isset($target['choices_by_position']) && is_array($target['choices_by_position'])) $positions = array_keys($target['choices_by_position']);
    } else {
        $positions = ['ATTACK_OR_BASIC'];
    }
    foreach ($positions as $pos) {
        $count_same_slot = 0;
        foreach ($questions as $q) {
            if (($q['id'] ?? '') === $target_id) continue;
            if (($q['type'] ?? '') !== $type) continue;
            if (($q['stage'] ?? '') !== $stage) continue;
            if (strval($q['outs'] ?? '') !== $outs) continue;
            if (intval($q['grade'] ?? 0) !== $grade) continue;
            if ($type === 'defense') {
                $qpos = [];
                if (isset($q['positions']) && is_array($q['positions'])) $qpos = $q['positions'];
                elseif (isset($q['choices_by_position']) && is_array($q['choices_by_position'])) $qpos = array_keys($q['choices_by_position']);
                if (!in_array($pos, $qpos, true)) continue;
            }
            $count_same_slot++;
        }
        if ($count_same_slot < 1) {
            return 'この問題を削除すると「種別=' . $type . ' / 学年=' . $grade . ' / 走者=' . $stage . ' / アウト=' . $outs . ($type === 'defense' ? ' / 守備位置=' . $pos : '') . '」の出題枠が空になるため削除できません。修正または代替問題を追加してから削除してください。';
        }
    }
    return '';
}
function normalize_question_id_admin($value) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$value);
}

function clean_features_admin($features, $type) {
    $allowed = ['mistake_review'=>true,'device_transfer'=>true,'quiz_master'=>true,'admin_mode'=>true];
    if (!is_array($features) || !count($features)) return default_features_for_code_type($type);
    $out = [];
    foreach ($features as $f) {
        $f = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$f);
        if ($f !== '' && isset($allowed[$f]) && !in_array($f, $out, true)) $out[] = $f;
    }
    if ($type !== 'admin') $out = array_values(array_filter($out, function($f){ return $f !== 'admin_mode'; }));
    return count($out) ? $out : default_features_for_code_type($type);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') admin_json(405, ['ok'=>false,'error'=>'method not allowed']);
$data = request_data_admin();
$action = normalize_admin_action($data['action'] ?? '');

if ($action === 'login') {
    $admin_id = strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', strtoupper($data['admin_id'] ?? '')));
    $password = (string)($data['password'] ?? '');
    $attemptStatus = admin_login_attempt_status($admin_id);
    if (!empty($attemptStatus['blocked'])) {
        audit_log_admin_event([
            'admin_label'=>$admin_id,
            'action'=>'login',
            'result'=>'blocked',
            'message'=>'super admin login rate limited'
        ]);
        admin_json(429, [
            'ok'=>false,
            'error'=>'login_rate_limited',
            'message'=>'ログイン試行回数が上限に達しました。15分ほど時間を置いてから再度お試しください。',
            'retry_after'=>$attemptStatus['retry_after'] ?? 900
        ]);
    }
    $db = load_super_admin_db();
    $salt = $db['salt_id'] ?? 'YAKYU_YAROUZE_SUPER_ADMIN_V570';
    $id_hash = super_admin_hash_for_id($admin_id, $salt);
    $row = $db['admins'][$id_hash] ?? null;
    if (!$row || empty($row['enabled']) || !hash_equals($row['password_hash'] ?? '', super_admin_password_hash($password, $salt))) {
        record_admin_login_attempt($admin_id, false);
        audit_log_admin_event([
            'admin_label'=>$admin_id,
            'action'=>'login',
            'result'=>'failed',
            'message'=>'invalid super admin credential'
        ]);
        admin_json(403, ['ok'=>false,'error'=>'login_failed','message'=>'最高位IDまたはパスワードが違います。']);
    }
    record_admin_login_attempt($admin_id, true);
    $label = $row['label'] ?? '最高位管理者';
    $token = create_admin_session($label);
    audit_log_admin_event([
        'admin_label'=>$label,
        'action'=>'login',
        'result'=>'success',
        'message'=>'super admin login'
    ]);
    admin_json(200, ['ok'=>true,'token'=>$token,'admin_label'=>$label,'expires_in'=>21600]);
}

$session = require_admin_for_action($data);
$admin_label = $session['admin_label'] ?? '最高位管理者';


function request_log_file_admin() {
    $candidates = [
        feature_scores_dir() . '/request_log.csv',
        __DIR__ . '/../requests/request_log.csv',
        sys_get_temp_dir() . '/request_log.csv'
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) return $candidate;
    }
    return feature_scores_dir() . '/request_log.csv';
}
function normalize_request_status_admin($status) {
    $status = trim((string)$status);
    if ($status === '' || $status === '未対応') return '検討中';
    if ($status === '対応済み') return '修正反映';
    $allowed = ['検討中','修正反映','対応不可','取消済み'];
    return in_array($status, $allowed, true) ? $status : '検討中';
}
function request_status_options_admin() {
    return ['検討中','修正反映','対応不可'];
}
function read_requests_admin() {
    $file = request_log_file_admin();
    if (!is_file($file)) return ['file'=>$file,'header'=>['id','submitted_at','player_id','grade','position','request_type','title','detail','status','handled_at','handled_note'],'rows'=>[]];
    $fp = fopen($file, 'r');
    if (!$fp) admin_json(500, ['ok'=>false,'error'=>'cannot_open_request_file','message'=>'要望データファイルを開けません。']);
    if (!flock($fp, LOCK_SH)) { fclose($fp); admin_json(500, ['ok'=>false,'error'=>'cannot_lock_request_file','message'=>'要望データファイルをロックできません。']); }
    $header = fgetcsv($fp);
    if (!is_array($header) || !count($header)) $header = ['id','submitted_at','player_id','grade','position','request_type','title','detail','status','handled_at','handled_note'];
    $rows = [];
    while (($row = fgetcsv($fp)) !== false) {
        while (count($row) < count($header)) $row[] = '';
        $assoc = [];
        foreach ($header as $i=>$h) $assoc[$h] = $row[$i] ?? '';
        if (($assoc['id'] ?? '') === '') continue;
        $assoc['status'] = normalize_request_status_admin($assoc['status'] ?? '検討中');
        $rows[] = $assoc;
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return ['file'=>$file,'header'=>$header,'rows'=>$rows];
}
function write_requests_admin($file, $header, $rows) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    rewind($fp);
    ftruncate($fp, 0);
    fputcsv($fp, $header);
    foreach ($rows as $assoc) {
        $line = [];
        foreach ($header as $h) {
            $value = $assoc[$h] ?? '';
            if (in_array($h, ['player_id','title','detail','handled_note'], true)) $value = safe_csv_cell_feature($value);
            $line[] = $value;
        }
        fputcsv($fp, $line);
    }
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}
function summarize_requests_admin($rows) {
    $summary = ['total'=>count($rows),'検討中'=>0,'修正反映'=>0,'対応不可'=>0,'取消済み'=>0];
    foreach ($rows as $r) {
        $st = normalize_request_status_admin($r['status'] ?? '検討中');
        if (!isset($summary[$st])) $summary[$st] = 0;
        $summary[$st]++;
    }
    return $summary;
}


if ($action === 'request_notification_get') {
    $settings = load_request_notification_settings();
    admin_json(200, ['ok'=>true,'settings'=>$settings]);
}

if ($action === 'request_notification_save') {
    $emails = normalize_request_notification_emails($data['emails'] ?? ($data['emails_text'] ?? ''));
    $enabled = !empty($data['enabled']);
    if ($enabled && !count($emails)) {
        admin_json(400, ['ok'=>false,'error'=>'email_required','message'=>'通知をONにする場合は、通知先メールアドレスを1件以上入力してください。']);
    }
    $from_email = normalize_request_notification_from($data['from_email'] ?? 'no-reply@realemotionfactory.com');
    $subject = normalize_request_notification_subject($data['subject'] ?? '新規要望メール通知');
    $settings = [
        'enabled'=>$enabled,
        'emails'=>$emails,
        'from_email'=>$from_email,
        'subject'=>$subject,
        'updated_at'=>date('Y-m-d H:i:s'),
        'updated_by'=>$admin_label
    ];
    if (!save_request_notification_settings($settings)) {
        admin_json(500, ['ok'=>false,'error'=>'cannot_save_request_notification_settings','message'=>'新規要望メール通知設定を保存できません。scores フォルダの権限を確認してください。']);
    }
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'request_notification_save','result'=>'success','target_type'=>'request_notification','message'=>'enabled=' . ($enabled ? '1' : '0') . ' / emails=' . count($emails)]);
    admin_json(200, ['ok'=>true,'message'=>'新規要望メール通知設定を保存しました。','settings'=>load_request_notification_settings()]);
}

if ($action === 'request_notification_test') {
    $settings = load_request_notification_settings();
    // v717: 17:51のiOSメールで正常表示されたv707相当のテストメール形式へ戻します。
    $subject = '【野球やろうぜ！】新規要望メール通知テスト';
    $body = "これは新規要望メール通知のテストです。\n\n"
        . "送信日時: " . date('Y-m-d H:i:s') . "\n"
        . "操作管理者: " . $admin_label . "\n\n"
        . "このメールが届けば、新規要望登録時の通知先設定は有効です。\n";
    $result = send_request_notification_email($subject, $body, $settings);
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'request_notification_test','result'=>($result['failed'] ?? 0) > 0 ? 'partial_or_failed' : 'success','target_type'=>'request_notification','message'=>$result['message'] ?? '']);
    admin_json(200, ['ok'=>true,'message'=>$result['message'] ?? 'テスト送信を実行しました。','result'=>$result]);
}




if ($action === 'request_list') {
    $db = read_requests_admin();
    $rows = array_reverse($db['rows']);
    $rows = array_slice($rows, 0, 300);
    admin_json(200, ['ok'=>true,'requests'=>$rows,'summary'=>summarize_requests_admin($db['rows']),'status_options'=>request_status_options_admin()]);
}

if ($action === 'request_update_status') {
    $request_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['request_id'] ?? ($data['id'] ?? '')));
    $status = normalize_request_status_admin($data['status'] ?? '');
    $note = trim((string)($data['note'] ?? ''));
    if ($request_id === '') admin_json(400, ['ok'=>false,'error'=>'request_id_required','message'=>'要望IDが指定されていません。']);
    if (!in_array($status, request_status_options_admin(), true)) admin_json(400, ['ok'=>false,'error'=>'invalid_status','message'=>'ステータスは検討中・修正反映・対応不可から選択してください。']);
    $db = read_requests_admin();
    $found = false;
    $now = date('Y-m-d H:i:s');
    foreach ($db['rows'] as &$row) {
        if (($row['id'] ?? '') === $request_id) {
            $found = true;
            $row['status'] = $status;
            $row['handled_at'] = $now;
            $row['handled_note'] = $note !== '' ? $note : ('最高位管理者がステータスを「' . $status . '」に変更');
            break;
        }
    }
    unset($row);
    if (!$found) admin_json(404, ['ok'=>false,'error'=>'request_not_found','message'=>'対象の要望データが見つかりません。']);
    if (!write_requests_admin($db['file'], $db['header'], $db['rows'])) admin_json(500, ['ok'=>false,'error'=>'cannot_write_request_file','message'=>'要望データを保存できません。scores/request_log.csv の権限を確認してください。']);
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'request_update_status','result'=>'success','target_type'=>'request','target_hash_prefix'=>$request_id,'message'=>'status=' . $status]);
    admin_json(200, ['ok'=>true,'message'=>'要望ステータスを更新しました。']);
}

if ($action === 'request_delete') {
    $request_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['request_id'] ?? ($data['id'] ?? '')));
    if ($request_id === '') admin_json(400, ['ok'=>false,'error'=>'request_id_required','message'=>'要望IDが指定されていません。']);
    $db = read_requests_admin();
    $before = count($db['rows']);
    $deleted = null;
    $rows = [];
    foreach ($db['rows'] as $row) {
        if (($row['id'] ?? '') === $request_id) { $deleted = $row; continue; }
        $rows[] = $row;
    }
    if ($deleted === null || count($rows) === $before) admin_json(404, ['ok'=>false,'error'=>'request_not_found','message'=>'対象の要望データが見つかりません。']);
    if (!write_requests_admin($db['file'], $db['header'], $rows)) admin_json(500, ['ok'=>false,'error'=>'cannot_write_request_file','message'=>'要望データを保存できません。scores/request_log.csv の権限を確認してください。']);
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'request_delete','result'=>'success','target_type'=>'request','target_hash_prefix'=>$request_id,'message'=>'deleted title=' . ($deleted['title'] ?? '')]);
    admin_json(200, ['ok'=>true,'message'=>'要望データを削除しました。']);
}

function load_player_registry_admin() {
    $rows = read_csv_assoc_all(feature_scores_dir() . '/player_registry.csv', 20000);
    $players = [];
    foreach ($rows as $r) {
        $pid = $r['player_id'] ?? '';
        if ($pid === '') continue;
        $players[$pid] = [
            'player_id'=>$pid,
            'client_hash'=>$r['client_hash'] ?? '',
            'created_at'=>$r['created_at'] ?? '',
            'last_login_at'=>$r['last_login_at'] ?? ''
        ];
    }
    return $players;
}

function read_player_id_change_history_admin() {
    $rows = read_recent_csv_assoc(feature_scores_dir() . '/player_id_change_history.csv', 5000);
    return is_array($rows) ? $rows : [];
}
function player_id_change_stats_admin() {
    $hist = read_player_id_change_history_admin();
    $stats = [];
    foreach ($hist as $r) {
        if (($r['result'] ?? '') !== 'success') continue;
        $old = $r['old_player_id'] ?? '';
        $new = $r['new_player_id'] ?? '';
        foreach ([$old, $new] as $pid) {
            if ($pid === '') continue;
            if (!isset($stats[$pid])) $stats[$pid] = ['count'=>0,'last_changed_at'=>'','history'=>[]];
            $stats[$pid]['count']++;
            $stats[$pid]['history'][] = $r;
            if (($r['changed_at'] ?? '') > ($stats[$pid]['last_changed_at'] ?? '')) $stats[$pid]['last_changed_at'] = $r['changed_at'] ?? '';
        }
    }
    foreach ($stats as &$s) {
        usort($s['history'], function($a,$b){ return strcmp($b['changed_at'] ?? '', $a['changed_at'] ?? ''); });
        $s['history'] = array_slice($s['history'], 0, 10);
    }
    unset($s);
    return $stats;
}

function build_player_admin_list($query='') {
    $players = load_player_registry_admin();
    $analytics = build_user_analytics();
    $susp = load_player_suspensions();
    $change_stats = player_id_change_stats_admin();
    $by_pid = [];
    foreach (($analytics['recent_users'] ?? []) as $p) {
        if (($p['player_id'] ?? '') !== '') $by_pid[$p['player_id']] = $p;
    }
    foreach (($analytics['top_players'] ?? []) as $p) {
        if (($p['player_id'] ?? '') !== '') $by_pid[$p['player_id']] = array_merge($by_pid[$p['player_id']] ?? [], $p);
    }
    foreach ($by_pid as $pid=>$p) {
        if (!isset($players[$pid])) {
            $players[$pid] = [
                'player_id'=>$pid,
                'client_hash'=>'',
                'created_at'=>$p['registered_at'] ?? '',
                'last_login_at'=>$p['last_login_at'] ?? ''
            ];
        }
    }
    $list = [];
    foreach ($players as $pid=>$p) {
        $a = $by_pid[$pid] ?? [];
        $rec = $susp['players'][$pid] ?? [];
        $status = (($rec['status'] ?? '') === 'suspended') ? 'suspended' : 'active';
        $row = [
            'player_id'=>$pid,
            'status'=>$status,
            'created_at'=>$p['created_at'] ?? ($a['registered_at'] ?? ''),
            'last_login_at'=>$p['last_login_at'] ?? ($a['last_login_at'] ?? ''),
            'last_played_at'=>$a['last_played_at'] ?? '',
            'play_count'=>$a['play_count'] ?? 0,
            'correct_count'=>$a['correct_count'] ?? 0,
            'best_score'=>$a['best_score'] ?? 0,
            'average_score'=>$a['average_score'] ?? 0,
            'grades'=>$a['grades'] ?? [],
            'positions'=>$a['positions'] ?? [],
            'suspended_at'=>$rec['suspended_at'] ?? '',
            'suspended_by'=>$rec['suspended_by'] ?? '',
            'suspend_reason'=>$rec['reason'] ?? '',
            'resumed_at'=>$rec['resumed_at'] ?? '',
            'resumed_by'=>$rec['resumed_by'] ?? '',
            'id_change_count'=>$change_stats[$pid]['count'] ?? 0,
            'id_change_last_at'=>$change_stats[$pid]['last_changed_at'] ?? '',
            'id_change_history'=>$change_stats[$pid]['history'] ?? []
        ];
        if ($query !== '') {
            $hay = json_encode($row, JSON_UNESCAPED_UNICODE);
            if (mb_stripos($hay, $query, 0, 'UTF-8') === false) continue;
        }
        $list[] = $row;
    }
    usort($list, function($a,$b){
        $al = $a['last_played_at'] ?: $a['last_login_at'] ?: $a['created_at'];
        $bl = $b['last_played_at'] ?: $b['last_login_at'] ?: $b['created_at'];
        return strcmp($bl, $al);
    });
    return $list;
}


function self_code_status_admin($type='invite', $query='') {
    $type = $type === 'admin' ? 'admin' : 'invite';
    $players = build_player_admin_list($query);
    $code_db = load_code_db_by_type($type);
    $by_owner = [];
    foreach (($code_db['codes'] ?? []) as $hash=>$row) {
        if (empty($row['self_issued'])) continue;
        $owner = normalize_player_id($row['owner_player_id'] ?? ($row['issued_to_player_id'] ?? ''));
        if ($owner === '') continue;
        $used_by = isset($row['used_by']) && is_array($row['used_by']) ? $row['used_by'] : [];
        $by_owner[canonical_player_id($owner)] = [
            'hash_prefix'=>substr($hash, 0, 12),
            'code'=>$row['plain_code'] ?? '',
            'enabled'=>!empty($row['enabled']),
            'issued_at'=>$row['issued_at'] ?? ($row['created_at'] ?? ''),
            'label'=>$row['label'] ?? '',
            'used_count'=>count($used_by),
            'max_uses'=>intval($row['max_uses'] ?? 1),
            'used_by'=>array_keys($used_by),
            'features'=>array_values($row['features'] ?? []),
            'admin_name_rule_acknowledged'=>!empty($row['admin_name_rule_acknowledged'])
        ];
    }
    $rows = [];
    $issued = 0;
    $total = 0;
    foreach ($players as $p) {
        $pid = $p['player_id'] ?? '';
        if ($pid === '') continue;
        $total++;
        $rec = $by_owner[canonical_player_id($pid)] ?? null;
        if ($rec) {
            $issued++;
            continue;
        }
        $rows[] = [
            'player_id'=>$pid,
            'status'=>$p['status'] ?? 'active',
            'registered_at'=>$p['created_at'] ?? '',
            'last_login_at'=>$p['last_login_at'] ?? '',
            'last_played_at'=>$p['last_played_at'] ?? '',
            'has_code'=>false
        ];
    }
    usort($rows, function($a,$b){
        $ad = $a['last_login_at'] ?: $a['last_played_at'] ?: $a['registered_at'];
        $bd = $b['last_login_at'] ?: $b['last_played_at'] ?: $b['registered_at'];
        if ($ad !== $bd) return strcmp($bd, $ad);
        return strcmp($a['player_id'] ?? '', $b['player_id'] ?? '');
    });
    return [
        'type'=>$type,
        'rows'=>$rows,
        'summary'=>[
            'total'=>$total,
            'issued'=>$issued,
            'not_issued'=>count($rows)
        ]
    ];
}
function self_invite_status_admin($query='') {
    return self_code_status_admin('invite', $query);
}
function self_admin_status_admin($query='') {
    return self_code_status_admin('admin', $query);
}

function self_unissued_code_status_admin($query='') {
    $players = build_player_admin_list($query);
    $invite_db = load_code_db_by_type('invite');
    $admin_db = load_code_db_by_type('admin');
    $invite_owners = [];
    foreach (($invite_db['codes'] ?? []) as $hash=>$row) {
        if (empty($row['self_issued'])) continue;
        $owner = normalize_player_id($row['owner_player_id'] ?? ($row['issued_to_player_id'] ?? ''));
        if ($owner === '') continue;
        $invite_owners[canonical_player_id($owner)] = true;
    }
    $admin_owners = [];
    foreach (($admin_db['codes'] ?? []) as $hash=>$row) {
        if (empty($row['self_issued'])) continue;
        $owner = normalize_player_id($row['owner_player_id'] ?? ($row['issued_to_player_id'] ?? ''));
        if ($owner === '') continue;
        $admin_owners[canonical_player_id($owner)] = true;
    }

    // v800: 「未取得ID」は、発行台帳（invite_codes/admin_codes）だけでなく、
    // 実際の機能解放状態（player_features.json）とも整合させる。
    // 旧仕様や手動反映により、コード台帳に self_issued が残っていなくても
    // player_features.json に招待ID由来/管理者ID由来の機能があれば取得済み扱いにする。
    $feature_db = load_player_feature_db();
    $feature_invite_owners = [];
    $feature_admin_owners = [];
    foreach (($feature_db['players'] ?? []) as $pid=>$feature_row) {
        $pid_norm = normalize_player_id($pid);
        if ($pid_norm === '') continue;
        $pkey = canonical_player_id($pid_norm);
        $flags = isset($feature_row['flags']) && is_array($feature_row['flags']) ? $feature_row['flags'] : [];
        $sources = isset($feature_row['sources']) && is_array($feature_row['sources']) ? $feature_row['sources'] : [];
        foreach ($sources as $feature=>$src) {
            if (!is_array($src)) continue;
            if (($src['type'] ?? '') !== 'invite') continue;
            $feature = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$feature);
            if ($feature === '' || $feature === 'admin_mode') continue;
            if (!empty($flags[$feature])) {
                $feature_invite_owners[$pkey] = true;
                break;
            }
        }
        if (!empty($flags['admin_mode'])) {
            $feature_admin_owners[$pkey] = true;
        }
    }

    $rows = [];
    $total = 0;
    $invite_issued = 0;
    $admin_issued = 0;
    $any_issued = 0;
    foreach ($players as $p) {
        $pid = $p['player_id'] ?? '';
        if ($pid === '') continue;
        $total++;
        $key = canonical_player_id($pid);
        $has_invite = !empty($invite_owners[$key]) || !empty($feature_invite_owners[$key]);
        $has_admin = !empty($admin_owners[$key]) || !empty($feature_admin_owners[$key]);
        if ($has_invite) $invite_issued++;
        if ($has_admin) $admin_issued++;
        if ($has_invite || $has_admin) {
            $any_issued++;
            continue;
        }
        $rows[] = [
            'player_id'=>$pid,
            'status'=>$p['status'] ?? 'active',
            'registered_at'=>$p['created_at'] ?? '',
            'last_login_at'=>$p['last_login_at'] ?? '',
            'last_played_at'=>$p['last_played_at'] ?? '',
            'has_invite_code'=>false,
            'has_admin_code'=>false
        ];
    }
    usort($rows, function($a,$b){
        $ad = $a['last_login_at'] ?: $a['last_played_at'] ?: $a['registered_at'];
        $bd = $b['last_login_at'] ?: $b['last_played_at'] ?: $b['registered_at'];
        if ($ad !== $bd) return strcmp($bd, $ad);
        return strcmp($a['player_id'] ?? '', $b['player_id'] ?? '');
    });
    return [
        'type'=>'invite_admin',
        'rows'=>$rows,
        'summary'=>[
            'total'=>$total,
            'invite_issued'=>$invite_issued,
            'admin_issued'=>$admin_issued,
            'any_issued'=>$any_issued,
            'not_issued'=>count($rows)
        ]
    ];
}


function invite_unlocked_players_admin($query='') {
    $query = trim((string)$query);
    $feature_db = load_player_feature_db();
    $players = build_player_admin_list('');
    $player_info = [];
    foreach ($players as $p) {
        $pid = normalize_player_id($p['player_id'] ?? '');
        if ($pid === '') continue;
        $player_info[canonical_player_id($pid)] = $p;
    }
    $rows = [];
    foreach (($feature_db['players'] ?? []) as $pid=>$row) {
        $pid_norm = normalize_player_id($pid);
        if ($pid_norm === '') continue;
        $flags = isset($row['flags']) && is_array($row['flags']) ? $row['flags'] : [];
        $sources = isset($row['sources']) && is_array($row['sources']) ? $row['sources'] : [];
        $invite_features = [];
        $first_unlocked_at = '';
        $hashes = [];
        foreach ($sources as $feature=>$src) {
            if (!is_array($src)) continue;
            if (($src['type'] ?? '') !== 'invite') continue;
            if (empty($flags[$feature])) continue;
            $feature = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$feature);
            if ($feature === '' || $feature === 'admin_mode') continue;
            $invite_features[] = $feature;
            $t = (string)($src['unlocked_at'] ?? '');
            if ($t !== '' && ($first_unlocked_at === '' || strcmp($t, $first_unlocked_at) < 0)) $first_unlocked_at = $t;
            $h = (string)($src['id_hash_prefix'] ?? '');
            if ($h !== '') $hashes[$h] = true;
        }
        if (!count($invite_features)) continue;
        $info = $player_info[canonical_player_id($pid_norm)] ?? [];
        $out = [
            'player_id'=>$pid_norm,
            'feature_label'=>'招待ID機能',
            'invite_features'=>array_values($invite_features),
            'unlocked_at'=>$first_unlocked_at ?: ($row['updated_at'] ?? ''),
            'updated_at'=>$row['updated_at'] ?? '',
            'id_hash_prefixes'=>array_keys($hashes),
            'registered_at'=>$info['created_at'] ?? '',
            'last_login_at'=>$info['last_login_at'] ?? '',
            'last_played_at'=>$info['last_played_at'] ?? '',
            'player_status'=>$info['status'] ?? '',
            'all_flags'=>array_keys(array_filter($flags))
        ];
        if ($query !== '') {
            $hay = json_encode($out, JSON_UNESCAPED_UNICODE);
            if (mb_stripos($hay, $query, 0, 'UTF-8') === false) continue;
        }
        $rows[] = $out;
    }
    usort($rows, function($a,$b){
        $ad = $a['unlocked_at'] ?: $a['updated_at'] ?: $a['last_login_at'];
        $bd = $b['unlocked_at'] ?: $b['updated_at'] ?: $b['last_login_at'];
        if ($ad !== $bd) return strcmp($bd, $ad);
        return strcmp($a['player_id'] ?? '', $b['player_id'] ?? '');
    });
    return ['rows'=>$rows, 'summary'=>['total'=>count($rows)]];
}

function admin_unlocked_players_admin($query='') {
    $query = trim((string)$query);
    $feature_db = load_player_feature_db();
    $players = build_player_admin_list('');
    $player_info = [];
    foreach ($players as $p) {
        $pid = normalize_player_id($p['player_id'] ?? '');
        if ($pid === '') continue;
        $player_info[canonical_player_id($pid)] = $p;
    }
    $rows = [];
    foreach (($feature_db['players'] ?? []) as $pid=>$row) {
        $pid_norm = normalize_player_id($pid);
        if ($pid_norm === '') continue;
        $flags = isset($row['flags']) && is_array($row['flags']) ? $row['flags'] : [];
        if (empty($flags['admin_mode'])) continue;
        $sources = isset($row['sources']) && is_array($row['sources']) ? $row['sources'] : [];
        $src = isset($sources['admin_mode']) && is_array($sources['admin_mode']) ? $sources['admin_mode'] : [];
        $info = $player_info[canonical_player_id($pid_norm)] ?? [];
        $out = [
            'player_id'=>$pid_norm,
            'feature'=>'admin_mode',
            'feature_label'=>'管理者用モード',
            'unlocked_at'=>$src['unlocked_at'] ?? ($row['updated_at'] ?? ''),
            'updated_at'=>$row['updated_at'] ?? '',
            'source_type'=>$src['type'] ?? '',
            'id_hash_prefix'=>$src['id_hash_prefix'] ?? '',
            'registered_at'=>$info['created_at'] ?? '',
            'last_login_at'=>$info['last_login_at'] ?? '',
            'last_played_at'=>$info['last_played_at'] ?? '',
            'player_status'=>$info['status'] ?? '',
            'all_flags'=>array_keys(array_filter($flags))
        ];
        if ($query !== '') {
            $hay = json_encode($out, JSON_UNESCAPED_UNICODE);
            if (mb_stripos($hay, $query, 0, 'UTF-8') === false) continue;
        }
        $rows[] = $out;
    }
    usort($rows, function($a,$b){
        $ad = $a['unlocked_at'] ?: $a['updated_at'] ?: $a['last_login_at'];
        $bd = $b['unlocked_at'] ?: $b['updated_at'] ?: $b['last_login_at'];
        if ($ad !== $bd) return strcmp($bd, $ad);
        return strcmp($a['player_id'] ?? '', $b['player_id'] ?? '');
    });
    return ['rows'=>$rows, 'summary'=>['total'=>count($rows)]];
}





if ($action === 'dashboard') {
    $invite_db = load_invite_db();
    $admin_db = load_admin_db();
    $payload = [
        'ok'=>true,
        'invite_codes'=>summarize_code_db('invite', $invite_db),
        'admin_codes'=>summarize_code_db('admin', $admin_db),
        'self_unissued_code_status'=>self_unissued_code_status_admin(),
        'admin_unlocked_players'=>admin_unlocked_players_admin(),
        'invite_unlocked_players'=>invite_unlocked_players_admin(),
        'issue_link_settings'=>summarize_issue_link_settings_admin(),
        'id_audit_log'=>read_recent_csv_assoc(feature_scores_dir() . '/id_audit_log.csv', 300),
        'admin_audit_log'=>read_recent_csv_assoc(feature_scores_dir() . '/admin_audit_log.csv', 200),
        'user_analytics'=>build_user_analytics(),
        'access_analytics'=>build_access_analytics(),
        'restricted_features'=>all_restricted_features()
    ];
    admin_json(200, $payload);
}



if ($action === 'issue_link_settings_get') {
    admin_json(200, ['ok'=>true,'settings'=>summarize_issue_link_settings_admin()]);
}

if ($action === 'issue_link_settings_save') {
    $invite_key = sanitize_issue_link_key_admin($data['invite_key'] ?? '');
    $admin_key = sanitize_issue_link_key_admin($data['admin_key'] ?? '');
    if ($invite_key === null || $admin_key === null) {
        admin_json(400, ['ok'=>false,'error'=>'invalid_key','message'=>'取得キーは4〜64文字の半角英数字・ハイフン・アンダースコアで入力してください。']);
    }
    $settings = load_issue_link_settings_admin();
    $settings['invite_key'] = $invite_key;
    $settings['admin_key'] = $admin_key;
    $settings['updated_at'] = date('Y-m-d H:i:s');
    $settings['updated_by'] = $admin_label;
    $settings['reuse_policy'] = 'valid_until_key_changed';
    if (!save_issue_link_settings_admin($settings)) {
        admin_json(500, ['ok'=>false,'error'=>'save_failed','message'=>'取得キー設定を保存できませんでした。']);
    }
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'issue_link_settings_save','result'=>'success','target_type'=>'issue_link_settings','message'=>'invite/admin issue link keys saved; urls remain valid until keys are changed']);
    admin_json(200, ['ok'=>true,'message'=>'取得キー設定を保存しました。保存したURLはキーを更新するまで再利用できます。','settings'=>summarize_issue_link_settings_admin()]);
}

if ($action === 'release_versions') {
    admin_json(200, summarize_release_versions_admin());
}

if ($action === 'release_version_save') {
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['id'] ?? ''));
    $public_version = sanitize_public_version_admin($data['public_version'] ?? '');
    if ($public_version === '') admin_json(400, ['ok'=>false,'error'=>'invalid_version','message'=>'ユーザー向けバージョンは v1.0.0 の形式で入力してください。']);
    $release_type = sanitize_release_type_admin($data['release_type'] ?? 'patch');
    $released_at = trim((string)($data['released_at'] ?? ''));
    if ($released_at === '') $released_at = date('Y-m-d');
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') $title = release_type_label_admin($release_type) . '更新';
    $public_summary = trim((string)($data['public_summary'] ?? ''));
    $admin_note = trim((string)($data['admin_note'] ?? ''));
    $visible = !empty($data['visible']);
    $is_current = !empty($data['is_current']);
    $version = version_json_admin();
    $db = load_release_versions_admin();
    $now = date('Y-m-d H:i:s');
    if ($id === '') $id = 'rel_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2));
    $found = false;
    foreach ($db['versions'] as &$row) {
        if (($row['id'] ?? '') === $id) {
            $row = array_merge($row, [
                'id'=>$id,
                'public_version'=>$public_version,
                'internal_version'=>trim((string)($data['internal_version'] ?? ($row['internal_version'] ?? ($version['app_version'] ?? '')))),
                'cache_version'=>trim((string)($data['cache_version'] ?? ($row['cache_version'] ?? ($version['cache_version'] ?? '')))),
                'release_type'=>$release_type,
                'released_at'=>$released_at,
                'title'=>$title,
                'public_summary'=>$public_summary,
                'admin_note'=>$admin_note,
                'visible'=>$visible,
                'is_current'=>$is_current,
                'updated_at'=>$now,
                'updated_by'=>$admin_label
            ]);
            $found = true;
            break;
        }
    }
    unset($row);
    if (!$found) {
        $db['versions'][] = [
            'id'=>$id,
            'public_version'=>$public_version,
            'internal_version'=>trim((string)($data['internal_version'] ?? ($version['app_version'] ?? ''))),
            'cache_version'=>trim((string)($data['cache_version'] ?? ($version['cache_version'] ?? ''))),
            'release_type'=>$release_type,
            'released_at'=>$released_at,
            'title'=>$title,
            'public_summary'=>$public_summary,
            'admin_note'=>$admin_note,
            'visible'=>$visible,
            'is_current'=>$is_current,
            'created_at'=>$now,
            'updated_at'=>$now,
            'updated_by'=>$admin_label
        ];
    }
    if ($is_current) {
        foreach ($db['versions'] as &$row) {
            if (($row['id'] ?? '') !== $id) $row['is_current'] = false;
        }
        unset($row);
        $db['current_public_version'] = $public_version;
    }
    $db['versions'] = sort_release_versions_desc_admin($db['versions']);
    if (!save_release_versions_admin($db)) admin_json(500, ['ok'=>false,'error'=>'save_failed','message'=>'バージョン情報を保存できませんでした。']);
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'release_version_save','result'=>'success','target_type'=>'release_version','target_hash_prefix'=>$id,'message'=>$public_version . ' saved']);
    admin_json(200, array_merge(summarize_release_versions_admin(), ['message'=>'バージョン情報を保存しました。']));
}

if ($action === 'release_version_delete') {
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['id'] ?? ''));
    if ($id === '') admin_json(400, ['ok'=>false,'error'=>'id_required','message'=>'削除対象が指定されていません。']);
    $db = load_release_versions_admin();
    $before = count($db['versions']);
    $db['versions'] = array_values(array_filter($db['versions'], function($row) use ($id){ return ($row['id'] ?? '') !== $id; }));
    if (count($db['versions']) === $before) admin_json(404, ['ok'=>false,'error'=>'not_found','message'=>'対象バージョンが見つかりません。']);
    $current = '';
    foreach ($db['versions'] as $row) { if (!empty($row['is_current'])) { $current = $row['public_version'] ?? ''; break; } }
    $db['current_public_version'] = $current ?: ($db['versions'][0]['public_version'] ?? 'v1.0.0');
    $db['versions'] = sort_release_versions_desc_admin($db['versions']);
    if (!save_release_versions_admin($db)) admin_json(500, ['ok'=>false,'error'=>'save_failed','message'=>'バージョン情報を削除できませんでした。']);
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'release_version_delete','result'=>'success','target_type'=>'release_version','target_hash_prefix'=>$id,'message'=>'deleted']);
    admin_json(200, array_merge(summarize_release_versions_admin(), ['message'=>'バージョン情報を削除しました。']));
}

if ($action === 'add_code') {
    $type = ($data['type'] ?? '') === 'admin' ? 'admin' : 'invite';
    $label = trim((string)($data['label'] ?? ''));
    if ($label === '') $label = ($type === 'admin' ? '管理者ID' : '招待ID') . ' ' . date('Ymd-His');
    $max_uses = intval($data['max_uses'] ?? 1);
    if ($max_uses < 0) $max_uses = 0;
    if ($max_uses > 9999) $max_uses = 9999;
    $features = clean_features_admin($data['features'] ?? [], $type);
    $prefix = $type === 'admin' ? 'ADMIN' : 'INVITE';
    $plain = $prefix . '-' . strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(bin2hex(random_bytes(3)));
    $hash = code_hash_for_type($type, $plain);
    $db = load_code_db_by_type($type);
    $db['codes'][$hash] = [
        'enabled'=>true,
        'features'=>$features,
        'label'=>$label,
        'max_uses'=>$max_uses,
        'used_by'=>[],
        'created_at'=>date('Y-m-d H:i:s'),
        'created_by'=>$admin_label
    ];
    if (!write_json_file_locked(code_db_file_by_type($type), $db)) {
        admin_json(500, ['ok'=>false,'error'=>'save_failed','message'=>'IDを保存できませんでした。']);
    }
    audit_log_admin_event([
        'admin_label'=>$admin_label,
        'action'=>'add_code',
        'result'=>'success',
        'target_type'=>$type,
        'target_hash_prefix'=>substr($hash,0,12),
        'message'=>'created code'
    ]);
    admin_json(200, ['ok'=>true,'type'=>$type,'code'=>$plain,'hash_prefix'=>substr($hash,0,12),'features'=>$features,'message'=>'IDを追加しました。このプレイヤーIDはこの画面で一度だけ表示されます。']);
}

if ($action === 'disable_code' || $action === 'delete_code' || $action === 'enable_code') {
    $type = ($data['type'] ?? '') === 'admin' ? 'admin' : 'invite';
    $prefix = preg_replace('/[^a-fA-F0-9]/', '', (string)($data['hash_prefix'] ?? ''));
    if (strlen($prefix) < 8) admin_json(400, ['ok'=>false,'error'=>'hash_prefix required','message'=>'対象IDのhash prefixが不足しています。']);
    $db = load_code_db_by_type($type);
    $found = null;
    foreach (($db['codes'] ?? []) as $hash=>$row) {
        if (strpos($hash, strtolower($prefix)) === 0 || strpos($hash, $prefix) === 0) { $found = $hash; break; }
    }
    if (!$found) admin_json(404, ['ok'=>false,'error'=>'not_found','message'=>'対象IDが見つかりません。']);
    if ($action === 'delete_code') {
        unset($db['codes'][$found]);
    } elseif ($action === 'enable_code') {
        $db['codes'][$found]['enabled'] = true;
        unset($db['codes'][$found]['disabled_at'], $db['codes'][$found]['disabled_reason']);
    } else {
        $db['codes'][$found]['enabled'] = false;
        $db['codes'][$found]['disabled_at'] = date('Y-m-d H:i:s');
        $db['codes'][$found]['disabled_reason'] = trim((string)($data['reason'] ?? '最高位管理者により停止'));
    }
    if (!write_json_file_locked(code_db_file_by_type($type), $db)) {
        admin_json(500, ['ok'=>false,'error'=>'save_failed','message'=>'ID設定を保存できませんでした。']);
    }
    audit_log_admin_event([
        'admin_label'=>$admin_label,
        'action'=>$action,
        'result'=>'success',
        'target_type'=>$type,
        'target_hash_prefix'=>substr($found,0,12),
        'message'=>$action . ' completed'
    ]);
    admin_json(200, ['ok'=>true,'message'=>'ID設定を更新しました。']);
}




function theme_label_admin($theme) {
    $theme = (string)$theme;
    $known = [
        'pickoff_tag_play_basic'=>'牽制時のタッチプレイ基本',
        'pickoff_after_return'=>'牽制後の帰塁確認',
        'pickoff_overthrow'=>'牽制悪送球カバー',
        'pitcher_pickoff_large_lead'=>'大きなリードへの牽制',
        'pitcher_pickoff_runner_not_looking'=>'走者が見ていない時の牽制',
        'pitcher_balk_rule_judgment'=>'ボークルール判断',
        'steal'=>'盗塁判断',
        'squeeze'=>'スクイズ判断',
        'wild_pitch_run'=>'ワイルドピッチ走塁',
        'two_out_run'=>'2アウト時の全力進塁',
        'ground_run'=>'ゴロ時の走塁',
        'fly_return'=>'フライ・ライナー時の帰塁',
        'liner_two_out_advance'=>'2アウトライナー時の進塁',
        'running_lane'=>'走塁レーン',
        'basic_rule'=>'基本ルール',
        'basic_rule_extra'=>'基本ルール補足',
        'basic_position_learning'=>'守備位置学習',
        'basic_position_name'=>'守備位置名',
        'basic_defense_number'=>'守備番号',
        'basic_foul_ball'=>'ファウルボール',
        'basic_foul_catch'=>'ファウルフライ捕球',
        'basic_strike_zone'=>'ストライクゾーン',
        'basic_uncaught_third_strike'=>'振り逃げ基本',
        'cover_basic_first'=>'一塁カバー基本',
        'cover_basic_second'=>'二塁カバー基本',
        'cover_home_basic'=>'本塁カバー基本',
        'cover_overthrow_first'=>'一塁悪送球カバー',
        'cover_home_overthrow'=>'本塁悪送球カバー',
        'cover_doubleplay'=>'ダブルプレイカバー',
        'cover_relay_second'=>'二塁中継カバー',
        'cover_bunt_rotation'=>'バント時ローテーション',
        'cover_squeeze_rotation'=>'スクイズ時ローテーション',
        'cover_rundown_home'=>'本塁挟殺カバー',
        'batter_runner_run_through_first'=>'打者走者の一塁駆け抜け',
        'batter_runner_interference_avoid'=>'打者走者の守備妨害回避',
        'batter_runner_three_foot_lane'=>'スリーフットレーン',
        'batter_runner_three_foot_interference'=>'スリーフット守備妨害',
        'catcher_block_one_bounce_pitch'=>'捕手ワンバウンド投球ブロック',
        'catcher_steal_second_throw'=>'捕手の二塁盗塁送球',
        'low_grade_catcher_fly'=>'低学年捕手フライ',
        'low_grade_catcher_dropped_third_strike'=>'低学年振り逃げ',
        'low_grade_catcher_dropped_third_throw_first'=>'低学年振り逃げ一塁送球',
        'infield_grounder_runner_on_third_throw_home'=>'三塁走者あり内野ゴロ本塁送球',
        'infield_fly_throw_back_base'=>'内野フライ後の帰塁送球',
        'infield_fly_runner_judgment'=>'インフィールドフライ走者判断',
        'multi_runner_force_play'=>'複数走者フォースプレイ',
        'force_advance_runner_on_first_second_grounder'=>'一二塁フォース進塁',
        'outfield_cleanup_batter_positioning_left_handed'=>'外野の左打者強打者守備位置',
        'outfield_cleanup_batter_positioning_right_handed'=>'外野の右打者強打者守備位置',
        'positioning_left_center_gap'=>'左中間の守備位置',
        'positioning_right_center_gap'=>'右中間の守備位置',
        'positioning_left_fly'=>'レフトフライの守備位置',
        'right_center_gap_2b_cutoff_runner_1b'=>'右中間・一塁走者あり二塁中継',
        'right_center_gap_cf_fielding_relay'=>'右中間センター処理と中継',
        'left_fielder_runner_on_second_single'=>'レフト前・二塁走者あり',
        'left_fielder_runner_on_third_single_two_out'=>'レフト前・三塁走者あり2アウト',
        'expanded_attack'=>'攻撃追加問題',
        'expanded_defense'=>'守備追加問題',
        'position_specific_fielding_expansion'=>'ポジション別守備追加問題'
    ];
    if (isset($known[$theme])) return $known[$theme];
    $s = $theme;
    $rules = [
        'attack'=>'攻撃',
        'defense'=>'守備',
        'basic'=>'基本',
        'pitcher'=>'ピッチャー',
        'catcher'=>'キャッチャー',
        'first_base'=>'ファースト',
        'second'=>'セカンド',
        'short'=>'ショート',
        'third'=>'サード',
        'left'=>'レフト',
        'center'=>'センター',
        'right'=>'ライト',
        'outfield'=>'外野',
        'infield'=>'内野',
        'grounder'=>'ゴロ',
        'fly'=>'フライ',
        'liner'=>'ライナー',
        'runner'=>'走者',
        'batter_runner'=>'打者走者',
        'pickoff'=>'牽制',
        'fake'=>'偽投',
        'cover'=>'カバー',
        'backup'=>'バックアップ',
        'throw'=>'送球',
        'relay'=>'中継',
        'cutoff'=>'カット',
        'steal'=>'盗塁',
        'squeeze'=>'スクイズ',
        'bunt'=>'バント',
        'wild_pitch'=>'ワイルドピッチ',
        'dropped_third'=>'振り逃げ',
        'rundown'=>'挟殺',
        'force'=>'フォースプレイ',
        'double_play'=>'ダブルプレイ',
        'dp'=>'ダブルプレイ',
        'overthrow'=>'悪送球',
        'obstruction'=>'走塁妨害',
        'interference'=>'守備妨害',
        'safe'=>'セーフ後',
        'low_grade'=>'低学年',
        'high_grade'=>'高学年',
        'cleanup'=>'強打者',
        'positioning'=>'守備位置',
        'judgment'=>'判断',
        'rule'=>'ルール',
        'tag'=>'タッチ',
        'play'=>'プレイ',
        'home'=>'本塁',
        'large_lead'=>'大きなリード',
        'two_out'=>'2アウト',
        'no_out'=>'ノーアウト',
        'one_out'=>'1アウト'
    ];
    uksort($rules, function($a,$b){ return strlen($b)-strlen($a); });
    foreach ($rules as $en=>$ja) {
        $s = str_replace($en, $ja, $s);
    }
    $s = str_replace('_', '・', $s);
    return $s;
}



function push_notice_history_file_admin() {
    return feature_scores_dir() . '/push_notice_history.json';
}
function append_push_notice_history_admin($rec) {
    $file = push_notice_history_file_admin();
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $db = ['history'=>[]];
    if (is_file($file)) {
        $tmp = json_decode(file_get_contents($file), true);
        if (is_array($tmp)) $db = $tmp;
    }
    if (!isset($db['history']) || !is_array($db['history'])) $db['history'] = [];
    array_unshift($db['history'], $rec);
    $db['history'] = array_slice($db['history'], 0, 200);
    file_put_contents($file, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)), LOCK_EX);
}
function read_push_notice_history_admin() {
    $file = push_notice_history_file_admin();
    if (!is_file($file)) return [];
    $db = json_decode(file_get_contents($file), true);
    if (!is_array($db) || !is_array($db['history'] ?? null)) return [];
    return $db['history'];
}


if ($action === 'player_list') {
    $query = trim((string)($data['query'] ?? ''));
    $list = build_player_admin_list($query);
    admin_json(200, ['ok'=>true,'players'=>array_slice($list,0,1000),'total'=>count($list)]);
}

if ($action === 'self_unissued_code_status') {
    $query = trim((string)($data['query'] ?? ''));
    $status = self_unissued_code_status_admin($query);
    admin_json(200, ['ok'=>true] + $status);
}

if ($action === 'admin_unlocked_players') {
    $query = trim((string)($data['query'] ?? ''));
    $status = admin_unlocked_players_admin($query);
    admin_json(200, ['ok'=>true] + $status);
}

if ($action === 'invite_unlocked_players') {
    $query = trim((string)($data['query'] ?? ''));
    $status = invite_unlocked_players_admin($query);
    admin_json(200, ['ok'=>true] + $status);
}

if ($action === 'revoke_invite_features') {
    $player_id = normalize_player_id($data['player_id'] ?? '');
    $reason = trim((string)($data['reason'] ?? '最高位管理者により招待ID機能を解除'));
    if ($player_id === '') admin_json(400, ['ok'=>false,'error'=>'player_id_required','message'=>'対象プレイヤーIDが指定されていません。']);
    $db = load_player_feature_db();
    $found_key = null;
    foreach (($db['players'] ?? []) as $pid=>$row) {
        if (canonical_player_id($pid) === canonical_player_id($player_id)) { $found_key = $pid; break; }
    }
    if ($found_key === null) admin_json(404, ['ok'=>false,'error'=>'player_not_found','message'=>'対象プレイヤーIDの機能解放データが見つかりません。']);
    $row = $db['players'][$found_key];
    $flags = isset($row['flags']) && is_array($row['flags']) ? $row['flags'] : [];
    $sources = isset($row['sources']) && is_array($row['sources']) ? $row['sources'] : [];
    $removed = [];
    foreach ($sources as $feature=>$src) {
        if (!is_array($src)) continue;
        if (($src['type'] ?? '') !== 'invite') continue;
        $feature = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$feature);
        if ($feature === '' || $feature === 'admin_mode') continue;
        if (!empty($flags[$feature])) {
            unset($db['players'][$found_key]['flags'][$feature]);
            $removed[] = $feature;
        }
        unset($db['players'][$found_key]['sources'][$feature]);
    }
    if (!count($removed)) {
        admin_json(404, ['ok'=>false,'error'=>'invite_features_not_enabled','message'=>'このプレイヤーIDには招待ID由来の解放機能がありません。']);
    }
    if (!isset($db['players'][$found_key]['revoked']) || !is_array($db['players'][$found_key]['revoked'])) $db['players'][$found_key]['revoked'] = [];
    $db['players'][$found_key]['revoked']['invite_features'] = [
        'revoked_at'=>date('Y-m-d H:i:s'),
        'revoked_by'=>$admin_label,
        'reason'=>$reason,
        'features'=>$removed
    ];
    $db['players'][$found_key]['updated_at'] = date('Y-m-d H:i:s');
    if (!save_player_feature_db($db)) admin_json(500, ['ok'=>false,'error'=>'save_failed','message'=>'招待ID機能の解除を保存できませんでした。']);
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'revoke_invite_features','result'=>'success','target_type'=>'player_feature','target_hash_prefix'=>hash_short($found_key),'message'=>'player_id=' . $found_key . ' features=' . implode('|', $removed) . ' reason=' . $reason]);
    audit_log_id_event(['action'=>'invite_features_revoked','result'=>'success','player_id'=>$found_key,'id_type'=>'invite','features'=>$removed,'message'=>$reason]);
    admin_json(200, ['ok'=>true,'message'=>'招待ID由来の機能を解除しました。','invite_unlocked_players'=>invite_unlocked_players_admin()]);
}

if ($action === 'revoke_admin_mode') {
    $player_id = normalize_player_id($data['player_id'] ?? '');
    $reason = trim((string)($data['reason'] ?? '最高位管理者により管理者ID由来を含む全機能を解除'));
    if ($player_id === '') admin_json(400, ['ok'=>false,'error'=>'player_id_required','message'=>'対象プレイヤーIDが指定されていません。']);
    $db = load_player_feature_db();
    $found_key = null;
    foreach (($db['players'] ?? []) as $pid=>$row) {
        if (canonical_player_id($pid) === canonical_player_id($player_id)) { $found_key = $pid; break; }
    }
    if ($found_key === null) admin_json(404, ['ok'=>false,'error'=>'player_not_found','message'=>'対象プレイヤーIDの機能解放データが見つかりません。']);

    $row = isset($db['players'][$found_key]) && is_array($db['players'][$found_key]) ? $db['players'][$found_key] : [];
    $flags = isset($row['flags']) && is_array($row['flags']) ? $row['flags'] : [];
    if (empty($flags['admin_mode'])) {
        admin_json(404, ['ok'=>false,'error'=>'admin_mode_not_enabled','message'=>'このプレイヤーIDは管理者機能解放済みではありません。']);
    }
    $removed_features = array_keys(array_filter($flags));
    if (!in_array('admin_mode', $removed_features, true)) $removed_features[] = 'admin_mode';

    // 管理者IDの権限解除は復活運用をしないため、admin_mode だけでなく
    // そのプレイヤーIDに付与されている全機能を無効化し、player_features.json から該当行を削除する。
    unset($db['players'][$found_key]);

    if (!save_player_feature_db($db)) admin_json(500, ['ok'=>false,'error'=>'save_failed','message'=>'管理者ID由来を含む全機能の解除を保存できませんでした。']);
    $features_text = implode('|', $removed_features);
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'revoke_admin_mode_all_features','result'=>'success','target_type'=>'player_feature','target_hash_prefix'=>hash_short($found_key),'message'=>'player_id=' . $found_key . ' removed_from_player_features=1 features=' . $features_text . ' reason=' . $reason]);
    audit_log_id_event(['action'=>'admin_mode_all_features_revoked','result'=>'success','player_id'=>$found_key,'id_type'=>'admin','features'=>$features_text,'message'=>$reason]);
    admin_json(200, ['ok'=>true,'message'=>'管理者ID由来を含む全機能を解除し、player_features.json から対象データを削除しました。','removed_features'=>$removed_features,'admin_unlocked_players'=>admin_unlocked_players_admin()]);
}





if ($action === 'get_push_notification_settings') {
    admin_json(200, ['ok'=>true,'settings'=>read_push_notification_settings()]);
}

if ($action === 'save_push_notification_settings') {
    $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
    $saved = save_push_notification_settings($settings);
    admin_json(200, ['ok'=>true,'settings'=>$saved,'message'=>'ランキング通知設定を保存しました。']);
}

if ($action === 'aggregate_weekly_ranking') {
    $snapshot = aggregate_weekly_ranking_notice(true);
    admin_json(200, [
        'ok'=>true,
        'week_key'=>$snapshot['week_key'] ?? '',
        'changed'=>!empty($snapshot['changed']),
        'changed_ranks'=>$snapshot['changed_ranks'] ?? [],
        'top3_count'=>count($snapshot['top3'] ?? [])
    ]);
}

if ($action === 'send_weekly_ranking_notice') {
    $force = !empty($data['force']);
    $result = send_weekly_ranking_notice($force);
    admin_json(200, ['ok'=>true] + $result);
}



if ($action === 'new_question_notice_candidates') {
    $items = new_question_notice_candidates($session['label'] ?? '最高位管理者');
    admin_json(200, ['ok'=>true,'items'=>$items]);
}

if ($action === 'question_publish_selected') {
    $ids = isset($data['question_ids']) && is_array($data['question_ids']) ? array_values(array_unique(array_filter(array_map('strval', $data['question_ids'])))) : [];
    if (empty($ids)) admin_json(400, ['ok'=>false,'message'=>'公開する問題を選択してください。']);
    question_status_set_many($ids, 'published');
    update_game_config_counts_admin(load_questions_admin());
    audit_log_admin_event(['admin_label'=>$session['label'] ?? '最高位管理者','action'=>'question_publish_selected','result'=>'success','target_type'=>'question','target_hash_prefix'=>implode(',', array_slice($ids,0,5)),'message'=>'published '.count($ids).' questions']);
    admin_json(200, ['ok'=>true,'message'=>count($ids).'件の問題を公開しました。']);
}

if ($action === 'scheduled_notice_list') {
    admin_json(200, ['ok'=>true,'items'=>scheduled_notifications_list()]);
}

if ($action === 'scheduled_notice_save') {
    $admin_label = $session['label'] ?? '最高位管理者';
    $result = scheduled_notifications_upsert($data, $admin_label);
    if (empty($result['ok'])) admin_json(400, ['ok'=>false,'message'=>$result['message'] ?? '保存できませんでした。']);
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'scheduled_notice_save','result'=>'success','target_type'=>'scheduled_notice','target_hash_prefix'=>$result['id'] ?? '','message'=>$result['message'] ?? '']);
    admin_json(200, ['ok'=>true] + $result);
}

if ($action === 'scheduled_notice_delete') {
    $id = trim((string)($data['id'] ?? ''));
    if (!scheduled_notifications_delete($id)) admin_json(404, ['ok'=>false,'message'=>'対象のスケジュール通知が見つかりません。']);
    audit_log_admin_event(['admin_label'=>$session['label'] ?? '最高位管理者','action'=>'scheduled_notice_delete','result'=>'success','target_type'=>'scheduled_notice','target_hash_prefix'=>$id,'message'=>'deleted']);
    admin_json(200, ['ok'=>true,'message'=>'スケジュール通知を削除しました。']);
}

if ($action === 'new_question_unschedule_selected') {
    $ids = isset($data['question_ids']) && is_array($data['question_ids']) ? array_values(array_unique(array_filter(array_map('strval', $data['question_ids'])))) : [];
    if (empty($ids)) admin_json(400, ['ok'=>false,'message'=>'スケジュール解除する問題を選択してください。']);
    $result = scheduled_notifications_unschedule_questions($ids);
    audit_log_admin_event(['admin_label'=>$session['label'] ?? '最高位管理者','action'=>'new_question_unschedule_selected','result'=>'success','target_type'=>'question','target_hash_prefix'=>implode(',', array_slice($ids,0,5)),'message'=>$result['message'] ?? 'unscheduled']);
    admin_json(200, ['ok'=>true] + $result);
}

if ($action === 'scheduled_notice_run_due') {
    $result = scheduled_notifications_run_due();
    admin_json(200, ['ok'=>true] + $result);
}

if ($action === 'send_admin_notice') {
    require_once __DIR__ . '/send_push_notification.php';
    $title = trim((string)($data['title'] ?? '野球やろうぜ！'));
    $body = trim((string)($data['body'] ?? ''));
    $url = normalize_notice_url($data['url'] ?? './');
    $target = trim((string)($data['target'] ?? 'all'));
    if ($title === '') admin_json(400, ['ok'=>false,'message'=>'通知タイトルを入力してください。']);
    if ($body === '') admin_json(400, ['ok'=>false,'message'=>'通知本文を入力してください。']);
    if ($target !== 'all') admin_json(400, ['ok'=>false,'message'=>'現在対応している送信対象は全員のみです。']);
    $guard = notice_duplicate_guard_reserve('admin_notice', $title, $body, $url, 'manual_admin_notice', 86400);
    if (empty($guard['ok'])) {
        admin_json(409, ['ok'=>false,'message'=>$guard['message'] ?? '同じ内容の通知が送信済みです。']);
    }
    $result = send_web_push_notifications($title, $body, $url);
    notice_duplicate_guard_finish($guard['id'] ?? '', (($result['failed'] ?? 0) > 0 && ($result['sent'] ?? 0) <= 0) ? 'failed' : 'sent', $result);
    append_push_notice_history_admin([
        'sent_at'=>date('Y-m-d H:i:s'),
        'title'=>$title,
        'body'=>$body,
        'url'=>$url,
        'target'=>$target,
        'sent'=>$result['sent'] ?? 0,
        'failed'=>$result['failed'] ?? 0,
        'message'=>$result['message'] ?? '',
        'duplicate_guard_signature'=>$guard['signature'] ?? ''
    ]);
    admin_json(200, ['ok'=>true] + $result);
}

if ($action === 'list_push_notices') {
    admin_json(200, ['ok'=>true,'history'=>read_push_notice_history_admin()]);
}

if ($action === 'send_push_test') {
    require_once __DIR__ . '/send_push_notification.php';
    $title = trim((string)($data['title'] ?? '野球やろうぜ！'));
    $body = trim((string)($data['body'] ?? 'テスト通知です'));
    $result = send_web_push_notifications($title, $body, './');
    admin_json(200, ['ok'=>true] + $result);
}

if ($action === 'player_id_change_history') {
    $player_id = normalize_player_id($data['player_id'] ?? '');
    $rows = read_player_id_change_history_admin();
    if ($player_id !== '') {
        $rows = array_values(array_filter($rows, function($r) use ($player_id) {
            return (($r['old_player_id'] ?? '') === $player_id) || (($r['new_player_id'] ?? '') === $player_id);
        }));
    }
    admin_json(200, ['ok'=>true,'history'=>array_slice($rows,0,300),'total'=>count($rows)]);
}

if ($action === 'player_set_status') {
    $player_id = normalize_player_id($data['player_id'] ?? '');
    $status = preg_replace('/[^a-z_]/', '', (string)($data['status'] ?? ''));
    $reason = trim((string)($data['reason'] ?? ''));
    if ($player_id === '') admin_json(400, ['ok'=>false,'message'=>'ユーザーIDが必要です。']);
    if (!in_array($status, ['active','suspended'], true)) admin_json(400, ['ok'=>false,'message'=>'status が不正です。']);
    $db = load_player_suspensions();
    if (!isset($db['players'][$player_id]) || !is_array($db['players'][$player_id])) $db['players'][$player_id] = [];
    if ($status === 'suspended') {
        $db['players'][$player_id] = array_merge($db['players'][$player_id], [
            'status'=>'suspended',
            'suspended_at'=>date('Y-m-d H:i:s'),
            'suspended_by'=>$admin_label,
            'reason'=>$reason
        ]);
        $msg = 'ゲーム利用を停止しました。';
        $act = 'player_suspend';
    } else {
        $db['players'][$player_id] = array_merge($db['players'][$player_id], [
            'status'=>'active',
            'resumed_at'=>date('Y-m-d H:i:s'),
            'resumed_by'=>$admin_label
        ]);
        $msg = 'ゲーム利用を再開しました。';
        $act = 'player_resume';
    }
    save_player_suspensions($db);
    audit_log_admin_event([
        'admin_label'=>$admin_label,
        'action'=>$act,
        'result'=>'success',
        'target_type'=>'player',
        'target_hash_prefix'=>$player_id,
        'message'=>$msg . ' player=' . $player_id . ($reason !== '' ? ' reason=' . $reason : '')
    ]);
    admin_json(200, ['ok'=>true,'message'=>$msg,'player_id'=>$player_id,'status'=>$status]);
}

if ($action === 'quiz_master_question_list') {
    $payload = load_quiz_master_payload_admin();
    $questions = array_map('normalize_quiz_master_question_admin', $payload['questions'] ?? []);
    $query = mb_strtolower(trim((string)($data['query'] ?? '')));
    $level = intval($data['level'] ?? 0);
    $category = trim((string)($data['category'] ?? ''));
    $rows = [];
    $categories = [];
    $level_counts = [];
    foreach ($questions as $q) {
        if (($q['category'] ?? '') !== '') $categories[$q['category']] = true;
        $lv = intval($q['level'] ?? 0);
        if ($lv >= 1 && $lv <= 20) $level_counts[$lv] = ($level_counts[$lv] ?? 0) + 1;
        if ($level && $lv !== $level) continue;
        if ($category !== '' && ($q['category'] ?? '') !== $category) continue;
        if ($query !== '') {
            $hay = mb_strtolower(($q['id'] ?? '') . ' ' . ($q['category'] ?? '') . ' ' . ($q['question'] ?? '') . ' ' . implode(' ', $q['choices'] ?? []) . ' ' . ($q['explanation'] ?? ''));
            if (mb_strpos($hay, $query) === false) continue;
        }
        $rows[] = quiz_master_question_public_row_admin($q);
    }
    usort($rows, function($a,$b){
        if (($a['level'] ?? 0) !== ($b['level'] ?? 0)) return ($a['level'] ?? 0) <=> ($b['level'] ?? 0);
        return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });
    ksort($categories);
    ksort($level_counts);
    admin_json(200, [
        'ok'=>true,
        'questions'=>$rows,
        'total'=>count($questions),
        'filtered'=>count($rows),
        'categories'=>array_keys($categories),
        'level_counts'=>$level_counts,
        'meta'=>$payload['meta'] ?? []
    ]);
}

if ($action === 'quiz_master_question_get') {
    $id = normalize_quiz_master_question_id_admin($data['id'] ?? '');
    if ($id === '') admin_json(400, ['ok'=>false,'message'=>'問題IDが必要です。']);
    $payload = load_quiz_master_payload_admin();
    foreach (($payload['questions'] ?? []) as $q) {
        if (normalize_quiz_master_question_id_admin($q['id'] ?? '') === $id) {
            admin_json(200, ['ok'=>true,'question'=>normalize_quiz_master_question_admin($q)]);
        }
    }
    admin_json(404, ['ok'=>false,'message'=>'問題が見つかりません。']);
}

if ($action === 'quiz_master_question_save') {
    $question = normalize_quiz_master_question_admin($data['question'] ?? []);
    $original_id = normalize_quiz_master_question_id_admin($data['original_id'] ?? '');
    if ($original_id !== '' && $question['id'] !== $original_id) {
        admin_json(400, ['ok'=>false,'message'=>'既存問題のIDは変更できません。']);
    }
    $payload = load_quiz_master_payload_admin();
    $questions = array_map('normalize_quiz_master_question_admin', $payload['questions'] ?? []);
    $ids = [];
    foreach ($questions as $q) $ids[$q['id'] ?? ''] = true;
    $error = validate_quiz_master_question_admin($question, $ids, $original_id);
    if ($error !== '') admin_json(400, ['ok'=>false,'message'=>$error]);
    if ($question['level_name'] === '') $question['level_name'] = '第' . $question['level'] . '問';
    $backup = backup_quiz_master_questions_admin('save_' . ($original_id ?: $question['id']));
    $found = false;
    foreach ($questions as $i=>$q) {
        if (($q['id'] ?? '') === $original_id) {
            $questions[$i] = $question;
            $found = true;
            break;
        }
    }
    if (!$found) $questions[] = $question;
    usort($questions, function($a,$b){
        if (($a['level'] ?? 0) !== ($b['level'] ?? 0)) return ($a['level'] ?? 0) <=> ($b['level'] ?? 0);
        return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });
    $payload['questions'] = $questions;
    if (!save_quiz_master_payload_admin($payload)) admin_json(500, ['ok'=>false,'message'=>'野球博士チャレンジ問題データを保存できませんでした。']);
    audit_log_admin_event([
        'admin_label'=>$admin_label,
        'action'=>'quiz_master_question_save',
        'result'=>'success',
        'target_type'=>'quiz_master_question',
        'target_hash_prefix'=>$question['id'],
        'message'=>($found?'updated ':'added ') . $question['id'] . ' backup=' . ($backup['file'] ?? '')
    ]);
    admin_json(200, ['ok'=>true,'message'=>($found?'野球博士問題を更新しました。':'野球博士問題を追加しました。'),'id'=>$question['id'],'backup_file'=>$backup['file'] ?? '']);
}

if ($action === 'quiz_master_question_delete') {
    $id = normalize_quiz_master_question_id_admin($data['id'] ?? '');
    if ($id === '') admin_json(400, ['ok'=>false,'message'=>'問題IDが必要です。']);
    $payload = load_quiz_master_payload_admin();
    $questions = array_map('normalize_quiz_master_question_admin', $payload['questions'] ?? []);
    $before = count($questions);
    $questions = array_values(array_filter($questions, function($q) use ($id) { return ($q['id'] ?? '') !== $id; }));
    if (count($questions) === $before) admin_json(404, ['ok'=>false,'message'=>'問題が見つかりません。']);
    $backup = backup_quiz_master_questions_admin('delete_' . $id);
    $payload['questions'] = $questions;
    if (!save_quiz_master_payload_admin($payload)) admin_json(500, ['ok'=>false,'message'=>'野球博士チャレンジ問題データを保存できませんでした。']);
    audit_log_admin_event([
        'admin_label'=>$admin_label,
        'action'=>'quiz_master_question_delete',
        'result'=>'success',
        'target_type'=>'quiz_master_question',
        'target_hash_prefix'=>$id,
        'message'=>'deleted ' . $id . ' backup=' . ($backup['file'] ?? '')
    ]);
    admin_json(200, ['ok'=>true,'message'=>'野球博士問題を削除しました。','id'=>$id,'backup_file'=>$backup['file'] ?? '']);
}

if ($action === 'quiz_master_titles_get') {
    $payload = quiz_master_load_titles_payload();
    admin_json(200, ['ok'=>true,'titles'=>$payload['titles'] ?? quiz_master_default_titles(),'updated_at'=>$payload['updated_at'] ?? '']);
}

if ($action === 'quiz_master_titles_save') {
    $titles = quiz_master_normalize_titles($data['titles'] ?? []);
    $payload = [
        'version'=>1,
        'updated_at'=>date('Y-m-d H:i:s'),
        'updated_by'=>$admin_label,
        'titles'=>$titles
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    if ($json === false || !write_text_file_locked_admin(quiz_master_titles_file(), $json . "\n")) {
        admin_json(500, ['ok'=>false,'message'=>'野球博士ランクデータを保存できませんでした。']);
    }
    audit_log_admin_event([
        'admin_label'=>$admin_label,
        'action'=>'quiz_master_titles_save',
        'result'=>'success',
        'target_type'=>'quiz_master_titles',
        'target_hash_prefix'=>'titles',
        'message'=>'saved ' . count($titles) . ' titles'
    ]);
    admin_json(200, ['ok'=>true,'message'=>'野球博士ランクを保存しました。','titles'=>$titles]);
}

if ($action === 'question_options') {
    $questions = load_questions_admin();
    $themes = [];
    $ball_tags = [];
    foreach ($questions as $q) {
        $theme = trim((string)($q['theme'] ?? ''));
        $ball_tag = trim((string)($q['ball_tag'] ?? ''));
        if ($theme !== '') $themes[$theme] = true;
        if ($ball_tag !== '') $ball_tags[$ball_tag] = true;
    }
    $themes = array_keys($themes);
    $ball_tags = array_keys($ball_tags);
    sort($themes);
    sort($ball_tags);
    $theme_labels = [];
    foreach ($themes as $theme) $theme_labels[$theme] = theme_label_admin($theme);
    admin_json(200, [
        'ok'=>true,
        'themes'=>$themes,
        'theme_labels'=>$theme_labels,
        'ball_tags'=>$ball_tags,
        'prompt_templates'=>[
            'あなたの守備位置ならどうする？最も良い判断を選んでください。',
            'この場面で、君ならどう判断する？',
            '打った後、あなたはどう走る？最も良い判断を選んでください。',
            'この場面で、あなたはどう動く？最も良い判断を選んでください。',
            'この場面で、チームとして一番よい動きはどれ？'
        ]
    ]);
}

if ($action === 'question_list') {
    $questions = load_questions_admin();
    $query = trim((string)($data['query'] ?? ''));
    $type_filter = preg_replace('/[^a-zA-Z]/', '', (string)($data['type'] ?? ''));
    $position_filter = preg_replace('/[^A-Z0-9]/', '', (string)($data['position'] ?? ''));
    $rows = [];
    foreach ($questions as $q) {
        $sum = question_summary_admin($q);
        if ($type_filter !== '' && $sum['type'] !== $type_filter) continue;
        if ($position_filter !== '') {
            $positions = $sum['positions'] ?? [];
            if (!is_array($positions) || !in_array($position_filter, $positions, true)) continue;
        }
        if ($query !== '') {
            $hay = json_encode($sum, JSON_UNESCAPED_UNICODE);
            if (mb_stripos($hay, $query, 0, 'UTF-8') === false) continue;
        }
        $rows[] = $sum;
    }
    admin_json(200, ['ok'=>true,'questions'=>$rows,'total'=>count($questions),'filtered'=>count($rows)]);
}


if ($action === 'question_csv_export') {
    $questions = load_questions_admin();
    $scope = trim((string)($data['scope'] ?? 'all'));
    if ($scope === 'filtered') {
        $query = trim((string)($data['query'] ?? ''));
        $type_filter = preg_replace('/[^a-zA-Z]/', '', (string)($data['type'] ?? ''));
        $position_filter = preg_replace('/[^A-Z0-9]/', '', (string)($data['position'] ?? ''));
        $rows = question_filter_rows_admin($questions, $query, $type_filter, $position_filter);
    } else {
        $rows = $questions;
        $scope = 'all';
    }
    $csv = "\xEF\xBB\xBF" . question_export_csv_admin($rows);
    $filename = 'questions_' . $scope . '_' . date('Ymd_His') . '.csv';
    audit_log_admin_event([
        'admin_label'=>$session['label'] ?? '最高位管理者',
        'action'=>'question_csv_export',
        'result'=>'success',
        'target_type'=>'questions',
        'target_hash_prefix'=>$scope,
        'message'=>'exported ' . count($rows) . ' questions'
    ]);
    admin_json(200, ['ok'=>true,'filename'=>$filename,'csv'=>$csv,'count'=>count($rows)]);
}


if ($action === 'question_csv_preview') {
    $csv = (string)($data['csv'] ?? '');
    if (trim($csv) === '') admin_json(400, ['ok'=>false,'message'=>'CSVデータが空です。']);
    $csv_hash = question_csv_hash_admin($csv);
    $duplicate = find_question_csv_history_admin($csv_hash);
    $preview = question_csv_preview_admin($csv);
    if ($duplicate && !question_csv_preview_has_actionable_changes_admin($preview)) {
        admin_json(409, ['ok'=>false,'error'=>'duplicate_csv_import','message'=>duplicate_question_csv_message_admin($duplicate),'csv_hash'=>$csv_hash,'duplicate'=>$duplicate]);
    }
    admin_json(200, ['ok'=>true,'csv_hash'=>$csv_hash,'duplicate_warning'=>$duplicate ? duplicate_question_csv_message_admin($duplicate) : '', 'duplicate'=>$duplicate] + $preview);
}

if ($action === 'question_csv_apply') {
    $csv = (string)($data['csv'] ?? '');
    $selected = isset($data['selected_row_keys']) && is_array($data['selected_row_keys']) ? array_values(array_map('strval', $data['selected_row_keys'])) : [];
    $fix_config = !empty($data['fix_game_config']);
    $file_name = (string)($data['file_name'] ?? '');
    if (trim($csv) === '') admin_json(400, ['ok'=>false,'message'=>'CSVデータが空です。']);
    $csv_hash = question_csv_hash_admin($csv);
    $duplicate = find_question_csv_history_admin($csv_hash);
    $preview = question_csv_preview_admin($csv);
    if ($duplicate && !question_csv_preview_has_actionable_changes_admin($preview)) {
        admin_json(409, ['ok'=>false,'error'=>'duplicate_csv_import','message'=>duplicate_question_csv_message_admin($duplicate),'csv_hash'=>$csv_hash,'duplicate'=>$duplicate]);
    }
    $selected_set = array_fill_keys($selected, true);
    $apply_items = [];
    foreach ($preview['items'] as $it) {
        $kind = $it['change_kind'] ?? 'error';
        if ($kind === 'error' || $kind === 'unchanged') continue;
        if (!isset($selected_set[$it['row_key'] ?? ''])) continue;
        $apply_items[] = $it;
    }
    if (!$apply_items) admin_json(400, ['ok'=>false,'message'=>'反映対象がありません。']);
    $questions = load_questions_admin();
    $backup = backup_questions_admin('csv_import');
    backup_game_config_admin($backup['id'], 'csv_import');
    $statuses_to_set = [];
    $new_questions = [];
    $updated_count = 0;
    $added_count = 0;
    foreach ($apply_items as $it) {
        $qid = $it['proposed_id'];
        $q = $it['question'];
        $found = false;
        foreach ($questions as $i=>$old) {
            if (($old['id'] ?? '') === $qid) {
                $questions[$i] = $q;
                $found = true;
                $updated_count++;
                break;
            }
        }
        if (!$found) {
            $questions[] = $q;
            $new_questions[] = $q;
            $added_count++;
        }
        $statuses_to_set[$qid] = $it['status'] ?? ($found ? question_status_get($qid) : 'draft');
    }
    if (!save_questions_admin($questions)) admin_json(500, ['ok'=>false,'message'=>'questions.json を保存できませんでした。']);
    foreach ($statuses_to_set as $id=>$st) question_status_set_many([$id], $st);
    if ($new_questions) {
        new_question_history_record_questions($new_questions, 'csv_import', $admin_label);
        $known = known_question_ids_read();
        known_question_ids_write(array_merge($known['ids'] ?? [], array_map(function($q){ return $q['id'] ?? ''; }, $new_questions)));
    }
    $config_fixed = false;
    $mismatches = game_config_mismatches_admin($questions, load_game_config_admin());
    if ($fix_config && !empty($mismatches)) $config_fixed = update_game_config_counts_admin($questions);
    $version_id = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    append_question_version_admin([
        'version_id'=>$version_id,
        'at'=>date('Y-m-d H:i:s'),
        'admin_label'=>$admin_label,
        'action'=>'csv_import',
        'question_id'=>'CSV',
        'backup_id'=>$backup['id'],
        'backup_file'=>$backup['file'],
        'backup_path'=>$backup['path'],
        'before'=>null,
        'after'=>null,
        'changed_fields'=>['questions.json','question_status.json',($config_fixed?'game_config.json':'')],
        'result_question_count'=>count($questions),
        'message'=>'csv import added=' . $added_count . ' updated=' . $updated_count . ' config_fixed=' . ($config_fixed ? '1' : '0')
    ]);
    $csv_record = record_question_csv_history_admin($csv, $admin_label, ['added'=>$added_count,'updated'=>$updated_count,'game_config_fixed'=>$config_fixed,'version_id'=>$version_id], $file_name);
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'question_csv_apply','result'=>'success','target_type'=>'questions','target_hash_prefix'=>substr($csv_hash,0,12),'message'=>'added=' . $added_count . ' updated=' . $updated_count]);
    admin_json(200, ['ok'=>true,'message'=>'CSVを反映しました。','added'=>$added_count,'updated'=>$updated_count,'backup_file'=>$backup['file'],'version_id'=>$version_id,'game_config_fixed'=>$config_fixed,'csv_hash'=>$csv_hash,'csv_import_record'=>$csv_record]);
}

if ($action === 'question_backup_list') {
    admin_json(200, ['ok'=>true,'backups'=>question_backup_list_admin()]);
}

if ($action === 'question_backup_restore') {
    $id = (string)($data['backup_id'] ?? '');
    $result = question_backup_restore_admin($id, $admin_label);
    if (empty($result['ok'])) admin_json(400, $result + ['ok'=>false]);
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'question_backup_restore','result'=>'success','target_type'=>'question_backup','target_hash_prefix'=>$id,'message'=>$result['message'] ?? '']);
    admin_json(200, $result);
}


if ($action === 'question_backup_delete') {
    $id = (string)($data['backup_id'] ?? '');
    $result = question_backup_delete_admin($id, $admin_label);
    if (empty($result['ok'])) admin_json(400, $result + ['ok'=>false]);
    audit_log_admin_event(['admin_label'=>$admin_label,'action'=>'question_backup_delete','result'=>'success','target_type'=>'question_backup','target_hash_prefix'=>$id,'message'=>implode(',', $result['deleted'] ?? [])]);
    admin_json(200, $result);
}

if ($action === 'question_get') {
    $id = normalize_question_id_admin($data['id'] ?? '');
    if ($id === '') admin_json(400, ['ok'=>false,'message'=>'問題IDが必要です。']);
    $questions = load_questions_admin();
    foreach ($questions as $q) {
        if (($q['id'] ?? '') === $id) admin_json(200, ['ok'=>true,'question'=>$q,'status'=>question_status_get($id),'status_label'=>question_status_label_admin(question_status_get($id))]);
    }
    admin_json(404, ['ok'=>false,'message'=>'問題が見つかりません。']);
}

if ($action === 'question_save') {
    $question = $data['question'] ?? null;
    $original_id = normalize_question_id_admin($data['original_id'] ?? '');
    $requested_status = question_status_valid($data['status'] ?? ($original_id === '' ? 'draft' : question_status_get($original_id)));
    $questions = load_questions_admin();
    $existing_question_for_update = $original_id !== '' ? question_by_id_admin($questions, $original_id) : null;
    if ($existing_question_for_update !== null) {
        if (($question['id'] ?? '') !== $original_id) {
            admin_json(400, ['ok'=>false,'message'=>'既存問題のIDは変更できません。']);
        }
        if (($question['type'] ?? '') !== ($existing_question_for_update['type'] ?? '')) {
            admin_json(400, ['ok'=>false,'message'=>'既存問題の種別は変更できません。']);
        }
    }
    $ids = [];
    foreach ($questions as $q) $ids[$q['id'] ?? ''] = true;
    $error = validate_question_admin($question, $ids, $original_id);
    if ($error !== '') admin_json(400, ['ok'=>false,'message'=>$error]);
    $expected_prefix = ($question['type'] ?? '') === 'attack' ? 'A' : (($question['type'] ?? '') === 'basic' ? 'B' : 'D');
    if (strpos((string)($question['id'] ?? ''), $expected_prefix) !== 0) {
        admin_json(400, ['ok'=>false,'message'=>'問題IDの接頭辞が種別と一致していません。攻撃=A、守備=D、基本=Bです。']);
    }
    $found = false;
    $new_id = $question['id'];
    $before_question = question_by_id_admin($questions, $original_id);
    $before_status_for_change = $original_id !== '' ? question_status_get($original_id) : '';
    $backup = backup_questions_admin('save_' . $original_id);
    foreach ($questions as $i=>$q) {
        if (($q['id'] ?? '') === $original_id) {
            $questions[$i] = $question;
            $found = true;
            break;
        }
    }
    if (!$found) $questions[] = $question;
    if (!save_questions_admin($questions)) admin_json(500, ['ok'=>false,'message'=>'questions.json を保存できませんでした。']);
    question_status_set_many([$new_id], $requested_status);
    if (!$found) {
        if ($requested_status === 'draft') {
            new_question_history_record_questions([$question], 'manual', $admin_label);
        }
        $known = known_question_ids_read();
        known_question_ids_write(array_merge($known['ids'] ?? [], [$new_id]));
    }
    $change = question_change_summary_admin($before_question, $question);
    if ($before_status_for_change !== $requested_status) $change['fields'][] = 'status';
    $version_id = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    append_question_version_admin([
        'version_id'=>$version_id,
        'at'=>date('Y-m-d H:i:s'),
        'admin_label'=>$admin_label,
        'action'=>($found ? 'update' : 'add'),
        'question_id'=>$new_id,
        'original_id'=>$original_id,
        'backup_id'=>$backup['id'],
        'backup_file'=>$backup['file'],
        'backup_path'=>$backup['path'],
        'before'=>$before_question,
        'after'=>$question,
        'changed_fields'=>$change['fields'],
        'result_question_count'=>count($questions),
        'message'=>($found?'updated ':'added ') . $new_id
    ]);
    audit_log_admin_event([
        'admin_label'=>$admin_label,
        'action'=>'question_save',
        'result'=>'success',
        'target_type'=>'question',
        'target_hash_prefix'=>$new_id,
        'message'=>($found?'updated ':'added ') . $new_id . ' / version=' . $version_id
    ]);
    admin_json(200, ['ok'=>true,'message'=>($found?'問題を更新しました。':'問題を追加しました。'),'id'=>$new_id,'version_id'=>$version_id]);
}

if ($action === 'question_delete') {
    $id = normalize_question_id_admin($data['id'] ?? '');
    if ($id === '') admin_json(400, ['ok'=>false,'message'=>'問題IDが必要です。']);
    $questions = load_questions_admin();
    $before_question = question_by_id_admin($questions, $id);
    $reason = delete_block_reason_admin($questions, $id);
    if ($reason !== '') {
        audit_log_admin_event([
            'admin_label'=>$admin_label,
            'action'=>'question_delete_blocked',
            'result'=>'blocked',
            'target_type'=>'question',
            'target_hash_prefix'=>$id,
            'message'=>$reason
        ]);
        admin_json(409, ['ok'=>false,'blocked'=>true,'message'=>$reason]);
    }
    $before = count($questions);
    $questions = array_values(array_filter($questions, function($q) use ($id) { return ($q['id'] ?? '') !== $id; }));
    if (count($questions) === $before) admin_json(404, ['ok'=>false,'message'=>'問題が見つかりません。']);
    $backup = backup_questions_admin('delete_' . $id);
    if (!save_questions_admin($questions)) admin_json(500, ['ok'=>false,'message'=>'questions.json を保存できませんでした。']);
    $version_id = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    append_question_version_admin([
        'version_id'=>$version_id,
        'at'=>date('Y-m-d H:i:s'),
        'admin_label'=>$admin_label,
        'action'=>'delete',
        'question_id'=>$id,
        'original_id'=>$id,
        'backup_id'=>$backup['id'],
        'backup_file'=>$backup['file'],
        'backup_path'=>$backup['path'],
        'before'=>$before_question,
        'after'=>null,
        'changed_fields'=>array_keys($before_question ?? []),
        'result_question_count'=>count($questions),
        'message'=>'deleted ' . $id
    ]);
    audit_log_admin_event([
        'admin_label'=>$admin_label,
        'action'=>'question_delete',
        'result'=>'success',
        'target_type'=>'question',
        'target_hash_prefix'=>$id,
        'message'=>'deleted ' . $id . ' / version=' . $version_id
    ]);
    admin_json(200, ['ok'=>true,'message'=>'問題を削除しました。','version_id'=>$version_id]);
}

if ($action === 'question_versions') {
    admin_json(200, ['ok'=>true,'versions'=>summarize_question_versions_admin(300)]);
}

if ($action === 'question_version_get') {
    $version_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['version_id'] ?? ''));
    if ($version_id === '') admin_json(400, ['ok'=>false,'message'=>'version_id が必要です。']);
    $v = find_question_version_admin($version_id);
    if (!$v) admin_json(404, ['ok'=>false,'message'=>'変更履歴が見つかりません。']);
    admin_json(200, ['ok'=>true,'version'=>$v]);
}

if ($action === 'question_restore_version') {
    $version_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['version_id'] ?? ''));
    if ($version_id === '') admin_json(400, ['ok'=>false,'message'=>'version_id が必要です。']);
    $v = find_question_version_admin($version_id);
    if (!$v) admin_json(404, ['ok'=>false,'message'=>'変更履歴が見つかりません。']);
    $backup_path = $v['backup_path'] ?? '';
    if ($backup_path === '' || !is_file($backup_path)) {
        admin_json(404, ['ok'=>false,'message'=>'復元用バックアップファイルが見つかりません。']);
    }
    $current_backup = backup_questions_admin('before_restore_' . $version_id);
    $raw = file_get_contents($backup_path);
    $json = json_decode($raw ?: '[]', true);
    if (!is_array($json)) admin_json(500, ['ok'=>false,'message'=>'バックアップJSONを読み込めません。']);
    if (!save_questions_admin($json)) admin_json(500, ['ok'=>false,'message'=>'questions.json を復元できませんでした。']);
    $restore_id = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    append_question_version_admin([
        'version_id'=>$restore_id,
        'at'=>date('Y-m-d H:i:s'),
        'admin_label'=>$admin_label,
        'action'=>'restore',
        'question_id'=>$v['question_id'] ?? '',
        'original_id'=>$v['original_id'] ?? '',
        'backup_id'=>$current_backup['id'],
        'backup_file'=>$current_backup['file'],
        'backup_path'=>$current_backup['path'],
        'restored_from_version_id'=>$version_id,
        'restored_from_backup_file'=>$v['backup_file'] ?? '',
        'before'=>null,
        'after'=>null,
        'changed_fields'=>['questions.json'],
        'result_question_count'=>count($json),
        'message'=>'restored to version before ' . ($v['action'] ?? '') . ' ' . ($v['question_id'] ?? '')
    ]);
    audit_log_admin_event([
        'admin_label'=>$admin_label,
        'action'=>'question_restore_version',
        'result'=>'success',
        'target_type'=>'question_version',
        'target_hash_prefix'=>$version_id,
        'message'=>'restored version=' . $version_id . ' / restore_log=' . $restore_id
    ]);
    admin_json(200, ['ok'=>true,'message'=>'選択した変更前のバージョンへ復元しました。','restore_version_id'=>$restore_id,'question_count'=>count($json)]);
}

if ($action === 'logout') {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($data['token'] ?? ''));
    $db = load_admin_session_db();
    if (isset($db['sessions'][$token])) unset($db['sessions'][$token]);
    save_admin_session_db($db);
    audit_log_admin_event([
        'admin_label'=>$admin_label,
        'action'=>'logout',
        'result'=>'success',
        'message'=>'super admin logout'
    ]);
    admin_json(200, ['ok'=>true]);
}

admin_json(400, ['ok'=>false,'error'=>'unknown action','message'=>'不明な操作です。']);
?>
