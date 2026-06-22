<?php
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
require_once __DIR__ . '/feature_common.php';
require_once __DIR__ . '/quiz_master_titles_common.php';
const QUIZ_MASTER_MAX_SCORE = 20262;
const QUIZ_MASTER_MAX_LEVEL = 20;

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

function quiz_master_clamp_int($value, $min, $max) {
    $n = intval($value);
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}

$player_id = normalize_player_id($data['player_id'] ?? '');
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

$score = quiz_master_clamp_int($data['score'] ?? 0, 0, QUIZ_MASTER_MAX_SCORE);
$reached_level = quiz_master_clamp_int($data['reached_level'] ?? 0, 0, QUIZ_MASTER_MAX_LEVEL);
$answered_count = quiz_master_clamp_int($data['answered_count'] ?? $reached_level, 0, QUIZ_MASTER_MAX_LEVEL);
$correct_count = quiz_master_clamp_int($data['correct_count'] ?? 0, 0, QUIZ_MASTER_MAX_LEVEL);
$cleared = !empty($data['cleared']);
$challenge = !empty($data['challenge']);
$duration_sec = quiz_master_clamp_int($data['duration_sec'] ?? 0, 0, 3600);
$result_reason = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($data['result_reason'] ?? 'unknown')), 0, 32);
$question_ids = $data['question_ids'] ?? [];
if (!is_array($question_ids)) $question_ids = [];
$question_ids = array_values(array_slice(array_map(function($id) {
    return substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$id), 0, 32);
}, $question_ids), 0, QUIZ_MASTER_MAX_LEVEL));
$answer_summary = $data['answer_summary'] ?? [];
if (!is_array($answer_summary)) $answer_summary = [];
$answer_summary = array_values(array_slice(array_map(function($row) {
    if (!is_array($row)) return null;
    return [
        'id'=>substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($row['id'] ?? '')), 0, 32),
        'level'=>quiz_master_clamp_int($row['level'] ?? 0, 0, QUIZ_MASTER_MAX_LEVEL),
        'selected'=>quiz_master_clamp_int($row['selected'] ?? -1, -1, 2),
        'answer'=>quiz_master_clamp_int($row['answer'] ?? -1, -1, 2),
        'correct'=>!empty($row['correct'])
    ];
}, $answer_summary), 0, QUIZ_MASTER_MAX_LEVEL));
$answer_summary = array_values(array_filter($answer_summary, function($row) {
    return is_array($row) && ($row['id'] ?? '') !== '';
}));

$file = feature_scores_dir() . '/quiz_master_scores.json';
$fp = fopen($file, 'c+');
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
if (!is_array($db)) $db = ['version'=>1,'scores'=>[]];
if (!isset($db['scores']) || !is_array($db['scores'])) $db['scores'] = [];

$total_before = 0;
foreach ($db['scores'] as $row) {
    if (is_array($row) && normalize_player_id($row['player_id'] ?? '') === $player_id) {
        $total_before += quiz_master_clamp_int($row['score'] ?? 0, 0, QUIZ_MASTER_MAX_SCORE);
    }
}

$db['scores'][] = [
    'played_at'=>date('Y-m-d H:i:s'),
    'player_id'=>$player_id,
    'score'=>$score,
    'reached_level'=>$reached_level,
    'answered_count'=>$answered_count,
    'correct_count'=>$correct_count,
    'cleared'=>$cleared,
    'challenge'=>$challenge,
    'result_reason'=>$result_reason,
    'duration_sec'=>$duration_sec,
    'question_ids'=>$question_ids,
    'answer_summary'=>$answer_summary
];
if (count($db['scores']) > 5000) $db['scores'] = array_slice($db['scores'], -5000);

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

$total_after = $total_before + $score;
$titles = quiz_master_load_titles_payload()['titles'] ?? quiz_master_default_titles();
echo json_encode([
    'ok'=>true,
    'total_before'=>$total_before,
    'total_after'=>$total_after,
    'title_before'=>quiz_master_title_for_score($total_before, $titles),
    'title_after'=>quiz_master_title_for_score($total_after, $titles),
    'titles'=>$titles
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
