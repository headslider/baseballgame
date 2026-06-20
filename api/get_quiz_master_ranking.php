<?php
require_once __DIR__ . '/feature_common.php';
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method not allowed'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

function quiz_master_rank_int($value, $min, $max) {
    $n = intval($value);
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}

function quiz_master_read_request_payload() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return [];
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 1024 * 64) return [];
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function quiz_master_public_row($row) {
    $pid = normalize_player_id($row['player_id'] ?? '');
    if ($pid === '') return null;
    $score = quiz_master_rank_int($row['score'] ?? 0, 0, 1215);
    $level = quiz_master_rank_int($row['reached_level'] ?? 0, 0, 10);
    $answered = quiz_master_rank_int($row['answered_count'] ?? $level, 0, 10);
    $correct = quiz_master_rank_int($row['correct_count'] ?? $level, 0, 10);
    return [
        'player_id'=>$pid,
        'score'=>$score,
        'reached_level'=>$level,
        'answered_count'=>$answered,
        'correct_count'=>$correct,
        'cleared'=>!empty($row['cleared']),
        'challenge'=>!empty($row['challenge']),
        'result_reason'=>(string)($row['result_reason'] ?? ''),
        'duration_sec'=>quiz_master_rank_int($row['duration_sec'] ?? 0, 0, 3600),
        'played_at'=>(string)($row['played_at'] ?? '')
    ];
}

function quiz_master_is_better_row($a, $b) {
    if (!$b) return true;
    if (($a['score'] ?? 0) !== ($b['score'] ?? 0)) return ($a['score'] ?? 0) > ($b['score'] ?? 0);
    if (($a['reached_level'] ?? 0) !== ($b['reached_level'] ?? 0)) return ($a['reached_level'] ?? 0) > ($b['reached_level'] ?? 0);
    if (($a['cleared'] ?? false) !== ($b['cleared'] ?? false)) return !empty($a['cleared']);
    $ad = intval($a['duration_sec'] ?? 0);
    $bd = intval($b['duration_sec'] ?? 0);
    if ($ad > 0 && $bd > 0 && $ad !== $bd) return $ad < $bd;
    return strcmp((string)($a['played_at'] ?? ''), (string)($b['played_at'] ?? '')) > 0;
}

function quiz_master_rank_sort($a, $b) {
    if (($a['score'] ?? 0) !== ($b['score'] ?? 0)) return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    if (($a['reached_level'] ?? 0) !== ($b['reached_level'] ?? 0)) return ($b['reached_level'] ?? 0) <=> ($a['reached_level'] ?? 0);
    if (!empty($a['cleared']) !== !empty($b['cleared'])) return !empty($a['cleared']) ? -1 : 1;
    $ad = intval($a['duration_sec'] ?? 0);
    $bd = intval($b['duration_sec'] ?? 0);
    if ($ad > 0 && $bd > 0 && $ad !== $bd) return $ad <=> $bd;
    return strcmp((string)($b['played_at'] ?? ''), (string)($a['played_at'] ?? ''));
}

function quiz_master_total_rank_sort($a, $b) {
    if (($a['total_score'] ?? 0) !== ($b['total_score'] ?? 0)) return ($b['total_score'] ?? 0) <=> ($a['total_score'] ?? 0);
    if (($a['plays'] ?? 0) !== ($b['plays'] ?? 0)) return ($b['plays'] ?? 0) <=> ($a['plays'] ?? 0);
    if (($a['cleared_count'] ?? 0) !== ($b['cleared_count'] ?? 0)) return ($b['cleared_count'] ?? 0) <=> ($a['cleared_count'] ?? 0);
    return strcmp((string)($b['latest_played_at'] ?? ''), (string)($a['latest_played_at'] ?? ''));
}

