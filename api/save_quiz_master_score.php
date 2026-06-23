<?php
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
require_once __DIR__ . '/feature_common.php';
require_once __DIR__ . '/quiz_master_titles_common.php';
require_once __DIR__ . '/quiz_master_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 1024 * 256) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$player_id    = normalize_player_id($data['player_id'] ?? '');
$client_token = normalize_client_token($data['client_token'] ?? '');
if ($player_id === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'player_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!verify_player_client($player_id, $client_token)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden','message'=>'プレイヤーIDの確認ができませんでした。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (is_player_suspended($player_id)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'player_suspended','message'=>'このプレイヤーIDは現在利用停止中です。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (player_has_feature($player_id, 'admin_mode')) {
    echo json_encode(['ok'=>false,'adminMode'=>true,'error'=>'admin score rejected'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}
if (!player_has_quiz_master_access($player_id)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'quiz_master_locked','message'=>'野球博士チャレンジはオプション機能です。招待IDまたは管理者IDで野球博士チャレンジ機能を解放してください。'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$score          = quiz_master_clamp_int($data['score']          ?? 0, 0, QUIZ_MASTER_MAX_SCORE);
$reached_level  = quiz_master_clamp_int($data['reached_level']  ?? 0, 0, QUIZ_MASTER_MAX_LEVEL);
$answered_count = quiz_master_clamp_int($data['answered_count'] ?? $reached_level, 0, QUIZ_MASTER_MAX_LEVEL);
$correct_count  = quiz_master_clamp_int($data['correct_count']  ?? 0, 0, QUIZ_MASTER_MAX_LEVEL);
$cleared        = !empty($data['cleared']);
$challenge      = !empty($data['challenge']);
$duration_sec   = quiz_master_clamp_int($data['duration_sec']   ?? 0, 0, 3600);
$result_reason  = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($data['result_reason'] ?? 'unknown')), 0, 32);

$question_ids = $data['question_ids'] ?? [];
if (!is_array($question_ids)) $question_ids = [];
$question_ids = array_values(array_slice(array_map(function($id) {
    return substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$id), 0, 32);
}, $question_ids), 0, QUIZ_MASTER_MAX_LEVEL));

$answer_summary = $data['answer_summary'] ?? [];
if (!is_array($answer_summary)) $answer_summary = [];
$answer_summary = array_values(array_filter(array_slice(array_map(function($row) {
    if (!is_array($row)) return null;
    return [
        'id'       => substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($row['id'] ?? '')), 0, 32),
        'level'    => quiz_master_clamp_int($row['level']    ?? 0,  0, QUIZ_MASTER_MAX_LEVEL),
        'selected' => quiz_master_clamp_int($row['selected'] ?? -1, -1, 2),
        'answer'   => quiz_master_clamp_int($row['answer']   ?? -1, -1, 2),
        'correct'  => !empty($row['correct'])
    ];
}, $answer_summary), 0, QUIZ_MASTER_MAX_LEVEL), function($row) {
    return is_array($row) && ($row['id'] ?? '') !== '';
}));

$file = feature_scores_dir() . '/quiz_master_scores.json';
$fp   = fopen($file, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'cannot open quiz score file'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'cannot lock quiz score file'], JSON_UNESCAPED_UNICODE);
    exit;
}

rewind($fp);
$existing = stream_get_contents($fp);
$db = $existing ? json_decode($existing, true) : null;
if (!is_array($db)) $db = ['version'=>1,'scores'=>[],'totals'=>[]];
if (!isset($db['scores']) || !is_array($db['scores'])) $db['scores'] = [];
if (!isset($db['totals']) || !is_array($db['totals'])) {
    // 初回または旧形式データ: scores 履歴から totals を一括構築（マイグレーション）
    $db['totals'] = [];
    foreach ($db['scores'] as $hist) {
        if (!is_array($hist)) continue;
        $pid = normalize_player_id($hist['player_id'] ?? '');
        if ($pid === '') continue;
        if (!isset($db['totals'][$pid])) {
            $db['totals'][$pid] = ['total_score'=>0,'plays'=>0,'cleared_count'=>0,'best_score'=>0,'best_reached_level'=>0,'latest_played_at'=>''];
        }
        $hs = quiz_master_clamp_int($hist['score'] ?? 0, 0, QUIZ_MASTER_MAX_SCORE);
        $hl = quiz_master_clamp_int($hist['reached_level'] ?? 0, 0, QUIZ_MASTER_MAX_LEVEL);
        $db['totals'][$pid]['total_score'] += $hs;
        $db['totals'][$pid]['plays']++;
        if (!empty($hist['cleared'])) $db['totals'][$pid]['cleared_count']++;
        if ($hs > $db['totals'][$pid]['best_score']) {
            $db['totals'][$pid]['best_score']         = $hs;
            $db['totals'][$pid]['best_reached_level']  = $hl;
        }
        if (($hist['played_at'] ?? '') > $db['totals'][$pid]['latest_played_at']) {
            $db['totals'][$pid]['latest_played_at'] = $hist['played_at'];
        }
    }
}

// total_before はスコア追記前の累積合計（旧: scores 全件走査 → totals から O(1) 参照に改善）
$total_before = intval($db['totals'][$player_id]['total_score'] ?? 0);

$played_at = date('Y-m-d H:i:s');
$db['scores'][] = [
    'played_at'      => $played_at,
    'player_id'      => $player_id,
    'score'          => $score,
    'reached_level'  => $reached_level,
    'answered_count' => $answered_count,
    'correct_count'  => $correct_count,
    'cleared'        => $cleared,
    'challenge'      => $challenge,
    'result_reason'  => $result_reason,
    'duration_sec'   => $duration_sec,
    'question_ids'   => $question_ids,
    'answer_summary' => $answer_summary
];
if (count($db['scores']) > QUIZ_MASTER_SCORE_HISTORY_CAP) {
    $db['scores'] = array_slice($db['scores'], -QUIZ_MASTER_SCORE_HISTORY_CAP);
}

// 累積合計を増分更新（scores キャップに影響されず全プレイ分を保持する）
if (!isset($db['totals'][$player_id])) {
    $db['totals'][$player_id] = ['total_score'=>0,'plays'=>0,'cleared_count'=>0,'best_score'=>0,'best_reached_level'=>0,'latest_played_at'=>''];
}
$db['totals'][$player_id]['total_score'] += $score;
$db['totals'][$player_id]['plays']++;
if ($cleared) $db['totals'][$player_id]['cleared_count']++;
if ($score > $db['totals'][$player_id]['best_score']) {
    $db['totals'][$player_id]['best_score']         = $score;
    $db['totals'][$player_id]['best_reached_level']  = $reached_level;
}
if ($played_at > ($db['totals'][$player_id]['latest_played_at'] ?? '')) {
    $db['totals'][$player_id]['latest_played_at'] = $played_at;
}

$total_after = $db['totals'][$player_id]['total_score'];

rewind($fp);
ftruncate($fp, 0);
$ok = fwrite($fp, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG)) !== false;
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'cannot write quiz score file'], JSON_UNESCAPED_UNICODE);
    exit;
}

$titles = quiz_master_load_titles_payload()['titles'] ?? quiz_master_default_titles();
echo json_encode([
    'ok'           => true,
    'total_before' => $total_before,
    'total_after'  => $total_after,
    'title_before' => quiz_master_title_for_score($total_before, $titles),
    'title_after'  => quiz_master_title_for_score($total_after,  $titles),
    'titles'       => $titles
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
