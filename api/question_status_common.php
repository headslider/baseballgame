<?php
require_once __DIR__ . '/feature_common.php';

function question_status_file_path() {
    return feature_scores_dir() . '/question_status.json';
}
function question_known_ids_file_path() {
    return feature_scores_dir() . '/known_question_ids.json';
}
function new_question_history_file_path() {
    return feature_scores_dir() . '/new_question_import_history.json';
}
function question_status_read_db() {
    $db = read_json_file_safe(question_status_file_path(), ['statuses'=>[], 'updated_at'=>'']);
    if (!isset($db['statuses']) || !is_array($db['statuses'])) $db['statuses'] = [];
    return $db;
}
function question_status_write_db($db) {
    if (!isset($db['statuses']) || !is_array($db['statuses'])) $db['statuses'] = [];
    $db['updated_at'] = date('Y-m-d H:i:s');
    return write_json_file_locked(question_status_file_path(), $db);
}
function question_status_valid($status) {
    return in_array($status, ['draft','published','disabled'], true) ? $status : 'published';
}
function question_status_get($id) {
    $db = question_status_read_db();
    $id = trim((string)$id);
    return question_status_valid($db['statuses'][$id] ?? 'published');
}
function question_status_set_many($ids, $status) {
    $status = question_status_valid($status);
    $db = question_status_read_db();
    foreach ($ids as $id) {
        $id = trim((string)$id);
        if ($id !== '') $db['statuses'][$id] = $status;
    }
    question_status_write_db($db);
}
function questions_data_file_path_for_status() {
    return __DIR__ . '/../data/questions.json';
}
function question_status_load_questions() {
    $file = questions_data_file_path_for_status();
    $json = json_decode(is_file($file) ? file_get_contents($file) : '[]', true);
    if (!is_array($json)) return [];
    if (isset($json['questions']) && is_array($json['questions'])) return $json['questions'];
    if (isset($json['items']) && is_array($json['items'])) return $json['items'];
    return $json;
}
function question_title_for_notice($q) {
    $parts = [];
    foreach (['theme','situation','prompt','ball_tag','stage'] as $k) {
        $v = trim((string)($q[$k] ?? ''));
        if ($v !== '') $parts[] = $v;
    }
    $title = trim(implode(' / ', array_slice($parts, 0, 3)));
    if ($title === '') $title = trim((string)($q['id'] ?? '')) . ' の新規問題';
    if (function_exists('mb_substr') && mb_strlen($title, 'UTF-8') > 80) $title = mb_substr($title, 0, 80, 'UTF-8') . '…';
    return $title;
}
function question_type_label_for_notice($type) {
    $type = strtolower(trim((string)$type));
    if ($type === 'basic') return '基礎';
    if ($type === 'attack') return '攻撃';
    if ($type === 'defense') return '守備';
    return $type !== '' ? $type : '-';
}
function question_grade_label_for_notice($grade) {
    $g = trim((string)$grade);
    if ($g === '2' || $g === '2年' || $g === '3年生以下') return '三年生以下';
    if (in_array($g, ['3','4','5','6'], true)) return $g . '年生';
    return $g !== '' ? $g : '-';
}
function question_tag_for_notice($q) {
    // 通知・管理画面のタグ表示は、問題管理で実際に利用している ball_tag を最優先にする。
    // ただし、過去にCSVから取り込んだ履歴では tag が '-' のまま、かつ問題側に theme だけが残る場合があるため、
    // 代表的な theme はユーザー向けの短いタグ名へ変換して表示する。
    foreach (['ball_tag','tag','theme_label'] as $k) {
        $v = trim((string)($q[$k] ?? ''));
        if ($v !== '' && $v !== '-') return $v;
    }
    $theme = trim((string)($q['theme'] ?? ''));
    if ($theme !== '' && $theme !== '-') {
        $map = [
            'basic_baserunning_slide' => 'スライディング',
            'baserunning_slide' => 'スライディング',
            'slide' => 'スライディング',
        ];
        return $map[$theme] ?? $theme;
    }
    $title = trim((string)($q['title'] ?? ''));
    if ($title !== '' && strpos($title, ' / ') !== false) {
        $first = trim(explode(' / ', $title, 2)[0]);
        if ($first !== '' && $first !== '-') {
            if ($first === 'basic_baserunning_slide') return 'スライディング';
            return $first;
        }
    }
    return '-';
}
function new_question_history_read_db() {
    $db = read_json_file_safe(new_question_history_file_path(), ['items'=>[], 'updated_at'=>'']);
    if (!isset($db['items']) || !is_array($db['items'])) $db['items'] = [];
    return $db;
}
function new_question_history_write_db($db) {
    if (!isset($db['items']) || !is_array($db['items'])) $db['items'] = [];
    $db['updated_at'] = date('Y-m-d H:i:s');
    return write_json_file_locked(new_question_history_file_path(), $db);
}
function known_question_ids_read() {
    $db = read_json_file_safe(question_known_ids_file_path(), ['ids'=>[], 'updated_at'=>'']);
    if (!isset($db['ids']) || !is_array($db['ids'])) $db['ids'] = [];
    return $db;
}
function known_question_ids_write($ids) {
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), function($v){ return trim($v) !== ''; })));
    return write_json_file_locked(question_known_ids_file_path(), ['ids'=>$ids, 'updated_at'=>date('Y-m-d H:i:s')]);
}
function new_question_history_record_questions($questions, $source='manual', $admin_label='') {
    $hist = new_question_history_read_db();
    $existing = [];
    foreach ($hist['items'] as $it) $existing[$it['id'] ?? ''] = true;
    $now = date('Y-m-d H:i:s');
    $added = [];
    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        $id = trim((string)($q['id'] ?? ''));
        if ($id === '') continue;
        if (isset($existing[$id])) continue;
        $item = [
            'id'=>$id,
            'title'=>question_title_for_notice($q),
            'type'=>(string)($q['type'] ?? ''),
            'type_label'=>question_type_label_for_notice($q['type'] ?? ''),
            'grade'=>$q['grade'] ?? '',
            'grade_label'=>question_grade_label_for_notice($q['grade'] ?? ''),
            'tag'=>question_tag_for_notice($q),
            'ball_tag'=>(string)($q['ball_tag'] ?? ''),
            'theme_label'=>(string)($q['theme_label'] ?? ''),
            'position'=>isset($q['positions']) && is_array($q['positions']) ? implode(',', $q['positions']) : '',
            'added_at'=>$now,
            'added_by'=>$admin_label,
            'source'=>$source,
            'notice_status'=>'unscheduled',
            'scheduled_notice_id'=>'',
            'notified_at'=>'',
        ];
        $hist['items'][] = $item;
        $existing[$id] = true;
        $added[] = $item;
    }
    if ($added) new_question_history_write_db($hist);
    return $added;
}
function sync_new_question_candidates($admin_label='') {
    $questions = question_status_load_questions();
    $current_ids = [];
    $by_id = [];
    foreach ($questions as $q) {
        $id = trim((string)($q['id'] ?? ''));
        if ($id === '') continue;
        $current_ids[] = $id;
        $by_id[$id] = $q;
    }
    $known = known_question_ids_read();
    if (empty($known['ids'])) {
        known_question_ids_write($current_ids);
        return [];
    }
    $known_set = array_fill_keys($known['ids'], true);
    $new = [];
    foreach ($current_ids as $id) {
        if (empty($known_set[$id])) $new[] = $by_id[$id];
    }
    if ($new) {
        question_status_set_many(array_map(function($q){ return $q['id'] ?? ''; }, $new), 'draft');
        new_question_history_record_questions($new, 'detected', $admin_label);
        known_question_ids_write(array_merge($known['ids'], array_map(function($q){ return $q['id'] ?? ''; }, $new)));
    }
    return $new;
}
function new_question_notice_candidates($admin_label='') {
    sync_new_question_candidates($admin_label);
    $hist = new_question_history_read_db();
    $questions = question_status_load_questions();
    $by_id = [];
    foreach ($questions as $q) {
        $qid = trim((string)($q['id'] ?? ''));
        if ($qid !== '') {
            $by_id[$qid] = $q;
            $by_id[strtoupper($qid)] = $q;
        }
    }
    $items = [];
    $hist_changed = false;
    foreach ($hist['items'] as $idx => $it) {
        $id = (string)($it['id'] ?? '');
        if ($id === '') continue;
        $q = $by_id[$id] ?? ($by_id[strtoupper($id)] ?? []);
        $status = question_status_get($id);
        $current_tag = is_array($q) ? question_tag_for_notice($q) : '-';
        // questions.json 側で取得できない場合でも、履歴のtitle/theme相当から補完する。
        if ($current_tag === '-' || $current_tag === '') {
            $current_tag = question_tag_for_notice([
                'ball_tag'=>$it['ball_tag'] ?? '',
                'tag'=>$it['tag'] ?? '',
                'theme_label'=>$it['theme_label'] ?? '',
                'theme'=>$it['theme'] ?? '',
                'title'=>$it['title'] ?? '',
            ]);
        }
        $stored_tag = trim((string)($it['tag'] ?? ''));
        $stored_ball_tag = trim((string)($it['ball_tag'] ?? ''));
        $display_tag = '-';
        if ($current_tag !== '' && $current_tag !== '-') {
            $display_tag = $current_tag;
        } elseif ($stored_ball_tag !== '' && $stored_ball_tag !== '-') {
            $display_tag = $stored_ball_tag;
        } elseif ($stored_tag !== '' && $stored_tag !== '-') {
            $display_tag = $stored_tag;
        }

        // 古い履歴で tag が '-' のまま残っていても、現在の questions.json の ball_tag で補完して保存し直す。
        if ($display_tag !== '-' && (($hist['items'][$idx]['tag'] ?? '') !== $display_tag || empty($hist['items'][$idx]['ball_tag']) || $hist['items'][$idx]['ball_tag'] === '-')) {
            $hist['items'][$idx]['tag'] = $display_tag;
            $hist['items'][$idx]['ball_tag'] = $display_tag;
            $hist_changed = true;
        }

        $items[] = array_merge($it, [
            'status'=>$status,
            'status_label'=>($status === 'draft' ? '下書き' : ($status === 'disabled' ? '停止' : '公開')),
            'type_label'=>($it['type_label'] ?? question_type_label_for_notice($q['type'] ?? ($it['type'] ?? ''))),
            'grade_label'=>($it['grade_label'] ?? question_grade_label_for_notice($q['grade'] ?? ($it['grade'] ?? ''))),
            'tag'=>$display_tag,
            'ball_tag'=>$display_tag,
        ]);
    }
    if ($hist_changed) new_question_history_write_db($hist);
    usort($items, function($a, $b){ return strcmp($b['added_at'] ?? '', $a['added_at'] ?? ''); });
    return $items;
}
function mark_new_questions_scheduled($ids, $schedule_id, $scheduled_delivery_at='') {
    $hist = new_question_history_read_db();
    $set = array_fill_keys($ids, true);
    foreach ($hist['items'] as &$it) {
        if (isset($set[$it['id'] ?? ''])) {
            $it['notice_status'] = 'scheduled';
            $it['scheduled_notice_id'] = $schedule_id;
            $it['scheduled_at'] = date('Y-m-d H:i:s');
            $it['scheduled_delivery_at'] = $scheduled_delivery_at !== '' ? $scheduled_delivery_at : ($it['scheduled_delivery_at'] ?? '');
        }
    }
    unset($it);
    new_question_history_write_db($hist);
}
function mark_new_questions_notified($ids, $schedule_id='') {
    $hist = new_question_history_read_db();
    $set = array_fill_keys($ids, true);
    foreach ($hist['items'] as &$it) {
        if (isset($set[$it['id'] ?? ''])) {
            $it['notice_status'] = 'notified';
            $it['scheduled_notice_id'] = $schedule_id ?: ($it['scheduled_notice_id'] ?? '');
            $it['notified_at'] = date('Y-m-d H:i:s');
        }
    }
    unset($it);
    new_question_history_write_db($hist);
}
function mark_new_questions_unscheduled($ids, $schedule_id='') {
    $hist = new_question_history_read_db();
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), function($v){ return trim($v) !== ''; })));
    $set = array_fill_keys($ids, true);
    $changed = 0;
    foreach ($hist['items'] as &$it) {
        $id = (string)($it['id'] ?? '');
        if (!isset($set[$id])) continue;
        $currentSchedule = (string)($it['scheduled_notice_id'] ?? '');
        if ($schedule_id !== '' && $currentSchedule !== $schedule_id) continue;
        if (($it['notice_status'] ?? '') === 'scheduled') {
            $it['notice_status'] = 'unscheduled';
            $it['scheduled_notice_id'] = '';
            $it['scheduled_at'] = '';
            $it['scheduled_delivery_at'] = '';
            $changed++;
        }
    }
    unset($it);
    if ($changed > 0) new_question_history_write_db($hist);
    return $changed;
}
?>
