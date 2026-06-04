<?php
require_once __DIR__ . '/feature_common.php';
header('Content-Type: application/json; charset=utf-8');
$JSON_INVALID_UTF8_SUBSTITUTE_FLAG = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 1024 * 64) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid json'], JSON_UNESCAPED_UNICODE);
    exit;
}

function to_int_safe($value) {
    return intval($value ?? 0);
}

$player_id = normalize_player_id($data['player_id'] ?? '');
if ($player_id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'player_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$positions = ['P','C','1B','2B','SS','3B','LF','CF','RF'];
$progress = [];
foreach ($positions as $pos) {
    $progress[$pos] = [
        'completed_grades' => [],
        'max_unlocked_grade' => 3,
    ];
}

$file = __DIR__ . '/../scores/score_log.csv';
if (is_file($file)) {
    $fp = fopen($file, 'r');
    if (!$fp) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'cannot open score file'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'cannot lock score file'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $header = fgetcsv($fp);
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < 8) continue;
        $pid = $row[1] ?? '';
        if ($pid !== $player_id) continue;

        $grade = to_int_safe($row[2] ?? 0);
        $position = $row[3] ?? '';
        $total_score = to_int_safe($row[4] ?? 0);
        if (!in_array($position, $positions, true)) continue;
        if ($grade < 3 || $grade > 6) continue;
        if ($total_score < 40) continue;

        $progress[$position]['completed_grades'][(string)$grade] = true;
    }

    flock($fp, LOCK_UN);
    fclose($fp);
}

foreach ($positions as $pos) {
    $completed = array_map('intval', array_keys($progress[$pos]['completed_grades']));
    sort($completed);
    $max_completed = count($completed) ? max($completed) : 0;
    $progress[$pos]['completed_grades'] = $completed;
    $progress[$pos]['max_unlocked_grade'] = min(6, max(3, $max_completed + 1));
}

echo json_encode([
    'ok' => true,
    'player_id' => $player_id,
    'progress' => $progress,
], JSON_UNESCAPED_UNICODE | $JSON_INVALID_UTF8_SUBSTITUTE_FLAG);
?>
