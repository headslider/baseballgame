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

function logs_correct_count_scores($logs_json, $fallback_score = 0) {
    $logs = json_decode($logs_json ?? '[]', true);
    if (is_array($logs) && count($logs) > 0) {
        $count = 0;
        foreach ($logs as $log) {
            if (is_array($log) && intval($log['score'] ?? 0) === 3) $count++;
        }
        return $count;
    }
    return max(0, intval(floor(intval($fallback_score ?? 0) / 3)));
}

$player_id = normalize_player_id($data['player_id'] ?? '');
if ($player_id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'player_id required'], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
    exit;
}

$file = __DIR__ . '/../scores/score_log.csv';
$records = [];

if (is_file($file) && is_readable($file)) {
    $fp = fopen($file, 'r');
    if ($fp) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if (!is_array($row) || count($row) < 8) continue;
            $record = [
                'played_at' => $row[0] ?? '',
                'player_id' => $row[1] ?? '',
                'grade' => to_int_safe($row[2] ?? 0),
                'position' => $row[3] ?? '',
                'total_score' => to_int_safe($row[4] ?? 0),
                'attack_score' => to_int_safe($row[5] ?? 0),
                'defense_score' => to_int_safe($row[6] ?? 0),
                'max_score' => to_int_safe($row[7] ?? 54),
            ];
            $logs_json = $row[8] ?? '[]';
            $record['correct_count'] = logs_correct_count_scores($logs_json, $record['total_score']);
            if ($record['player_id'] === $player_id) {
                $records[] = $record;
            }
        }
        fclose($fp);
    }
}

usort($records, function($a, $b) {
    return strcmp($b['played_at'], $a['played_at']);
});

$count = count($records);
$best = 0;
$total = 0;
$correct_total = 0;
foreach ($records as $record) {
    $score = to_int_safe($record['total_score']);
    if ($score > $best) $best = $score;
    $total += $score;
    $correct_total += to_int_safe($record['correct_count'] ?? 0);
}
$average = $count > 0 ? round($total / $count, 1) : 0;
$latest = $count > 0 ? ($records[0]['played_at'] ?? '') : '';

$records = array_slice($records, 0, 50);

echo json_encode([
    'ok' => true,
    'player_id' => $player_id,
    'summary' => [
        'play_count' => $count,
        'correct_count' => $correct_total,
        'best_score' => $best,
        'average_score' => $average,
        'latest_played_at' => $latest,
    ],
    'records' => $records,
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
