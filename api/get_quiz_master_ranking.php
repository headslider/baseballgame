<?php
require_once __DIR__ . '/feature_common.php';
require_once __DIR__ . '/quiz_master_titles_common.php';
require_once __DIR__ . '/quiz_master_common.php';
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method not allowed'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

function quiz_master_read_request_payload() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return [];
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 1024 * 64) return [];
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

/**
 * スコア行を公開用に正規化する。
 * correct_count のフォールバックを 0 に統一（旧: reached_level が使われていたバグを修正）。
 */
function quiz_master_public_row($row) {
    $pid = normalize_player_id($row['player_id'] ?? '');
    if ($pid === '') return null;
    $score    = quiz_master_clamp_int($row['score']          ?? 0, 0, QUIZ_MASTER_MAX_SCORE);
    $level    = quiz_master_clamp_int($row['reached_level']  ?? 0, 0, QUIZ_MASTER_MAX_LEVEL);
    $answered = quiz_master_clamp_int($row['answered_count'] ?? $level, 0, QUIZ_MASTER_MAX_LEVEL);
    $correct  = quiz_master_clamp_int($row['correct_count']  ?? 0, 0, QUIZ_MASTER_MAX_LEVEL);
    return [
        'player_id'      => $pid,
        'score'          => $score,
        'reached_level'  => $level,
        'answered_count' => $answered,
        'correct_count'  => $correct,
        'cleared'        => !empty($row['cleared']),
        'challenge'      => !empty($row['challenge']),
        'result_reason'  => (string)($row['result_reason'] ?? ''),
        'duration_sec'   => quiz_master_clamp_int($row['duration_sec'] ?? 0, 0, 3600),
        'played_at'      => (string)($row['played_at'] ?? '')
    ];
}

$request              = quiz_master_read_request_payload();
$request_player_id    = normalize_player_id($request['player_id'] ?? ($_GET['player_id'] ?? ''));
$request_client_token = normalize_client_token($request['client_token'] ?? ($_GET['client_token'] ?? ''));
$include_private      = $request_player_id !== ''
    && $request_client_token !== ''
    && verify_player_client($request_player_id, $request_client_token)
    && !is_player_suspended($request_player_id);

$file = feature_scores_dir() . '/quiz_master_scores.json';
$db   = ['version'=>1,'scores'=>[],'totals'=>[]];
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

// scores 配列: ベスト1プレイ特定・直近履歴に使用（5000件上限あり）
$best      = [];
$my_recent = [];
foreach (($db['scores'] ?? []) as $raw_row) {
    if (!is_array($raw_row)) continue;
    $row = quiz_master_public_row($raw_row);
    if (!$row) continue;
    $pid = $row['player_id'];
    if (quiz_master_is_better_row($row, $best[$pid] ?? null)) $best[$pid] = $row;
    if ($include_private && $pid === $request_player_id) $my_recent[] = $row;
}

// totals セクションからランキング行を構築（累積スコアは scores キャップに左右されない）
// totals がなければ scores から集計するフォールバックで旧データとの互換を保つ
$rows = [];
if (!empty($db['totals']) && is_array($db['totals'])) {
    foreach ($db['totals'] as $pid => $tot) {
        $pid = normalize_player_id($pid);
        if ($pid === '') continue;
        $rows[] = [
            'player_id'          => $pid,
            'total_score'        => intval($tot['total_score']        ?? 0),
            'score'              => intval($tot['total_score']        ?? 0),
            'plays'              => intval($tot['plays']              ?? 0),
            'cleared_count'      => intval($tot['cleared_count']      ?? 0),
            'best_score'         => intval($tot['best_score']         ?? 0),
            'best_reached_level' => intval($tot['best_reached_level'] ?? 0),
            'latest_played_at'   => (string)($tot['latest_played_at'] ?? '')
        ];
    }
} else {
    // フォールバック: totals 未生成（save_quiz_master_score.php が1回も走っていない等）
    $totals_fb = [];
    foreach (($db['scores'] ?? []) as $raw_row) {
        if (!is_array($raw_row)) continue;
        $row = quiz_master_public_row($raw_row);
        if (!$row) continue;
        $pid = $row['player_id'];
        if (!isset($totals_fb[$pid])) {
            $totals_fb[$pid] = ['player_id'=>$pid,'total_score'=>0,'score'=>0,'plays'=>0,'cleared_count'=>0,'best_score'=>0,'best_reached_level'=>0,'latest_played_at'=>''];
        }
        $totals_fb[$pid]['total_score'] += $row['score'];
        $totals_fb[$pid]['score']        = $totals_fb[$pid]['total_score'];
        $totals_fb[$pid]['plays']++;
        if (!empty($row['cleared'])) $totals_fb[$pid]['cleared_count']++;
        if ($row['score'] > $totals_fb[$pid]['best_score']) {
            $totals_fb[$pid]['best_score']         = $row['score'];
            $totals_fb[$pid]['best_reached_level']  = $row['reached_level'];
        }
        if (($row['played_at'] ?? '') > $totals_fb[$pid]['latest_played_at']) {
            $totals_fb[$pid]['latest_played_at'] = $row['played_at'];
        }
    }
    $rows = array_values($totals_fb);
}

// サマリー統計は totals から集計（scores は5000件上限のため全体数が不正確になる可能性がある）
$total_plays      = 0;
$cleared_count    = 0;
$latest_played_at = '';
foreach ($rows as $r) {
    $total_plays   += intval($r['plays']         ?? 0);
    $cleared_count += intval($r['cleared_count'] ?? 0);
    if (($r['latest_played_at'] ?? '') > $latest_played_at) $latest_played_at = $r['latest_played_at'];
}

$best_rows = array_values($best);
usort($best_rows, 'quiz_master_compare_rows');

$title_rows = quiz_master_load_titles_payload()['titles'] ?? quiz_master_default_titles();
usort($rows, 'quiz_master_total_compare');
foreach ($rows as $i => &$row) {
    $row['rank']       = $i + 1;
    $row['title_info'] = quiz_master_title_for_score($row['total_score'] ?? 0, $title_rows);
}
unset($row);

usort($my_recent, function($a, $b) {
    return strcmp((string)($b['played_at'] ?? ''), (string)($a['played_at'] ?? ''));
});

$my_best  = null;
$my_total = null;
if ($include_private) {
    foreach ($best_rows as $row) {
        if (($row['player_id'] ?? '') === $request_player_id) { $my_best = $row; break; }
    }
    foreach ($rows as $row) {
        if (($row['player_id'] ?? '') === $request_player_id) { $my_total = $row; break; }
    }
}

echo json_encode([
    'ok'            => true,
    'ranking'       => array_slice($rows, 0, 5),
    'recent'        => [],
    'my_best'       => $my_best,
    'my_total'      => $my_total,
    'my_recent'     => array_slice($my_recent, 0, 10),
    'summary'       => [
        'total_players'    => count($rows),
        'total_plays'      => $total_plays,
        'cleared_count'    => $cleared_count,
        'latest_played_at' => $latest_played_at
    ],
    'titles'        => $title_rows,
    'total_players' => count($rows)
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
