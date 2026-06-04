<?php
require_once __DIR__ . '/feature_common.php';
require_once __DIR__ . '/send_push_notification.php';

function push_notification_settings_file() {
    return feature_scores_dir() . '/push_notification_settings.json';
}
function default_push_notification_settings() {
    return [
        'ranking_notice' => [
            'enabled' => false,
            'ranking_type' => 'weekly_total',
            'aggregate_day' => 'sunday',
            'aggregate_time' => '00:00',
            'delivery_day' => 'monday',
            'delivery_time' => '18:00'
        ]
    ];
}
function read_push_notification_settings() {
    $file = push_notification_settings_file();
    $defaults = default_push_notification_settings();
    if (!is_file($file)) return $defaults;
    $db = json_decode(file_get_contents($file), true);
    if (!is_array($db)) return $defaults;
    $db['ranking_notice'] = array_merge($defaults['ranking_notice'], is_array($db['ranking_notice'] ?? null) ? $db['ranking_notice'] : []);
    return $db;
}
function save_push_notification_settings($settings) {
    $defaults = default_push_notification_settings();
    $incoming = is_array($settings['ranking_notice'] ?? null) ? $settings['ranking_notice'] : [];
    $allowed_days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    $rn = array_merge($defaults['ranking_notice'], $incoming);
    $rn['enabled'] = !empty($rn['enabled']);
    if (!in_array($rn['aggregate_day'], $allowed_days, true)) $rn['aggregate_day'] = 'sunday';
    if (!in_array($rn['delivery_day'], $allowed_days, true)) $rn['delivery_day'] = 'monday';
    if (!preg_match('/^\d{2}:\d{2}$/', $rn['aggregate_time'])) $rn['aggregate_time'] = '00:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $rn['delivery_time'])) $rn['delivery_time'] = '18:00';
    $db = ['ranking_notice'=>$rn, 'updated_at'=>date('Y-m-d H:i:s')];
    file_put_contents(push_notification_settings_file(), json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)), LOCK_EX);
    return $db;
}
function weekly_ranking_snapshot_file() {
    return feature_scores_dir() . '/weekly_ranking_snapshot.json';
}
function weekly_ranking_notice_history_file() {
    return feature_scores_dir() . '/weekly_ranking_notice_history.json';
}
function weekly_rank_ts($s) {
    $t = strtotime($s);
    return $t ? $t : 0;
}

function weekly_logs_correct_question_ids($logs_json, $fallback_score = 0, $fallback_prefix = '') {
    $logs = json_decode($logs_json ?? '[]', true);
    $ids = [];
    if (is_array($logs) && count($logs) > 0) {
        foreach ($logs as $idx => $log) {
            if (!is_array($log)) continue;
            if (intval($log['score'] ?? 0) !== 3) continue;
            $qid = trim((string)($log['id'] ?? ''));
            if ($qid === '') $qid = $fallback_prefix !== '' ? ($fallback_prefix . '_correct_' . $idx) : ('unknown_correct_' . $idx);
            $ids[$qid] = true;
        }
        return array_keys($ids);
    }
    $n = max(0, intval(floor(intval($fallback_score ?? 0) / 3)));
    for ($i=0; $i<$n; $i++) {
        $ids[($fallback_prefix !== '' ? $fallback_prefix : 'legacy') . '_correct_' . $i] = true;
    }
    return array_keys($ids);
}

function weekly_logs_correct_count($logs_json, $fallback_score = 0) {
    return count(weekly_logs_correct_question_ids($logs_json, $fallback_score, 'legacy'));
}

