<?php
require_once __DIR__ . '/feature_common.php';
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) $raw = '';
if (strlen($raw) > 1024 * 64) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid payload'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$content_type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$data = [];

if (is_array($_GET) && count($_GET) > 0) {
    $data = $_GET;
}
if (is_array($_POST) && count($_POST) > 0) {
    $data = array_merge($data, $_POST);
} elseif (stripos($content_type, 'application/json') !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $data = array_merge($data, $decoded);
} else {
    parse_str($raw, $parsed);
    if (is_array($parsed) && count($parsed) > 0) {
        $data = array_merge($data, $parsed);
    } else {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $data = array_merge($data, $decoded);
    }
}

function to_int_safe($value) {
    return intval($value ?? 0);
}

function read_json_file_safe_ranking($file, $default) {
    if (!is_file($file)) return $default;
    $raw = file_get_contents($file);
    $json = $raw ? json_decode($raw, true) : null;
    return is_array($json) ? $json : $default;
}
function player_has_invite_link_ranking($player_id) {
    $pid = normalize_player_id($player_id);
    if ($pid === '') return false;
    $db = read_json_file_safe_ranking(__DIR__ . '/../scores/player_features.json', ['players'=>[]]);
    $row = $db['players'][$pid] ?? null;
    if (!is_array($row)) return false;
    $sources = $row['sources'] ?? [];
    if (!is_array($sources)) return false;
    foreach ($sources as $source) {
        if (is_array($source) && (($source['type'] ?? '') === 'invite')) return true;
    }
    return false;
}

function logs_time_stats($logs_json) {
    $sum = 0;
    $count = 0;
    $logs = json_decode($logs_json ?? '[]', true);
    if (!is_array($logs)) return ['sum' => 0, 'count' => 0];
    foreach ($logs as $log) {
        if (!is_array($log)) continue;
        if (isset($log['answer_time_ms'])) {
            $ms = intval($log['answer_time_ms']);
            if ($ms > 0) {
                $sum += $ms;
                $count++;
            }
        }
    }
    return ['sum' => $sum, 'count' => $count];
}


function logs_correct_question_ids($logs_json, $fallback_score = 0, $fallback_prefix = '') {
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
    // 旧データなどlogs_jsonが無い場合の保険。問題IDが取れないため仮IDで換算する。
    $n = max(0, intval(floor(intval($fallback_score ?? 0) / 3)));
    for ($i=0; $i<$n; $i++) {
        $ids[($fallback_prefix !== '' ? $fallback_prefix : 'legacy') . '_correct_' . $i] = true;
    }
    return array_keys($ids);
}

function logs_correct_count($logs_json, $fallback_score = 0) {
    return count(logs_correct_question_ids($logs_json, $fallback_score, 'legacy'));
}

function avg_answer_seconds($sum_ms, $count) {
    return $count > 0 ? round(($sum_ms / $count) / 1000, 2) : null;
}

function compare_answer_time($a, $b) {
    $av = $a['average_answer_seconds'] ?? null;
    $bv = $b['average_answer_seconds'] ?? null;
    if ($av === null && $bv === null) return 0;
    if ($av === null) return 1;
    if ($bv === null) return -1;
    if ($av == $bv) return 0;
    return $av <=> $bv;
}

function grade_bonus_value($grade) {
    $g = intval($grade ?? 0);
    if ($g >= 6) return 3.0;
    if ($g === 5) return 2.0;
    if ($g === 4) return 1.0;
    return 0.0;
}

function position_count_bonus_value($position_count) {
    $count = intval($position_count ?? 0);
    if ($count <= 1) return 0.0;
    return min(($count - 1) * 0.5, 4.0);
}

function rank_grade_value($row) {
    return intval($row['max_grade'] ?? $row['grade'] ?? 0);
}

function sort_rows_standard($rows) {
    usort($rows, function($a, $b) {
        // v683: ランキング基準はプレイ回数ではなく「クリア問題数」
        if (($a['correct_count'] ?? 0) !== ($b['correct_count'] ?? 0)) return ($b['correct_count'] ?? 0) <=> ($a['correct_count'] ?? 0);
        $ag = rank_grade_value($a);
        $bg = rank_grade_value($b);
        if ($ag !== $bg) return $bg <=> $ag;
        if (($a['average_score'] ?? 0) != ($b['average_score'] ?? 0)) return ($b['average_score'] ?? 0) <=> ($a['average_score'] ?? 0);
        $time_cmp = compare_answer_time($a, $b);
        if ($time_cmp !== 0) return $time_cmp;
        if (($a['best_score'] ?? 0) !== ($b['best_score'] ?? 0)) return ($b['best_score'] ?? 0) <=> ($a['best_score'] ?? 0);
        return ($b['_latest_ts'] ?? 0) <=> ($a['_latest_ts'] ?? 0);
    });
    foreach ($rows as $i => &$row) {
        $row['rank'] = $i + 1;
    }
    unset($row);
    return $rows;
}