$request = quiz_master_read_request_payload();
$request_player_id = normalize_player_id($request['player_id'] ?? ($_GET['player_id'] ?? ''));
$request_client_token = normalize_client_token($request['client_token'] ?? ($_GET['client_token'] ?? ''));
$include_private = $request_player_id !== ''
    && $request_client_token !== ''
    && verify_player_client($request_player_id, $request_client_token)
    && !is_player_suspended($request_player_id);

$file = feature_scores_dir() . '/quiz_master_scores.json';
$db = ['version'=>1,'scores'=>[]];
if (is_file($file)) {
    $fp = fopen($file, 'r');
    if ($fp && flock($fp, LOCK_SH)) {
        $raw = stream_get_contents($fp);
        $tmp = $raw ? json_decode($raw, true) : null;
        if (is_array($tmp)) $db = $tmp;
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);
}

$best = [];
$totals = [];
$recent = [];
$my_recent = [];
$total_plays = 0;
$cleared_count = 0;
$latest_played_at = '';

foreach (($db['scores'] ?? []) as $raw_row) {
    if (!is_array($raw_row)) continue;
    $row = quiz_master_public_row($raw_row);
    if (!$row) continue;
    $total_plays++;
    if (!empty($row['cleared'])) $cleared_count++;
    if (($row['played_at'] ?? '') > $latest_played_at) $latest_played_at = $row['played_at'];

    $pid = $row['player_id'];
    if (quiz_master_is_better_row($row, $best[$pid] ?? null)) $best[$pid] = $row;
    if (!isset($totals[$pid])) {
        $totals[$pid] = [
            'player_id'=>$pid,
            'total_score'=>0,
            'score'=>0,
            'plays'=>0,
            'cleared_count'=>0,
            'best_score'=>0,
            'best_reached_level'=>0,
            'latest_played_at'=>''
        ];
    }
    $totals[$pid]['total_score'] += $row['score'];
    $totals[$pid]['score'] = $totals[$pid]['total_score'];
    $totals[$pid]['plays']++;
    if (!empty($row['cleared'])) $totals[$pid]['cleared_count']++;
    if ($row['score'] > $totals[$pid]['best_score']) {
        $totals[$pid]['best_score'] = $row['score'];
        $totals[$pid]['best_reached_level'] = $row['reached_level'];
    }
    if (($row['played_at'] ?? '') > $totals[$pid]['latest_played_at']) $totals[$pid]['latest_played_at'] = $row['played_at'];
    $recent[] = $row;
    if ($include_private && $pid === $request_player_id) $my_recent[] = $row;
}

$best_rows = array_values($best);
usort($best_rows, 'quiz_master_rank_sort');

$rows = array_values($totals);
usort($rows, 'quiz_master_total_rank_sort');
foreach ($rows as $i=>&$row) $row['rank'] = $i + 1;
unset($row);

usort($recent, function($a, $b) {
    return strcmp((string)($b['played_at'] ?? ''), (string)($a['played_at'] ?? ''));
});
usort($my_recent, function($a, $b) {
    return strcmp((string)($b['played_at'] ?? ''), (string)($a['played_at'] ?? ''));
});

$my_best = null;
$my_total = null;
if ($include_private) {
    foreach ($best_rows as $row) {
        if (($row['player_id'] ?? '') === $request_player_id) {
            $my_best = $row;
            break;
        }
    }
    foreach ($rows as $row) {
        if (($row['player_id'] ?? '') === $request_player_id) {
            $my_total = $row;
            break;
        }
    }
}

echo json_encode([
    'ok'=>true,
    'ranking'=>array_slice($rows, 0, 5),
    'recent'=>[],
    'my_best'=>$my_best,
    'my_total'=>$my_total,
    'my_recent'=>array_slice($my_recent, 0, 10),
    'summary'=>[
        'total_players'=>count($rows),
        'total_plays'=>$total_plays,
        'cleared_count'=>$cleared_count,
        'latest_played_at'=>$latest_played_at
    ],
    'total_players'=>count($rows)
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