function weekly_rank_sort($a, $b) {
    // v683: 週間ランキングも「クリア問題数」を最優先。プレイ回数は順位判定に使わない。
    if (($a['correct_count'] ?? 0) !== ($b['correct_count'] ?? 0)) return ($b['correct_count'] ?? 0) <=> ($a['correct_count'] ?? 0);
    if (($a['max_grade'] ?? 0) !== ($b['max_grade'] ?? 0)) return ($b['max_grade'] ?? 0) <=> ($a['max_grade'] ?? 0);
    if (($a['average_score'] ?? 0) != ($b['average_score'] ?? 0)) return ($b['average_score'] ?? 0) <=> ($a['average_score'] ?? 0);
    if (($a['best_score'] ?? 0) !== ($b['best_score'] ?? 0)) return ($b['best_score'] ?? 0) <=> ($a['best_score'] ?? 0);
    return ($b['_latest_ts'] ?? 0) <=> ($a['_latest_ts'] ?? 0);
}
function compute_weekly_total_ranking($now_ts = null) {
    if ($now_ts === null) $now_ts = time();
    $week_start = strtotime('monday this week 00:00:00', $now_ts);
    if ($week_start > $now_ts) $week_start = strtotime('-7 days', $week_start);
    $week_end = strtotime('+7 days', $week_start);
    $week_key = date('o-\WW', $week_start);

    $file = feature_scores_dir() . '/score_log.csv';
    $summary = [];
    if (is_file($file) && ($fp = fopen($file, 'r'))) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if (count($row) < 8) continue;
            $ts = weekly_rank_ts($row[0] ?? '');
            if ($ts < $week_start || $ts >= $week_end) continue;
            $pid = normalize_player_id($row[1] ?? '');
            if ($pid === '') continue;
            $grade = intval($row[2] ?? 0);
            if ($grade <= 3) continue; // 3年生以下・ランキング対象外モードは除外
            $score = intval($row[4] ?? 0);
            $record_key = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)($row[0] ?? '')) . '_' . $pid;
            $correct_ids = weekly_logs_correct_question_ids($row[8] ?? '[]', $score, $record_key);
            $correct_count = count($correct_ids);
            if (!isset($summary[$pid])) {
                $summary[$pid] = [
                    'player_id'=>$pid,
                    'play_count'=>0,
                    'correct_count'=>0,
                    'correct_ids'=>[],
                    'total_sum'=>0,
                    'best_score'=>0,
                    'max_grade'=>0,
                    '_latest_ts'=>0,
                    'latest_played_at'=>''
                ];
            }
            $summary[$pid]['play_count']++;
            foreach ($correct_ids as $qid) { $summary[$pid]['correct_ids'][$qid] = true; }
            $summary[$pid]['correct_count'] = count($summary[$pid]['correct_ids']);
            $summary[$pid]['total_sum'] += $score;
            if ($score > $summary[$pid]['best_score']) $summary[$pid]['best_score'] = $score;
            if ($grade > $summary[$pid]['max_grade']) $summary[$pid]['max_grade'] = $grade;
            if ($ts > $summary[$pid]['_latest_ts']) {
                $summary[$pid]['_latest_ts'] = $ts;
                $summary[$pid]['latest_played_at'] = $row[0] ?? '';
            }
        }
        fclose($fp);
    }
    $rows = [];
    foreach ($summary as $pid => $s) {
        $count = max(1, intval($s['play_count']));
        $s['average_score'] = round($s['total_sum'] / $count, 1);
        unset($s['total_sum'], $s['correct_ids']);
        $rows[] = $s;
    }
    usort($rows, 'weekly_rank_sort');
    foreach ($rows as $i => &$r) {
        $r['rank'] = $i + 1;
        unset($r['_latest_ts'], $r['correct_ids']);
    }
    unset($r);
    return [
        'week_key'=>$week_key,
        'week_start'=>date('Y-m-d H:i:s', $week_start),
        'week_end'=>date('Y-m-d H:i:s', $week_end),
        'ranking'=>$rows,
        'top3'=>array_slice($rows, 0, 3)
    ];
}
function aggregate_weekly_ranking_notice($force = false) {
    $current = compute_weekly_total_ranking();
    $file = weekly_ranking_snapshot_file();
    $previous = is_file($file) ? json_decode(file_get_contents($file), true) : null;
    $prev_top3 = is_array($previous['top3'] ?? null) ? $previous['top3'] : [];
    $changed = false;
    $changed_ranks = [];
    for ($i=0; $i<3; $i++) {
        $old = $prev_top3[$i] ?? null;
        $new = $current['top3'][$i] ?? null;
        $old_pid = is_array($old) ? ($old['player_id'] ?? '') : '';
        $new_pid = is_array($new) ? ($new['player_id'] ?? '') : '';
        if ($old_pid !== $new_pid) {
            $changed = true;
            $changed_ranks[] = $i + 1;
        }
    }
    $snapshot = [
        'week_key'=>$current['week_key'],
        'week_start'=>$current['week_start'],
        'week_end'=>$current['week_end'],
        'aggregated_at'=>date('Y-m-d H:i:s'),
        'top3'=>$current['top3'],
        'changed'=>$changed,
        'changed_ranks'=>$changed_ranks,
        'notice_pending'=>$changed,
        'notice_sent_at'=>null
    ];
    file_put_contents($file, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)), LOCK_EX);
    return $snapshot;
}
function append_weekly_ranking_notice_history($rec) {
    $file = weekly_ranking_notice_history_file();
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
function send_weekly_ranking_notice($force = false) {
    $settings = read_push_notification_settings();
    $enabled = !empty($settings['ranking_notice']['enabled']);
    if (!$enabled && !$force) return ['sent'=>0,'failed'=>0,'message'=>'ランキング通知はOFFです。'];
    $file = weekly_ranking_snapshot_file();
    if (!is_file($file)) {
        aggregate_weekly_ranking_notice(true);
    }
    $snapshot = is_file($file) ? json_decode(file_get_contents($file), true) : null;
    if (!is_array($snapshot)) return ['sent'=>0,'failed'=>0,'message'=>'週間ランキング集計データがありません。'];
    if (empty($snapshot['notice_pending']) && !$force) {
        return ['sent'=>0,'failed'=>0,'message'=>'通知対象のランキング変動はありません。'];
    }
    $title = '週間ランキング更新！';
    $body = '今週の1〜3位ランキングが更新されました。ランキングを確認してみよう！';
    $result = send_web_push_notifications($title, $body, './?open=ranking');
    $snapshot['notice_pending'] = false;
    $snapshot['notice_sent_at'] = date('Y-m-d H:i:s');
    file_put_contents($file, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)), LOCK_EX);
    append_weekly_ranking_notice_history([
        'sent_at'=>date('Y-m-d H:i:s'),
        'week_key'=>$snapshot['week_key'] ?? '',
        'changed_ranks'=>$snapshot['changed_ranks'] ?? [],
        'title'=>$title,
        'body'=>$body,
        'sent'=>$result['sent'] ?? 0,
        'failed'=>$result['failed'] ?? 0,
        'message'=>$result['message'] ?? ''
    ]);
    return $result + ['week_key'=>$snapshot['week_key'] ?? '', 'changed_ranks'=>$snapshot['changed_ranks'] ?? []];
}
?>