function finalize_summary_rows($summary, $grade_labels) {
    $rows = [];
    foreach ($summary as $pid => $row) {
        $count = max(1, intval($row['play_count'] ?? 0));
        $row['average_score'] = round(($row['total_sum'] ?? 0) / $count, 1);
        $row['average_answer_seconds'] = avg_answer_seconds($row['answer_time_sum_ms'] ?? 0, $row['answer_time_count'] ?? 0);
        $row['max_grade'] = intval($row['max_grade'] ?? $row['grade'] ?? 0);
        $row['max_grade_label'] = isset($grade_labels[$row['max_grade']]) ? $grade_labels[$row['max_grade']] : '';
        unset($row['total_sum'], $row['correct_ids']);
        $rows[] = $row;
    }
    return sort_rows_standard($rows);
}

$viewer_id = normalize_player_id($data['player_id'] ?? '');
if ($viewer_id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'player_id required'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$file = __DIR__ . '/../scores/score_log.csv';
$header = ['played_at','player_id','grade','position','total_score','attack_score','defense_score','max_score','logs_json'];
$records = [];
$deleted_stale_users = 0;
$deleted_stale_records = 0;

if (is_file($file)) {
    $fp = fopen($file, 'c+');
    if (!$fp) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'cannot open score file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
        exit;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'cannot lock score file'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
        exit;
    }

    rewind($fp);
    $csv_header = fgetcsv($fp);
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < 8) continue;
        $records[] = [
            'played_at' => $row[0] ?? '',
            'player_id' => $row[1] ?? '',
            'grade' => to_int_safe($row[2] ?? 0),
            'position' => $row[3] ?? '',
            'total_score' => to_int_safe($row[4] ?? 0),
            'attack_score' => to_int_safe($row[5] ?? 0),
            'defense_score' => to_int_safe($row[6] ?? 0),
            'max_score' => to_int_safe($row[7] ?? 54),
            'logs_json' => $row[8] ?? '',
        ];
    }

    $latest_by_user = [];
    foreach ($records as $record) {
        $pid = $record['player_id'];
        if ($pid === '') continue;
        $ts = strtotime($record['played_at']);
        if ($ts === false) continue;
        if (!isset($latest_by_user[$pid]) || $ts > $latest_by_user[$pid]) {
            $latest_by_user[$pid] = $ts;
        }
    }

    $cutoff = strtotime('-3 months');
    $stale_users = [];
    foreach ($latest_by_user as $pid => $latest_ts) {
        if ($latest_ts < $cutoff) {
            // 招待IDに紐づいたユーザーは、3カ月未ログイン・未プレイでもデータ削除対象にしない。
            if (player_has_invite_link_ranking($pid)) continue;
            $stale_users[$pid] = true;
        }
    }

    if (!empty($stale_users)) {
        $deleted_stale_users = count($stale_users);
        $active_records = [];
        foreach ($records as $record) {
            if (isset($stale_users[$record['player_id']])) {
                $deleted_stale_records++;
                continue;
            }
            $active_records[] = $record;
        }
        $records = $active_records;

        rewind($fp);
        ftruncate($fp, 0);
        fputcsv($fp, $header);
        foreach ($records as $record) {
            fputcsv($fp, [
                $record['played_at'],
                $record['player_id'],
                $record['grade'],
                $record['position'],
                $record['total_score'],
                $record['attack_score'],
                $record['defense_score'],
                $record['max_score'],
                $record['logs_json'],
            ]);
        }
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);
}

$position_labels = [
    'P' => 'ピッチャー',
    'C' => 'キャッチャー',
    '1B' => 'ファースト',
    '2B' => 'セカンド',
    'SS' => 'ショート',
    '3B' => 'サード',
    'LF' => 'レフト',
    'CF' => 'センター',
    'RF' => 'ライト',
];
$grade_labels = [
    3 => '3年生',
    4 => '4年生',
    5 => '5年生',
    6 => '6年生',
];

