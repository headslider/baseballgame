<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }
// CORESERVER CRON用：週間ランキング通知 毎時実行ランナー
// CORESERVER側では、このファイルだけを毎時実行する想定です。
// 管理画面で保存した集計曜日/時刻・配信曜日/時刻をPHP側で判定します。

require_once __DIR__ . '/api/weekly_ranking_notice.php';
require_once __DIR__ . '/api/scheduled_notifications.php';

function runner_log_line($message) {
    $dir = feature_scores_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    // PHP側でログファイルへ1回だけ書き込む。
    // echoしないことで、.sh側のリダイレクトによる同一ログの二重追記を防ぐ。
    file_put_contents($dir . '/cron_weekly_ranking_runner.log', $line, FILE_APPEND | LOCK_EX);
}

function runner_day_index($day) {
    $map = [
        'sunday' => 0,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
    ];
    return array_key_exists($day, $map) ? $map[$day] : 1;
}

function runner_parse_time($time, $fallback) {
    if (!preg_match('/^(\d{2}):(\d{2})$/', (string)$time, $m)) $time = $fallback;
    if (!preg_match('/^(\d{2}):(\d{2})$/', (string)$time, $m)) return [0, 0];
    $h = max(0, min(23, intval($m[1])));
    $min = max(0, min(59, intval($m[2])));
    return [$h, $min];
}

function runner_scheduled_timestamp_this_week($day, $time, $type, $now_ts) {
    [$hour, $minute] = runner_parse_time($time, $type === 'aggregate' ? '00:00' : '18:00');
    $day_index = runner_day_index($day);

    // 仕様上「日曜日24:00」は、管理画面では sunday + 00:00 として保存していても、
    // CRON判定では「月曜日00:00」として扱います。
    if ($type === 'aggregate' && $day === 'sunday' && $hour === 0 && $minute === 0) {
        $day_index = 1; // Monday
    }

    // PHPの strtotime('sunday this week') は環境や曜日により「次の日曜」を返すことがあるため、
    // 現在日の00:00から曜日番号分を引いて「今週の日曜日00:00」を確実に作る。
    $today_midnight = strtotime(date('Y-m-d 00:00:00', $now_ts));
    if ($today_midnight === false) $today_midnight = $now_ts;
    $current_day_index = intval(date('w', $now_ts)); // 0=Sunday, 1=Monday, ... 6=Saturday
    $week_start = $today_midnight - ($current_day_index * 86400);

    return $week_start + ($day_index * 86400) + ($hour * 3600) + ($minute * 60);
}

function runner_state_file() {
    return feature_scores_dir() . '/cron_weekly_ranking_runner_state.json';
}

function runner_read_state() {
    $file = runner_state_file();
    if (!is_file($file)) return [];
    $fp = fopen($file, 'r');
    if (!$fp) return [];
    $data = null;
    if (flock($fp, LOCK_SH)) {
        $raw  = stream_get_contents($fp);
        $data = $raw ? json_decode($raw, true) : null;
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return is_array($data) ? $data : [];
}

function runner_save_state($state) {
    file_put_contents(runner_state_file(), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)), LOCK_EX);
}

function runner_should_run($type, $scheduled_ts, $now_ts, $state) {
    // CORESERVER v1のcron最短間隔が1時間のため、設定時刻から60分以内なら実行対象にします。
    // 例：18:30設定で毎時0分CRONの場合、19:00実行時に拾えるようにする。
    $window = 3600;
    if ($now_ts < $scheduled_ts || $now_ts >= ($scheduled_ts + $window)) return false;

    $key = $type . '_' . date('o-\WW', $scheduled_ts) . '_' . date('YmdHi', $scheduled_ts);
    if (($state[$type]['last_key'] ?? '') === $key) return false;

    return $key;
}

$settings = read_push_notification_settings();
$ranking = $settings['ranking_notice'] ?? [];
$now_ts = time();
$state = runner_read_state();
$ran = false;
$log_parts = [];

if (empty($ranking['enabled'])) {
    $log_parts[] = 'ranking notice disabled';
} else {
    $aggregate_ts = runner_scheduled_timestamp_this_week($ranking['aggregate_day'] ?? 'sunday', $ranking['aggregate_time'] ?? '00:00', 'aggregate', $now_ts);
    $delivery_ts = runner_scheduled_timestamp_this_week($ranking['delivery_day'] ?? 'monday', $ranking['delivery_time'] ?? '18:00', 'delivery', $now_ts);

    $aggregate_key = runner_should_run('aggregate', $aggregate_ts, $now_ts, $state);
    if ($aggregate_key !== false) {
        $snapshot = aggregate_weekly_ranking_notice(false);
        $state['aggregate'] = [
            'last_key' => $aggregate_key,
            'last_run_at' => date('Y-m-d H:i:s'),
            'week_key' => $snapshot['week_key'] ?? '',
            'changed' => !empty($snapshot['changed']),
            'changed_ranks' => $snapshot['changed_ranks'] ?? []
        ];
        runner_log_line('aggregate executed key=' . $aggregate_key . ' week=' . ($snapshot['week_key'] ?? '') . ' changed=' . (!empty($snapshot['changed']) ? '1' : '0'));
        $ran = true;
    }

    $delivery_key = runner_should_run('delivery', $delivery_ts, $now_ts, $state);
    if ($delivery_key !== false) {
        $result = send_weekly_ranking_notice(false);
        $state['delivery'] = [
            'last_key' => $delivery_key,
            'last_run_at' => date('Y-m-d H:i:s'),
            'sent' => $result['sent'] ?? 0,
            'failed' => $result['failed'] ?? 0,
            'message' => $result['message'] ?? ''
        ];
        runner_log_line('delivery executed key=' . $delivery_key . ' sent=' . ($result['sent'] ?? 0) . ' failed=' . ($result['failed'] ?? 0) . ' message=' . ($result['message'] ?? ''));
        $ran = true;
    }

    $log_parts[] = 'aggregate=' . date('Y-m-d H:i', $aggregate_ts) . ' delivery=' . date('Y-m-d H:i', $delivery_ts);
}

$scheduled = scheduled_notifications_run_due();
if (($scheduled['executed_count'] ?? 0) > 0) {
    runner_log_line('scheduled notices executed count=' . ($scheduled['executed_count'] ?? 0));
    foreach (($scheduled['executed'] ?? []) as $item) {
        runner_log_line('scheduled notice id=' . ($item['id'] ?? '') . ' status=' . ($item['status'] ?? '') . ' sent=' . ($item['sent'] ?? 0) . ' failed=' . ($item['failed'] ?? 0) . ' message=' . ($item['message'] ?? ''));
    }
    $ran = true;
}

runner_save_state($state);

if (!$ran) {
    runner_log_line('no action. ' . implode(' / ', $log_parts));
}
?>
