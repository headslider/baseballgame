<?php
// 野球博士チャレンジ 共通定数・ヘルパー
// save_quiz_master_score.php / get_quiz_master_ranking.php から require_once して使う

if (!defined('QUIZ_MASTER_MAX_SCORE'))         define('QUIZ_MASTER_MAX_SCORE',         20262);
if (!defined('QUIZ_MASTER_MAX_LEVEL'))         define('QUIZ_MASTER_MAX_LEVEL',         20);
if (!defined('QUIZ_MASTER_SCORE_HISTORY_CAP')) define('QUIZ_MASTER_SCORE_HISTORY_CAP', 5000);

function quiz_master_clamp_int($value, $min, $max) {
    $n = intval($value);
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}

/**
 * 1プレイ同士の比較（usort コールバック）
 * 優先順位: スコア降順 → レベル降順 → クリア優先 → 短時間 → 新しい順
 */
function quiz_master_compare_rows($a, $b) {
    if (($a['score'] ?? 0) !== ($b['score'] ?? 0)) return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    if (($a['reached_level'] ?? 0) !== ($b['reached_level'] ?? 0)) return ($b['reached_level'] ?? 0) <=> ($a['reached_level'] ?? 0);
    if (!empty($a['cleared']) !== !empty($b['cleared'])) return !empty($a['cleared']) ? -1 : 1;
    $ad = intval($a['duration_sec'] ?? 0);
    $bd = intval($b['duration_sec'] ?? 0);
    if ($ad > 0 && $bd > 0 && $ad !== $bd) return $ad <=> $bd;
    return strcmp((string)($b['played_at'] ?? ''), (string)($a['played_at'] ?? ''));
}

/**
 * $a が $b より良い1プレイかどうかを返す。$b が null なら必ず true。
 */
function quiz_master_is_better_row($a, $b) {
    if (!$b) return true;
    return quiz_master_compare_rows($a, $b) < 0;
}

/**
 * 累積合計ランキングの比較（usort コールバック）
 * 優先順位: 合計スコア降順 → プレイ数降順 → クリア数降順 → 最終プレイ新しい順
 */
function quiz_master_total_compare($a, $b) {
    if (($a['total_score'] ?? 0) !== ($b['total_score'] ?? 0)) return ($b['total_score'] ?? 0) <=> ($a['total_score'] ?? 0);
    if (($a['plays'] ?? 0) !== ($b['plays'] ?? 0)) return ($b['plays'] ?? 0) <=> ($a['plays'] ?? 0);
    if (($a['cleared_count'] ?? 0) !== ($b['cleared_count'] ?? 0)) return ($b['cleared_count'] ?? 0) <=> ($a['cleared_count'] ?? 0);
    return strcmp((string)($b['latest_played_at'] ?? ''), (string)($a['latest_played_at'] ?? ''));
}