$position_summary = [];
$position_grade_summary = [];
$user_summary = [];

foreach ($records as $record) {
    $pid = $record['player_id'];
    $pos = $record['position'];
    $grade = to_int_safe($record['grade']);
    if ($pid === '' || !isset($position_labels[$pos])) continue;
    if ($grade <= 2) continue;
    if (!isset($grade_labels[$grade])) $grade = 0;

    if (!isset($position_summary[$pos])) $position_summary[$pos] = [];
    if (!isset($position_summary[$pos][$pid])) {
        $position_summary[$pos][$pid] = [
            'player_id' => $pid,
            'position' => $pos,
            'position_label' => $position_labels[$pos],
            'play_count' => 0,
            'correct_count' => 0,
            'correct_ids' => [],
            'total_sum' => 0,
            'best_score' => 0,
            'latest_played_at' => '',
            'answer_time_sum_ms' => 0,
            'answer_time_count' => 0,
            'max_grade' => 0,
            '_latest_ts' => 0,
        ];
    }

    if ($grade > 0) {
        if (!isset($position_grade_summary[$pos])) $position_grade_summary[$pos] = [];
        if (!isset($position_grade_summary[$pos][$grade])) $position_grade_summary[$pos][$grade] = [];
        if (!isset($position_grade_summary[$pos][$grade][$pid])) {
            $position_grade_summary[$pos][$grade][$pid] = [
                'player_id' => $pid,
                'position' => $pos,
                'position_label' => $position_labels[$pos],
                'grade' => $grade,
                'grade_label' => $grade_labels[$grade],
                'play_count' => 0,
                'correct_count' => 0,
                'correct_ids' => [],
                'total_sum' => 0,
                'best_score' => 0,
                'latest_played_at' => '',
                'answer_time_sum_ms' => 0,
                'answer_time_count' => 0,
                'max_grade' => $grade,
                '_latest_ts' => 0,
            ];
        }
    }

    if (!isset($user_summary[$pid])) {
        $user_summary[$pid] = [
            'player_id' => $pid,
            'play_count' => 0,
            'correct_count' => 0,
            'correct_ids' => [],
            'total_sum' => 0,
            'best_score' => 0,
            'latest_played_at' => '',
            'answer_time_sum_ms' => 0,
            'answer_time_count' => 0,
            'max_grade' => 0,
            '_latest_ts' => 0,
            'position_ranks' => [],
            'position_score_sums' => [],
            'position_play_counts' => [],
            'position_correct_counts' => [],
            'position_correct_ids' => [],
        ];
    }

    $score = to_int_safe($record['total_score']);
    $record_key = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)$record['played_at']) . '_' . $pid . '_' . $pos;
    $correct_ids = logs_correct_question_ids($record['logs_json'] ?? '[]', $score, $record_key);
    $correct_count = count($correct_ids); // 表示上の単発プレイ内クリア問題数用。ランキング集計はID重複を除外する。
    $time_stats = logs_time_stats($record['logs_json'] ?? '[]');
    $ts = strtotime($record['played_at']);
    if ($ts === false) $ts = 0;

    $position_summary[$pos][$pid]['play_count']++;
    foreach ($correct_ids as $qid) { $position_summary[$pos][$pid]['correct_ids'][$qid] = true; }
    $position_summary[$pos][$pid]['correct_count'] = count($position_summary[$pos][$pid]['correct_ids']);
    $position_summary[$pos][$pid]['total_sum'] += $score;
    $position_summary[$pos][$pid]['answer_time_sum_ms'] += $time_stats['sum'];
    $position_summary[$pos][$pid]['answer_time_count'] += $time_stats['count'];
    if ($grade > ($position_summary[$pos][$pid]['max_grade'] ?? 0)) $position_summary[$pos][$pid]['max_grade'] = $grade;
    if ($score > $position_summary[$pos][$pid]['best_score']) $position_summary[$pos][$pid]['best_score'] = $score;
    if ($ts > $position_summary[$pos][$pid]['_latest_ts']) {
        $position_summary[$pos][$pid]['_latest_ts'] = $ts;
        $position_summary[$pos][$pid]['latest_played_at'] = $record['played_at'];
    }

    if ($grade > 0) {
        $position_grade_summary[$pos][$grade][$pid]['play_count']++;
        foreach ($correct_ids as $qid) { $position_grade_summary[$pos][$grade][$pid]['correct_ids'][$qid] = true; }
        $position_grade_summary[$pos][$grade][$pid]['correct_count'] = count($position_grade_summary[$pos][$grade][$pid]['correct_ids']);
        $position_grade_summary[$pos][$grade][$pid]['total_sum'] += $score;
        $position_grade_summary[$pos][$grade][$pid]['answer_time_sum_ms'] += $time_stats['sum'];
        $position_grade_summary[$pos][$grade][$pid]['answer_time_count'] += $time_stats['count'];
        $position_grade_summary[$pos][$grade][$pid]['max_grade'] = $grade;
        if ($score > $position_grade_summary[$pos][$grade][$pid]['best_score']) $position_grade_summary[$pos][$grade][$pid]['best_score'] = $score;
        if ($ts > $position_grade_summary[$pos][$grade][$pid]['_latest_ts']) {
            $position_grade_summary[$pos][$grade][$pid]['_latest_ts'] = $ts;
            $position_grade_summary[$pos][$grade][$pid]['latest_played_at'] = $record['played_at'];
        }
    }

    $user_summary[$pid]['play_count']++;
    foreach ($correct_ids as $qid) { $user_summary[$pid]['correct_ids'][$qid] = true; }
    $user_summary[$pid]['correct_count'] = count($user_summary[$pid]['correct_ids']);
    $user_summary[$pid]['total_sum'] += $score;
    if (!isset($user_summary[$pid]['position_score_sums'][$pos])) $user_summary[$pid]['position_score_sums'][$pos] = 0;
    if (!isset($user_summary[$pid]['position_play_counts'][$pos])) $user_summary[$pid]['position_play_counts'][$pos] = 0;
    if (!isset($user_summary[$pid]['position_correct_counts'][$pos])) $user_summary[$pid]['position_correct_counts'][$pos] = 0;
    $user_summary[$pid]['position_score_sums'][$pos] += $score;
    $user_summary[$pid]['position_play_counts'][$pos]++;
    if (!isset($user_summary[$pid]['position_correct_ids'][$pos])) $user_summary[$pid]['position_correct_ids'][$pos] = [];
    foreach ($correct_ids as $qid) { $user_summary[$pid]['position_correct_ids'][$pos][$qid] = true; }
    $user_summary[$pid]['position_correct_counts'][$pos] = count($user_summary[$pid]['position_correct_ids'][$pos]);
    $user_summary[$pid]['answer_time_sum_ms'] += $time_stats['sum'];
    $user_summary[$pid]['answer_time_count'] += $time_stats['count'];
    if ($grade > ($user_summary[$pid]['max_grade'] ?? 0)) $user_summary[$pid]['max_grade'] = $grade;
    if ($score > $user_summary[$pid]['best_score']) $user_summary[$pid]['best_score'] = $score;
    if ($ts > $user_summary[$pid]['_latest_ts']) {
        $user_summary[$pid]['_latest_ts'] = $ts;
        $user_summary[$pid]['latest_played_at'] = $record['played_at'];
    }
}

