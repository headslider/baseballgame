<?php
require_once __DIR__ . '/feature_common.php';
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method not allowed'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

function quiz_master_stat_question_id($value) {
    return substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$value), 0, 32);
}

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

$stats = [];
foreach (($db['scores'] ?? []) as $play) {
    if (!is_array($play)) continue;
    $summary = $play['answer_summary'] ?? [];
    if (!is_array($summary)) continue;
    foreach ($summary as $answer) {
        if (!is_array($answer)) continue;
        $id = quiz_master_stat_question_id($answer['id'] ?? '');
        if ($id === '') continue;
        if (!isset($stats[$id])) {
            $stats[$id] = [
                'attempts'=>0,
                'correct'=>0,
                'correct_rate'=>0
            ];
        }
        $stats[$id]['attempts']++;
        if (!empty($answer['correct'])) $stats[$id]['correct']++;
    }
}

foreach ($stats as $id=>&$row) {
    $attempts = max(0, intval($row['attempts'] ?? 0));
    $correct = max(0, intval($row['correct'] ?? 0));
    $row['attempts'] = $attempts;
    $row['correct'] = $correct;
    $row['correct_rate'] = $attempts > 0 ? intval(round(($correct / $attempts) * 100)) : 0;
}
unset($row);
ksort($stats);

echo json_encode([
    'ok'=>true,
    'stats'=>$stats,
    'generated_at'=>date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