$position_rankings = [];
foreach ($position_labels as $pos => $label) {
    $overall_rows = isset($position_summary[$pos]) ? finalize_summary_rows($position_summary[$pos], $grade_labels) : [];
    foreach ($overall_rows as $row) {
        if (isset($user_summary[$row['player_id']])) {
            $user_summary[$row['player_id']]['position_ranks'][$pos] = $row['rank'];
        }
    }

    $grade_rankings = [];
    $high_grade_rank_values = [];
    foreach ($grade_labels as $grade => $grade_label) {
        $grade_rows = (isset($position_grade_summary[$pos]) && isset($position_grade_summary[$pos][$grade]))
            ? finalize_summary_rows($position_grade_summary[$pos][$grade], $grade_labels)
            : [];
        foreach ($grade_rows as $gr) {
            if ($grade >= 5) $high_grade_rank_values[] = $gr['rank'];
        }
        foreach ($grade_rows as &$gr) unset($gr['_latest_ts']);
        unset($gr);
        $grade_rankings[(string)$grade] = [
            'grade' => $grade,
            'grade_label' => $grade_label,
            'ranking' => array_slice($grade_rows, 0, 50),
        ];
    }

    foreach ($overall_rows as &$orow) unset($orow['_latest_ts']);
    unset($orow);

    $high_grade_average_rank = count($high_grade_rank_values) ? round(array_sum($high_grade_rank_values) / count($high_grade_rank_values), 2) : null;

    $position_rankings[$pos] = [
        'position' => $pos,
        'position_label' => $label,
        'ranking' => array_slice($overall_rows, 0, 50),
        'grade_rankings' => $grade_rankings,
        'high_grade_average_rank' => $high_grade_average_rank,
        'high_grade_rank_count' => count($high_grade_rank_values),
    ];
}

$position_order = array_values($position_rankings);
usort($position_order, function($a, $b) {
    $ar = $a['high_grade_average_rank'];
    $br = $b['high_grade_average_rank'];
    if ($ar === null && $br === null) return 0;
    if ($ar === null) return 1;
    if ($br === null) return -1;
    if ($ar != $br) return $ar <=> $br;
    return $b['high_grade_rank_count'] <=> $a['high_grade_rank_count'];
});
$position_order_codes = array_map(function($x){ return $x['position']; }, $position_order);

$overall = [];
foreach ($user_summary as $pid => $row) {
    $ranks = array_values($row['position_ranks']);
    if (count($ranks) === 0) continue;
    $position_count = count($row['position_play_counts'] ?? []);
    if ($position_count <= 0) $position_count = count($ranks);

    $position_averages = [];
    foreach (($row['position_score_sums'] ?? []) as $pos_code => $sum_score) {
        $pc = intval($row['position_play_counts'][$pos_code] ?? 0);
        if ($pc > 0) $position_averages[] = $sum_score / $pc;
    }

    $position_average_score = count($position_averages) ? round(array_sum($position_averages) / count($position_averages), 2) : 0;
    $max_grade = intval($row['max_grade'] ?? 0);
    $correct_count = intval($row['correct_count'] ?? 0);

    $overall[] = [
        'player_id' => $pid,
        'play_count' => $row['play_count'],
        'correct_count' => $correct_count,
        'average_score' => round($row['total_sum'] / max(1, $row['play_count']), 1),
        'position_average_score' => $position_average_score,
        'overall_score' => $correct_count, // 旧UI互換。v683以降はクリア問題数として扱う。
        'position_bonus' => 0,
        'grade_bonus' => 0,
        'average_answer_seconds' => avg_answer_seconds($row['answer_time_sum_ms'] ?? 0, $row['answer_time_count'] ?? 0),
        'max_grade' => $max_grade,
        'max_grade_label' => isset($grade_labels[$max_grade]) ? $grade_labels[$max_grade] : '',
        'best_score' => $row['best_score'],
        'latest_played_at' => $row['latest_played_at'],
        'position_count' => $position_count,
        'average_rank' => count($ranks) ? round(array_sum($ranks) / max(1, count($ranks)), 2) : null,
        'position_ranks' => $row['position_ranks'],
        'ranking_logic' => 'correct_count',
        '_latest_ts' => $row['_latest_ts'],
    ];
}

usort($overall, function($a, $b) {
    // v683: 総合ランキングは「クリア問題数」を最優先。プレイ回数は順位判定に使わない。
    if (($a['correct_count'] ?? 0) !== ($b['correct_count'] ?? 0)) return ($b['correct_count'] ?? 0) <=> ($a['correct_count'] ?? 0);
    $ag = rank_grade_value($a);
    $bg = rank_grade_value($b);
    if ($ag !== $bg) return $bg <=> $ag;
    if (($a['average_score'] ?? 0) != ($b['average_score'] ?? 0)) return ($b['average_score'] ?? 0) <=> ($a['average_score'] ?? 0);
    $time_cmp = compare_answer_time($a, $b);
    if ($time_cmp !== 0) return $time_cmp;
    if (($a['best_score'] ?? 0) !== ($b['best_score'] ?? 0)) return ($b['best_score'] ?? 0) <=> ($a['best_score'] ?? 0);
    return ($b['_latest_ts'] ?? 0) <=> ($a['_latest_ts'] ?? 0);
});

$overall = array_slice($overall, 0, 50);
foreach ($overall as $i => &$row) {
    $row['rank'] = $i + 1;
    unset($row['_latest_ts'], $row['correct_ids']);
}
unset($row);

echo json_encode([
    'ok' => true,
    'viewer_id' => $viewer_id,
    'ranking' => $overall,
    'overall_ranking' => $overall,
    'position_rankings' => $position_rankings,
    'position_order' => $position_order_codes,
    'position_labels' => $position_labels,
    'grade_labels' => $grade_labels,
    'deleted_stale_users' => $deleted_stale_users,
    'deleted_stale_records' => $deleted_stale_records,
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
